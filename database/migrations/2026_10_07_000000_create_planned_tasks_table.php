<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('planner.table', 'planned_tasks'), function (Blueprint $table) {
            $table->id();
            $table->string('command');
            $table->json('parameters')->nullable();
            $table->string('group')->nullable()->index();
            $table->dateTime('run_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['status', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('planner.table', 'planned_tasks'));
    }
};
