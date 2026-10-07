<?php

declare(strict_types=1);

namespace Ramir\Planner\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Ramir\Planner\Models\PlannedTask schedule(string $command, \DateTimeInterface $runAt, array $parameters = [], ?string $group = null, ?\Illuminate\Database\Eloquent\Model $source = null)
 * @method static \Illuminate\Database\Eloquent\Collection scheduleMany(iterable $items)
 * @method static int cancelGroup(string $group, ?string $reason = null)
 * @method static \Illuminate\Database\Eloquent\Collection pending(string $group)
 * @method static \Illuminate\Support\Carbon|null lastRunAt(string $group)
 * @method static bool commandExists(string $command)
 * @method static array commands()
 * @method static int dispatchDue(?int $limit = null)
 * @method static int releaseStuck()
 * @method static void run(int $id, ?int $attempt = null)
 *
 * @see \Ramir\Planner\Planner
 */
class Planner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ramir\Planner\Planner::class;
    }
}
