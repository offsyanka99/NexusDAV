<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The portal's item index (Decision 9, PR 50b of the Version 2 plan): one
 * row per calendar object or contact, derived from the DAV rows, which stay
 * the source of truth. A row belongs to the calendar or the address book
 * (not to a user's instance) and goes with it. Exactly one of the two is
 * set: a CHECK on PostgreSQL, triggers on SQLite (it cannot add a CHECK to
 * a table afterwards).
 *
 * Wall-clock values (all-day dates and floating times) are stored as if
 * they were UTC and flagged, so they can be read in the user's time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_index', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->nullable()->constrained('calendars')->cascadeOnDelete();
            $table->foreignId('addressbook_id')->nullable()->constrained('addressbooks')->cascadeOnDelete();
            $table->string('uri', 200);
            $table->string('kind', 16);
            $table->string('uid', 200)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('sort_name', 255)->nullable();
            $table->string('organization', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('floating')->default(false);
            $table->dateTime('due_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('parent_uid', 200)->nullable();
            $table->boolean('recurring')->default(false);
            $table->text('search_text')->nullable();
            $table->string('etag', 64);
            $table->unsignedSmallInteger('indexer_version');

            $table->unique(['calendar_id', 'uri']);
            $table->unique(['addressbook_id', 'uri']);
            $table->index(['calendar_id', 'kind', 'starts_at']);
            $table->index(['calendar_id', 'kind', 'due_at']);
            $table->index(['addressbook_id', 'sort_name']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE item_index ADD CONSTRAINT item_index_one_collection CHECK ((calendar_id IS NULL) <> (addressbook_id IS NULL))');

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $event) {
            DB::statement('CREATE TRIGGER item_index_one_collection_'.strtolower($event).' BEFORE '.$event.' ON item_index '
                .'WHEN (NEW.calendar_id IS NULL) = (NEW.addressbook_id IS NULL) '
                ."BEGIN SELECT RAISE(ABORT, 'item_index needs exactly one of calendar_id and addressbook_id'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_index');
    }
};
