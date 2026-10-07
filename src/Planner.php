<?php

declare(strict_types=1);

namespace Ramir\Planner;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramir\Planner\Enums\PlannedTaskStatus;
use Ramir\Planner\Jobs\RunPlannedTaskJob;
use Ramir\Planner\Models\PlannedTask;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Планировщик одноразового отложенного запуска artisan-команд с хранением в БД.
 */
class Planner
{
    /**
     * Запланировать запуск artisan-команды на время $runAt.
     *
     * $command — имя команды или строка с аргументами ("anonce:bump 15"); аргументы строкой
     * используются, только если $parameters пуст. Существование команды не проверяется
     * (это загрузило бы все команды приложения) — неизвестная команда завершится ошибкой при запуске.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function schedule(
        string $command,
        DateTimeInterface $runAt,
        array $parameters = [],
        ?string $group = null,
        ?Model $source = null,
    ): PlannedTask {
        return PlannedTask::query()->create([
            'command' => trim($command),
            'parameters' => $parameters ?: null,
            'run_at' => $runAt,
            'group' => $group,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'status' => PlannedTaskStatus::PENDING,
        ]);
    }

    /**
     * Запланировать несколько команд в одной транзакции.
     *
     * @param  iterable<array{command: string, run_at: DateTimeInterface, parameters?: array<string, mixed>, group?: string|null, source?: Model|null}>  $items
     * @return Collection<int, PlannedTask>
     */
    public function scheduleMany(iterable $items): Collection
    {
        return DB::connection((new PlannedTask)->getConnectionName())->transaction(function () use ($items) {
            $created = new Collection;

            foreach ($items as $item) {
                $created->push($this->schedule(
                    $item['command'],
                    $item['run_at'],
                    $item['parameters'] ?? [],
                    $item['group'] ?? null,
                    $item['source'] ?? null,
                ));
            }

            return $created;
        });
    }

    /**
     * Отменить все ожидающие задачи группы.
     *
     * @return int количество отменённых задач
     */
    public function cancelGroup(string $group, ?string $reason = null): int
    {
        return PlannedTask::query()
            ->inGroup($group)
            ->where('status', PlannedTaskStatus::PENDING)
            ->update(['status' => PlannedTaskStatus::CANCELED, 'message' => $reason, 'executed_at' => now()]);
    }

    /**
     * Ожидающие и выполняющиеся задачи группы по времени запуска.
     *
     * @return Collection<int, PlannedTask>
     */
    public function pending(string $group): Collection
    {
        return PlannedTask::query()->inGroup($group)->active()->orderBy('run_at')->get();
    }

    /**
     * Время запуска последней ожидающей задачи группы.
     */
    public function lastRunAt(string $group): ?Carbon
    {
        $value = PlannedTask::query()->inGroup($group)->active()->max('run_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Зарегистрирована ли artisan-команда (по первому слову строки "name --option=...").
     */
    public function commandExists(string $command): bool
    {
        $name = (string) strtok(trim($command), ' ');

        return $name !== '' && array_key_exists($name, Artisan::all());
    }

    /**
     * Нескрытые artisan-команды приложения: имя => описание, по имени.
     *
     * @return array<string, string>
     */
    public function commands(): array
    {
        $commands = [];

        foreach (Artisan::all() as $name => $command) {
            if (! $command->isHidden()) {
                $commands[$name] = $command->getDescription();
            }
        }

        ksort($commands);

        return $commands;
    }

    /**
     * Захватить созревшие задачи и отправить их в очередь.
     *
     * @return int количество отправленных задач
     */
    public function dispatchDue(?int $limit = null): int
    {
        $attempts = PlannedTask::query()
            ->due()
            ->orderBy('run_at')
            ->limit($limit ?? (int) config('planner.batch', 500))
            ->pluck('attempts', 'id');

        $dispatched = 0;

        foreach ($attempts as $id => $previous) {
            if (! $this->claim($id)) {
                continue;
            }

            try {
                Bus::dispatch(
                    (new RunPlannedTaskJob($id, $previous + 1))
                        ->onConnection(config('planner.connection'))
                        ->onQueue(config('planner.queue'))
                );
            } catch (Throwable $e) {
                // очередь недоступна: вернуть задачу в ожидание, не расходуя попытку
                report($e);
                $this->unclaim($id);

                continue;
            }

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Вернуть в ожидание задачи, зависшие в статусе running (например, упал воркер).
     *
     * @return int количество возвращённых задач
     */
    public function releaseStuck(): int
    {
        $minutes = config('planner.stuck_after');

        if ($minutes === null) {
            return 0;
        }

        return PlannedTask::query()
            ->where('status', PlannedTaskStatus::RUNNING)
            ->where('started_at', '<', now()->subMinutes((int) $minutes))
            ->update(['status' => PlannedTaskStatus::PENDING, 'started_at' => null]);
    }

    /**
     * Выполнить захваченную задачу (вызывается из RunPlannedTaskJob).
     *
     * $attempt — номер попытки, для которой был отправлен job: устаревший job (задачу вернул
     * releaseStuck() и захватили снова) не выполняется повторно.
     *
     * Код выхода 0 — done; любой другой код или исключение — ошибка с повтором. Вывод команды — в message.
     * Решения вроде «неактуально» или «отменить группу» — забота самой команды, не планировщика.
     */
    public function run(int $id, ?int $attempt = null): void
    {
        $task = PlannedTask::query()->find($id);

        if (! $task || $task->status !== PlannedTaskStatus::RUNNING) {
            return;
        }

        if ($attempt !== null && $task->attempts !== $attempt) {
            return;
        }

        try {
            $output = new BufferedOutput;
            $exitCode = Artisan::call($task->command, $task->parameters ?? [], $output);
            $text = trim($output->fetch());

            if ($exitCode !== 0) {
                throw new RuntimeException("Command [{$task->command}] exited with code {$exitCode}: {$text}");
            }
        } catch (Throwable $e) {
            $this->fail($task, $e);

            return;
        }

        $task->status = PlannedTaskStatus::DONE;
        $task->message = $text === '' ? null : Str::limit($text, 1000);
        $task->executed_at = now();
        $task->save();
    }

    /**
     * Атомарно перевести задачу pending → running (защита от двойного запуска).
     */
    protected function claim(int $id): bool
    {
        return PlannedTask::query()
            ->whereKey($id)
            ->where('status', PlannedTaskStatus::PENDING)
            ->increment('attempts', 1, ['status' => PlannedTaskStatus::RUNNING, 'started_at' => now()]) === 1;
    }

    /**
     * Откатить захват задачи, которую не удалось отправить в очередь.
     */
    protected function unclaim(int $id): void
    {
        PlannedTask::query()
            ->whereKey($id)
            ->where('status', PlannedTaskStatus::RUNNING)
            ->decrement('attempts', 1, ['status' => PlannedTaskStatus::PENDING, 'started_at' => null]);
    }

    /**
     * Ошибка выполнения: повтор через planner.backoff или failed после planner.max_attempts.
     */
    protected function fail(PlannedTask $task, Throwable $e): void
    {
        report($e);

        $task->message = Str::limit($e->getMessage(), 1000);

        if ($task->attempts >= (int) config('planner.max_attempts', 3)) {
            $task->status = PlannedTaskStatus::FAILED;
            $task->executed_at = now();
        } else {
            $task->status = PlannedTaskStatus::PENDING;
            $task->started_at = null;
            $task->run_at = now()->addSeconds((int) config('planner.backoff', 300));
        }

        $task->save();
    }
}
