<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Ramir\Planner\Enums\PlannedTaskStatus;
use Ramir\Planner\Facades\Planner as PlannerFacade;
use Ramir\Planner\Jobs\RunPlannedTaskJob;
use Ramir\Planner\Models\PlannedTask;
use Ramir\Planner\Planner;

beforeEach(function () {
    defineTestCommands();
    $this->planner = app(Planner::class);
});

// Задачи выполняются через dispatchDue() (очередь sync), а не $this->artisan('planner:run'):
// в тестах $this->artisan() подменяет вывод вложенных команд моком, и message не заполнится.

it('schedules a pending command', function () {
    $source = PlannedTask::query()->create(['command' => 'test:ok', 'run_at' => now()]);

    $task = $this->planner->schedule(' test:ok ', now()->addHour(), ['--x' => 1], group: 'g1', source: $source)->fresh();

    expect($task->status)->toBe(PlannedTaskStatus::PENDING)
        ->and($task->command)->toBe('test:ok')
        ->and($task->parameters)->toBe(['--x' => 1])
        ->and($task->group)->toBe('g1')
        ->and($task->source->is($source))->toBeTrue();
});

it('stores empty parameters as null', function () {
    expect($this->planner->schedule('test:ok', now())->fresh()->parameters)->toBeNull();
});

it('schedules many commands and reports the last run time of a group', function () {
    $last = now()->addDays(2)->startOfSecond();

    $created = $this->planner->scheduleMany([
        ['command' => 'test:ok', 'run_at' => now()->addDay(), 'group' => 'g1'],
        ['command' => 'test:ok', 'run_at' => $last, 'group' => 'g1', 'parameters' => ['--x' => 2]],
    ]);

    expect($created)->toHaveCount(2)
        ->and($this->planner->pending('g1'))->toHaveCount(2)
        ->and($this->planner->lastRunAt('g1')->equalTo($last))->toBeTrue()
        ->and($this->planner->lastRunAt('empty'))->toBeNull();
});

it('rolls back scheduleMany on error', function () {
    expect(fn () => $this->planner->scheduleMany([
        ['command' => 'test:ok', 'run_at' => now()->addDay()],
        ['command' => 'test:ok'],
    ]))->toThrow(ErrorException::class);

    expect(PlannedTask::query()->count())->toBe(0);
});

it('runs a due command and stores its output', function () {
    $task = $this->planner->schedule('test:ok', now()->subMinute(), ['--x' => 7]);
    $future = $this->planner->schedule('test:ok', now()->addHour());

    expect($this->planner->dispatchDue())->toBe(1);

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::DONE)
        ->and($task->fresh()->message)->toBe('x=7')
        ->and($task->fresh()->executed_at)->not->toBeNull()
        ->and($future->fresh()->status)->toBe(PlannedTaskStatus::PENDING);
});

it('runs a command given as a string with arguments', function () {
    $task = $this->planner->schedule('test:ok --x=5', now()->subMinute());

    $this->planner->dispatchDue();

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::DONE)
        ->and($task->fresh()->message)->toBe('x=5');
});

it('retries a failing command and marks it failed after max attempts', function () {
    config(['planner.max_attempts' => 2, 'planner.backoff' => 0]);
    $task = $this->planner->schedule('test:fail', now()->subMinute());

    $this->planner->dispatchDue();

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::PENDING)
        ->and($task->fresh()->message)->toBe('Command [test:fail] exited with code 1: broken');

    $this->planner->dispatchDue();

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::FAILED)
        ->and($task->fresh()->attempts)->toBe(2);
});

it('fails an unknown command', function () {
    config(['planner.max_attempts' => 1]);
    $task = $this->planner->schedule('no:such-command', now()->subMinute());

    $this->planner->dispatchDue();

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::FAILED)
        ->and($task->fresh()->message)->toContain('no:such-command');
});

it('postpones a failed attempt by the backoff', function () {
    config(['planner.backoff' => 600]);
    $task = $this->planner->schedule('test:fail', now()->subMinute());

    $this->planner->dispatchDue();

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::PENDING)
        ->and($task->fresh()->run_at->isAfter(now()->addSeconds(590)))->toBeTrue()
        ->and($task->fresh()->started_at)->toBeNull();
});

it('dispatches only due tasks and never claims a task twice', function () {
    Bus::fake();
    $due = $this->planner->schedule('test:ok', now()->subMinute());
    $this->planner->schedule('test:ok', now()->addHour());

    expect($this->planner->dispatchDue())->toBe(1)
        ->and($this->planner->dispatchDue())->toBe(0);

    Bus::assertDispatchedTimes(RunPlannedTaskJob::class, 1);
    expect($due->fresh()->status)->toBe(PlannedTaskStatus::RUNNING)
        ->and($due->fresh()->attempts)->toBe(1);
});

