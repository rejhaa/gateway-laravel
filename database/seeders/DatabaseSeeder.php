<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Create the one user the gateway acts for.
     */
    public function run(): void
    {
        User::primary();
    }
}
