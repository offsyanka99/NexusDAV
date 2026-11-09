<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WebDAV-Push registrations (docs/design/webdav-push.md). One row per push
 * resource and DAV path, so `scope` is a hash of the two. `registration` is
 * the id in the registration URL /dav/push/<id>. The client's key and auth
 * secret are encrypted with APP_KEY. A row goes with its user and with the
 * app password it was registered with. `removed_at` marks a row whose
 * collection was deleted: it gets the deletion's message, then goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('registration', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_password_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('scope', 64)->unique();
            $table->text('path');
            $table->string('topic', 32)->index();
            $table->text('push_resource');
            $table->text('public_key');
            $table->text('auth_secret');
            $table->string('content_update_depth', 8)->nullable();
            $table->string('property_update_depth', 8)->nullable();
            $table->text('properties')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_failure', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