it('respects the dispatch limit and queue settings', function () {
    Bus::fake();
    config(['planner.connection' => 'database', 'planner.queue' => 'planner']);
    $this->planner->schedule('test:ok', now()->subMinutes(3));
    $this->planner->schedule('test:ok', now()->subMinutes(2));
    $this->planner->schedule('test:ok', now()->subMinute());

    expect($this->planner->dispatchDue(2))->toBe(2);

    Bus::assertDispatchedTimes(RunPlannedTaskJob::class, 2);
    Bus::assertDispatched(RunPlannedTaskJob::class, fn (RunPlannedTaskJob $job) => $job->connection === 'database'
        && $job->queue === 'planner'
        && $job->attempt === 1);

    $this->artisan('planner:run', ['--limit' => 1])->assertSuccessful();

    Bus::assertDispatchedTimes(RunPlannedTaskJob::class, 3);
});

it('returns the task to pending when the queue is unavailable', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    $task = $this->planner->schedule('test:ok', now()->subMinute());

    expect($this->planner->dispatchDue())->toBe(0)
        ->and($task->fresh()->status)->toBe(PlannedTaskStatus::PENDING)
        ->and($task->fresh()->attempts)->toBe(0)
        ->and($task->fresh()->started_at)->toBeNull();
});

it('does not run a task that is missing or not claimed', function () {
    $task = $this->planner->schedule('test:ok', now()->subMinute());

    $this->planner->run($task->id);
    $this->planner->run(999);

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::PENDING)
        ->and($task->fresh()->message)->toBeNull();
});

it('does not run a stale job of a released and reclaimed task', function () {
    $task = $this->planner->schedule('test:ok', now()->subMinute());
    $task->update(['status' => PlannedTaskStatus::RUNNING, 'attempts' => 2, 'started_at' => now()]);

    (new RunPlannedTaskJob($task->id, 1))->handle($this->planner);

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::RUNNING);

    (new RunPlannedTaskJob($task->id, 2))->handle($this->planner);

    expect($task->fresh()->status)->toBe(PlannedTaskStatus::DONE);
});

it('releases tasks stuck in running', function () {
    $task = $this->planner->schedule('test:ok', now()->subHours(3));
    $task->update(['status' => PlannedTaskStatus::RUNNING, 'started_at' => now()->subHours(2)]);

    expect($this->planner->releaseStuck())->toBe(1)
        ->and($task->fresh()->status)->toBe(PlannedTaskStatus::PENDING);
});

it('keeps fresh running tasks and can disable stuck release', function () {
    $task = $this->planner->schedule('test:ok', now()->subHour());
    $task->update(['status' => PlannedTaskStatus::RUNNING, 'started_at' => now()->subMinutes(5)]);

    expect($this->planner->releaseStuck())->toBe(0);

    $task->update(['started_at' => now()->subHours(2)]);
    config(['planner.stuck_after' => null]);

    expect($this->planner->releaseStuck())->toBe(0)
        ->and($task->fresh()->status)->toBe(PlannedTaskStatus::RUNNING);
});

it('cancels only pending tasks of a group', function () {
    $pending = $this->planner->schedule('test:ok', now()->addHour(), group: 'g1');
    $running = $this->planner->schedule('test:ok', now()->addHour(), group: 'g1');
    $running->update(['status' => PlannedTaskStatus::RUNNING]);
    $done = $this->planner->schedule('test:ok', now()->addHour(), group: 'g1');
    $done->update(['status' => PlannedTaskStatus::DONE]);
    $other = $this->planner->schedule('test:ok', now()->addHour(), group: 'g2');

    expect($this->planner->cancelGroup('g1', 'why'))->toBe(1)
        ->and($pending->fresh()->status)->toBe(PlannedTaskStatus::CANCELED)
        ->and($pending->fresh()->message)->toBe('why')
        ->and($pending->fresh()->executed_at)->not->toBeNull()
        ->and($running->fresh()->status)->toBe(PlannedTaskStatus::RUNNING)
        ->and($done->fresh()->status)->toBe(PlannedTaskStatus::DONE)
        ->and($other->fresh()->status)->toBe(PlannedTaskStatus::PENDING);
});

it('lists active tasks of a group ordered by run time', function () {
    $later = $this->planner->schedule('test:ok', now()->addHours(2), group: 'g1');
    $running = $this->planner->schedule('test:ok', now()->addHour(), group: 'g1');
    $running->update(['status' => PlannedTaskStatus::RUNNING]);
    $this->planner->schedule('test:ok', now()->addMinute(), group: 'g1')->update(['status' => PlannedTaskStatus::DONE]);

    expect($this->planner->pending('g1')->pluck('id')->all())->toBe([$running->id, $later->id]);
});

it('checks and lists available commands', function () {
    expect($this->planner->commandExists('test:ok --x=1'))->toBeTrue()
        ->and($this->planner->commandExists('  test:ok'))->toBeTrue()
        ->and($this->planner->commandExists('no:such'))->toBeFalse()
        ->and($this->planner->commandExists(''))->toBeFalse()
        ->and($this->planner->commands())->toHaveKey('test:ok', 'Test command')
        ->and($this->planner->commands())->not->toHaveKey('test:hidden')
        ->and(array_keys($this->planner->commands()))->toBe(collect($this->planner->commands())->keys()->sort()->values()->all());
});

it('exposes the planner through the facade', function () {
    expect(PlannerFacade::getFacadeRoot())->toBe($this->planner)
        ->and(PlannerFacade::schedule('test:ok', now())->command)->toBe('test:ok');
});

it('registers planner:run in the scheduler', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'planner:run'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
