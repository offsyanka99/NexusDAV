<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The push merge window: at most one row per topic, written when a change
 * commits and taken by the queued job that sends its message about five
 * seconds later. Changes inside the window update the row. `content_depth`
 * is the smallest depth a content update needs (0 the collection itself,
 * 1 a member, 2 deeper), null for none. `skip` lists the registrations that
 * every change in the window asked not to notify (Push-Dont-Notify).
 * `source` names the collection whose sync token goes into the message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 32)->unique();
            $table->string('source', 64)->nullable();
            $table->unsignedTinyInteger('content_depth')->nullable();
            $table->boolean('property_update')->default(false);
            $table->text('skip')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_outbox');
    }
};
