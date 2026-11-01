<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The request ID of a row's first occurrence, so an admin can find the
 * matching lines in the container log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diagnostic_events', function (Blueprint $table): void {
            $table->string('request_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_events', function (Blueprint $table): void {
            $table->dropColumn('request_id');
        });
    }
};
