<?php

namespace Database\Seeders;

use App\Services\UserService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        app(UserService::class)->create([
            'username' => 'test',
            'display_name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
    }
}
