<?php

declare(strict_types=1);

namespace Ramir\Planner\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Ramir\Planner\Enums\PlannedTaskStatus;

/**
 * Одноразовый запуск artisan-команды, запланированный на время run_at.
 *
 * @property int $id
 * @property string $command
 * @property array<string, mixed>|null $parameters
 * @property string|null $group
 * @property Carbon $run_at
 * @property PlannedTaskStatus $status
 * @property int $attempts
 * @property string|null $message
 * @property Carbon|null $started_at
 * @property Carbon|null $executed_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property Model|null $source
 *
 * @method static Builder<static> due()
 * @method static Builder<static> active()
 * @method static Builder<static> inGroup(string $group)
 */
class PlannedTask extends Model
{
    protected $fillable = [
        'command',
        'parameters',
        'group',
        'run_at',
        'status',
        'attempts',
        'message',
        'started_at',
        'executed_at',
        'source_type',
        'source_id',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    public function getTable(): string
    {
        return config('planner.table', 'planned_tasks');
    }

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'run_at' => 'datetime',
            'started_at' => 'datetime',
            'executed_at' => 'datetime',
            'status' => PlannedTaskStatus::class,
            'attempts' => 'integer',
        ];
    }

    /**
     * Источник задачи (например, оплаченная позиция заказа).
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Ожидающие задачи, время которых наступило.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', PlannedTaskStatus::PENDING)->where('run_at', '<=', now());
    }

    /**
     * Ожидающие или выполняющиеся задачи.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [PlannedTaskStatus::PENDING, PlannedTaskStatus::RUNNING]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeInGroup(Builder $query, string $group): void
    {
        $query->where('group', $group);
    }
}
