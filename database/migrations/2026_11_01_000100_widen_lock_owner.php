<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sabre stores the LOCK request's owner element as-is. On PostgreSQL the
 * column was varchar(100), so a longer owner caused a 500. SQLite does not
 * enforce the length and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('locks', function (Blueprint $table): void {
            $table->text('owner')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('locks', function (Blueprint $table): void {
            $table->string('owner', 100)->nullable()->change();
        });
    }
};
