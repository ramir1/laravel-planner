<?php

declare(strict_types=1);

namespace Ramir\Planner\Console\Commands;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Ramir\Planner\Planner;

class PlannerAtCommand extends Command
{
    protected $signature = 'planner:at
        {time : Run time, e.g. "2026-10-08 03:00" or "+2 hours"}
        {cmd : Artisan command with arguments, quoted}
        {--group= : Group of the planned task}';

    protected $description = 'Schedule an artisan command to run once at the given time';

    public function handle(Planner $planner): int
    {
        try {
            $runAt = Carbon::parse((string) $this->argument('time'));
        } catch (InvalidFormatException) {
            $this->components->error("Cannot parse time [{$this->argument('time')}].");

            return self::FAILURE;
        }

        $command = trim((string) $this->argument('cmd'));

        if (! $planner->commandExists($command)) {
            $this->components->error("Command [{$command}] is not defined.");

            return self::FAILURE;
        }

        if ($runAt->isPast()) {
            $this->components->warn('The time is in the past: the command will run on the next planner:run.');
        }

        $group = $this->option('group');
        $task = $planner->schedule($command, $runAt, group: $group !== null ? (string) $group : null);

        $this->components->info("Planned task #{$task->id}: {$command} at {$runAt->format('Y-m-d H:i:s')}");

        return self::SUCCESS;
    }
}
