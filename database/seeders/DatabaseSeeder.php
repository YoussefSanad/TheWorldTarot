<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Only seeders that are safe to run on production belong here. It runs on
     * every deploy, so anything listed must be idempotent and must never
     * overwrite something the client has edited.
     *
     * Test fixtures do not belong here. Use factories in tests instead.
     */
    public function run(): void
    {
        $this->call([
            ProductSeeder::class,
        ]);
    }
}
