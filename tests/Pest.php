<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ramir\Planner\Tests\TestCase;

uses(
    TestCase::class,
)->in('Feature', 'Unit');

/**
 * Тестовые команды: test:ok (печатает x=<option>), test:fail (код 1), test:hidden.
 */
function defineTestCommands(): void
{
    Artisan::command('test:ok {--x=}', function () {
        $this->line('x='.$this->option('x'));
    })->describe('Test command');
    Artisan::command('test:fail', function () {
        $this->line('broken');

        return 1;
    });
    Artisan::command('test:hidden', fn () => 0)->setHidden();
}
