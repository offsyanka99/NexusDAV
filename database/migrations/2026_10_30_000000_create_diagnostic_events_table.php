<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diagnostic_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('source', 16);
            $table->string('kind', 32);
            $table->string('ip', 45);
            $table->string('username');
            $table->string('public_id', 32)->nullable();
            $table->string('method', 16);
            $table->string('path', 512);
            $table->unsignedSmallInteger('status');
            $table->string('reason', 64);
            $table->unsignedInteger('repeat_count');
            $table->unsignedInteger('bucket');
            $table->unique(
                ['source', 'kind', 'ip', 'username', 'path', 'reason', 'bucket'],
                'diagnostic_events_collapse_unique',
            );
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnostic_events');
    }
};
