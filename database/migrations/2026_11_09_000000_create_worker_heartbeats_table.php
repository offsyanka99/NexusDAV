<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per queue worker, written by the worker itself about once a
 * minute. The file cache is per container, so the app learns from this
 * table whether a worker is running (WorkerStatus). `last_probe` is the
 * token of the last probe job the worker ran (nexusdav:worker-status
 * --probe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('worker', 100)->unique();
            $table->string('version', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('beat_at')->index();
            $table->string('last_probe', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};
