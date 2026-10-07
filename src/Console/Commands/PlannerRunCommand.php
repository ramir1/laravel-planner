<?php

declare(strict_types=1);

namespace Ramir\Planner\Console\Commands;

use Illuminate\Console\Command;
use Ramir\Planner\Planner;

class PlannerRunCommand extends Command
{
    protected $signature = 'planner:run {--limit= : Maximum number of due tasks to dispatch}';

    protected $description = 'Dispatch due planned tasks to the queue';

    public function handle(Planner $planner): int
    {
        $released = $planner->releaseStuck();
        $limit = $this->option('limit');
        $dispatched = $planner->dispatchDue($limit !== null ? (int) $limit : null);

        $this->components->info("Dispatched: {$dispatched}".($released ? ", released stuck: {$released}" : ''));

        return self::SUCCESS;
    }
}
