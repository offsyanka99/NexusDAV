<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A calendar whose events the server makes from a holiday provider (PR 41 of
 * the Version 1.x.x plan). One row per calendar, deleted with it. `region`
 * is the ISO 3166 code (`CA` or `CA-ON`), `locale` the language of the names,
 * `types` a JSON list of the holiday types it holds, as Yasumi names them.
 * `data_version` records the holiday data and generator that made the events,
 * so a newer image can generate them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->unique()->constrained('calendars')->cascadeOnDelete();
            $table->string('region', 16);
            $table->string('locale', 16);
            $table->text('types');
            $table->unsignedSmallInteger('from_year');
            $table->unsignedSmallInteger('to_year');
            $table->string('data_version', 64);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendars');
    }
};
