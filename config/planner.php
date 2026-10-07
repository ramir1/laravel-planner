<?php

declare(strict_types=1);

return [
    /*
     * Table holding planned tasks.
     */
    'table' => 'planned_tasks',

    /*
     * Load package migrations automatically. Disable if you publish them (planner-migrations tag).
     */
    'run_migrations' => true,

    /*
     * Register `planner:run` in the Laravel scheduler (every minute, without overlapping).
     */
    'schedule' => env('PLANNER_SCHEDULE', true),

    /*
     * Queue connection and queue name for RunPlannedTaskJob. null = application defaults.
     */
    'connection' => env('PLANNER_QUEUE_CONNECTION'),
    'queue' => env('PLANNER_QUEUE'),

    /*
     * How many due tasks `planner:run` dispatches per run.
     */
    'batch' => 500,

    /*
     * Attempts before a task is marked as failed, and delay (seconds) before the next attempt.
     */
    'max_attempts' => 3,
    'backoff' => 300,

    /*
     * A task stuck in "running" longer than this (minutes) is returned to "pending"
     * (e.g. the worker died). Set null to disable.
     */
    'stuck_after' => 60,
];
