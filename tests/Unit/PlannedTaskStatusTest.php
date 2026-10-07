<?php

declare(strict_types=1);

use Ramir\Planner\Enums\PlannedTaskStatus;

it('knows which statuses are active', function () {
    $active = array_filter(PlannedTaskStatus::cases(), fn (PlannedTaskStatus $status) => $status->isActive());

    expect(array_values($active))->toBe([PlannedTaskStatus::PENDING, PlannedTaskStatus::RUNNING]);
});

it('lists status labels keyed by value', function () {
    expect(PlannedTaskStatus::labels())->toBe([
        'pending' => 'Pending',
        'running' => 'Running',
        'done' => 'Done',
        'canceled' => 'Canceled',
        'failed' => 'Failed',
    ]);
});
