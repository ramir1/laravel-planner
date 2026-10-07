# Laravel Planner

Database-backed planner of **one-off deferred artisan commands** for Laravel.

Laravel has queues (including delayed jobs) and a scheduler for recurring commands. A delayed job, however,
lives inside the queue backend: you cannot list it, cancel it, or survive a queue flush. Planner stores every
planned run in a table: schedule a command for a given moment, group runs, cancel them and see what happened
to each one.

```bash
php artisan planner:at "2026-10-08 03:00" "anonce:bump 15" --group=anonce:15
```

[Русская версия](README.ru.md)

## Installation

```bash
composer require ramir1/laravel-planner
php artisan migrate
```

`planner:run` is registered in the Laravel scheduler automatically (every minute, without overlapping), so
make sure the scheduler (`schedule:run` / `schedule:work`) and a queue worker are running.

Publish the config if you need to change defaults:

```bash
php artisan vendor:publish --tag=planner-config
```

## Writing a command

Any artisan command can be planned; the command knows nothing about the planner. Pass models by id. Business
decisions ("not relevant any more", "cancel the remaining runs") belong to the command:

```php
use Illuminate\Console\Command;
use Ramir\Planner\Facades\Planner;

class AnonceBumpCommand extends Command
{
    protected $signature = 'anonce:bump {anonce}';

    public function handle(): int
    {
        $anonce = Anonce::find($this->argument('anonce'));

        if (! $anonce) {
            Planner::cancelGroup("anonce:{$this->argument('anonce')}", 'Anonce deleted');
            $this->line('Anonce deleted');

            return self::SUCCESS;
        }

        if (! $anonce->isPublished()) {
            $this->line('Not published, nothing to do');

            return self::SUCCESS;
        }

        $anonce->bump();
        $this->line('Bumped');

        return self::SUCCESS;
    }
}
```

The planner only runs the command and records the result: exit code `0` — `done`; any other code or an exception
— retried after `backoff` seconds, `failed` after `max_attempts`. The command output is stored in the task
`message` (up to 1000 characters).

## Scheduling

From the console — `time` is anything `Carbon::parse()` understands, in the application timezone:

```bash
php artisan planner:at "2026-10-08 03:00" "reports:build --month=9"
php artisan planner:at "+2 hours" "cache:clear" --group=maintenance
```

From code:

```php
use Ramir\Planner\Facades\Planner;

Planner::schedule('anonce:bump', now()->addDay(), ['anonce' => $anonce->id], group: "anonce:{$anonce->id}");
Planner::schedule('anonce:bump 15', now()->addDays(2));                  // arguments as a string

Planner::scheduleMany([                                                    // one transaction
    ['command' => 'anonce:bump', 'run_at' => now()->addDay(), 'parameters' => ['anonce' => 15], 'group' => 'anonce:15'],
    ['command' => 'anonce:bump', 'run_at' => now()->addDays(2), 'parameters' => ['anonce' => 15], 'group' => 'anonce:15'],
]);

Planner::pending('anonce:15');     // pending + running runs of the group
Planner::lastRunAt('anonce:15');   // run_at of the last pending run, or null
Planner::cancelGroup('anonce:15');
```

`schedule()` does not check that the command exists (that would load every command of the application on each
call); an unknown command fails when it runs. `planner:at` does check it.

`source` (optional morph, `source: $orderLine`) records where a run came from, e.g. a paid order line.

## Statuses and execution

`pending` → `running` → `done` | `failed`; `canceled` — cancelled before it ran (`cancelGroup()`).

Every minute `planner:run` takes due `pending` runs (oldest `run_at` first, up to `batch`), atomically marks
each one `running` and dispatches `RunPlannedTaskJob` to the queue. The worker runs the command in-process via
`Artisan::call()` — no new PHP process, so the overhead per run is negligible compared to the command itself
(about 0.1 ms for an empty command). Runs are dispatched in `run_at` order but executed in parallel by the
workers; a group is for cancelling together, not for ordering.

A run stuck in `running` longer than `stuck_after` minutes (dead worker) is returned to `pending`; a stale job
of such a run is ignored, so it is not executed twice.

### Missed runs

A run is due when it is `pending` and `run_at <= now()`; this is checked once a minute, so a run starts within
about a minute after `run_at` (plus the queue wait). Runs live in the database, so nothing is lost while the
server is down: after it comes back, the first `planner:run` dispatches every missed run (oldest first, up to
`batch` per minute).

There is no "too late" limit — a run late by a week still executes. If a late run makes no sense, the command
checks it itself:

```php
if ($anonce->bumped_at?->isToday()) {
    $this->line('Already bumped today');

    return self::SUCCESS;
}
```

A run interrupted by a shutdown stays `running` and is returned to `pending` after `stuck_after` minutes, then
executed again — write commands so that a repeated run is safe.

`run_at` is stored in the application timezone (`app.timezone`); changing it shifts all planned runs.

## Configuration

| Key | Default | Description |
|---|---|---|
| `table` | `planned_tasks` | table name |
| `run_migrations` | `true` | load package migrations (disable if published via `planner-migrations`) |
| `schedule` | `true` (`PLANNER_SCHEDULE`) | register `planner:run` in the scheduler |
| `connection`, `queue` | `null` | queue for `RunPlannedTaskJob` |
| `batch` | `500` | due runs dispatched per `planner:run` |
| `max_attempts`, `backoff` | `3`, `300` | retries on a non-zero exit code or an exception |
| `stuck_after` | `60` | minutes before a running task is released |

## Testing

```bash
composer test
composer phpstan
composer pint
```

## License

MIT
