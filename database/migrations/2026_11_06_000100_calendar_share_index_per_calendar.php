<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sabre's PostgreSQL script makes (principaluri, share_href) unique on
 * calendarinstances. share_href is the sharee, so one person could receive
 * only one calendar share on the whole server. The rule wanted is one share
 * per sharee per calendar: (calendarid, share_href), which SQLite already has.
 *
 * Owner rows keep a null share_href, and PostgreSQL allows many nulls in a
 * unique index. No share existed before this migration, so the new index
 * cannot fail on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('calendarinstances', function (Blueprint $table): void {
            $table->dropUnique(['principaluri', 'share_href']);
            $table->unique(['calendarid', 'share_href']);
        });
    }

    /**
     * Going back means going back to a release without sharing. Its code
     * would list a received calendar share as one of the sharee's own
     * calendars, and sabre's index cannot hold two shares of one sharee, so
     * the calendar shares are removed. addressbookinstances goes with the
     * migration before this one.
     */
    public function down(): void
    {
        DB::table('calendarinstances')->where('access', '!=', 1)->delete();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('calendarinstances', function (Blueprint $table): void {
            $table->dropUnique(['calendarid', 'share_href']);
            $table->unique(['principaluri', 'share_href']);
        });
    }
};
