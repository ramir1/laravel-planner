<?php

declare(strict_types=1);

namespace Ramir\Planner\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Ramir\Planner\Planner;

/**
 * Выполняет одну захваченную запланированную задачу.
 * Повторы управляются планировщиком (planner.max_attempts), а не очередью.
 */
class RunPlannedTaskJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $plannedTaskId, public ?int $attempt = null) {}

    public function handle(Planner $planner): void
    {
        $planner->run($this->plannedTaskId, $this->attempt);
    }
}
