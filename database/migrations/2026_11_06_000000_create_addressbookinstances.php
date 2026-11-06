<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Address book shares (PR 21, docs/design/sharing.md). One row per sharee of
 * an address book; the owner stays the addressbooks row. Cards and the change
 * log stay on addressbookid. Access is 2 (read) or 3 (read and write).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('addressbookinstances', function (Blueprint $table) use ($driver): void {
            $table->id();
            $table->foreignId('addressbookid')->constrained('addressbooks')->cascadeOnDelete();
            $table->string('principaluri', 255);
            $table->string('uri', 200);
            $table->string('displayname', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('access');
            $table->string('share_href', 100);
            $table->string('share_displayname', 100)->nullable();
            $table->unsignedSmallInteger('share_invitestatus')->default(2);

            $table->unique(['principaluri', 'uri']);
            $table->unique(['addressbookid', 'share_href']);

            // The same pair on both engines, in the column order
            // calendarinstances uses on each.
            if ($driver === 'pgsql') {
                $table->unique(['principaluri', 'addressbookid']);
            } else {
                $table->unique(['addressbookid', 'principaluri']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addressbookinstances');
    }
};
