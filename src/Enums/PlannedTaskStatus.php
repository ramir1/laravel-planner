<?php

declare(strict_types=1);

namespace Ramir\Planner\Enums;

enum PlannedTaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case CANCELED = 'canceled';
    case FAILED = 'failed';

    /**
     * Задача ещё может быть выполнена (ожидает или выполняется).
     */
    public function isActive(): bool
    {
        return $this === self::PENDING || $this === self::RUNNING;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_combine(
            array_map(fn (self $case) => $case->value, self::cases()),
            array_map(fn (self $case) => ucfirst($case->value), self::cases()),
        );
    }
}
