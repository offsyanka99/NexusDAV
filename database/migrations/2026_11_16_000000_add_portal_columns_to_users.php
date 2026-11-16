<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User portal settings (PR 49 of the Version 2 plan). show_week_numbers is
 * the user's Show week numbers setting. portal_state holds per-user UI state
 * (PortalState), so later apps add keys without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('show_week_numbers')->default(false);
            $table->json('portal_state')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['show_week_numbers', 'portal_state']);
        });
    }
};
