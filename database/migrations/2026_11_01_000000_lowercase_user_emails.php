<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emails are matched without case by DAV and the admin login. Store them in
 * lower case and enforce that in the database with a unique index on
 * lower(email). Two accounts whose emails differ only in case stop the
 * migration with the addresses to fix, instead of picking one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collisions = DB::table('users')
            ->selectRaw('lower(email) as address, count(*) as accounts')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('address')
            ->all();

        if ($collisions !== []) {
            throw new RuntimeException(
                'These emails belong to more than one account when case is ignored: '
                .implode(', ', $collisions)
                .'. Change one of each pair in the admin panel, then start again.'
            );
        }

        DB::table('users')->update(['email' => DB::raw('lower(email)')]);
        DB::table('principals')->update(['email' => DB::raw('lower(email)')]);
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
