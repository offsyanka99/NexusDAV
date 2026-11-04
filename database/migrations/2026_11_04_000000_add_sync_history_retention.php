<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sync history is pruned by age. created_at is the database clock so sabre's
 * INSERT, which does not name the column, still stamps the row. Existing rows
 * take the migration time and are kept until they pass the retention window.
 * synctoken_min is the oldest change number the collection can still answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['calendarchanges', 'addressbookchanges'] as $changes) {
            // SQLite cannot add a column with a CURRENT_TIMESTAMP default to a
            // table that has rows. Add it empty, stamp the existing rows, then
            // set the default (SQLite rebuilds the table for that step).
            Schema::table($changes, function (Blueprint $table): void {
                $table->timestamp('created_at')->nullable();
            });

            DB::table($changes)->update(['created_at' => now()]);

            Schema::table($changes, function (Blueprint $table) use ($changes): void {
                $table->timestamp('created_at')->useCurrent()->change();
                $table->index('created_at', $changes.'_created_at');
            });
        }

        Schema::table('calendars', function (Blueprint $table): void {
            $table->integer('synctoken_min')->default(1);
        });

        Schema::table('addressbooks', function (Blueprint $table): void {
            $table->integer('synctoken_min')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table): void {
            $table->dropColumn('synctoken_min');
        });

        Schema::table('addressbooks', function (Blueprint $table): void {
            $table->dropColumn('synctoken_min');
        });

        Schema::table('calendarchanges', function (Blueprint $table): void {
            $table->dropIndex('calendarchanges_created_at');
            $table->dropColumn('created_at');
        });

        Schema::table('addressbookchanges', function (Blueprint $table): void {
            $table->dropIndex('addressbookchanges_created_at');
            $table->dropColumn('created_at');
        });
    }
};
