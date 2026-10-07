<?php

declare(strict_types=1);

use Ramir\Planner\Models\PlannedTask;

beforeEach(function () {
    defineTestCommands();
});

it('schedules a command from the console', function () {
    $this->artisan('planner:at', ['time' => '+1 hour', 'cmd' => 'test:ok --x=1', '--group' => 'maintenance'])
        ->expectsOutputToContain('Planned task #')
        ->assertSuccessful();

    $task = PlannedTask::query()->sole();

    expect($task->command)->toBe('test:ok --x=1')
        ->and($task->group)->toBe('maintenance')
        ->and(abs($task->run_at->diffInSeconds(now()->addHour())))->toBeLessThan(5);
});

it('warns about a time in the past but still schedules', function () {
    $this->artisan('planner:at', ['time' => '2020-01-01 00:00', 'cmd' => 'test:ok'])
        ->expectsOutputToContain('in the past')
        ->assertSuccessful();

    expect(PlannedTask::query()->count())->toBe(1);
});

it('refuses an unparsable time or an unknown command', function () {
    $this->artisan('planner:at', ['time' => 'not a time', 'cmd' => 'test:ok'])->assertFailed();
    $this->artisan('planner:at', ['time' => '+1 hour', 'cmd' => 'no:such-command'])->assertFailed();

    expect(PlannedTask::query()->count())->toBe(0);
});
