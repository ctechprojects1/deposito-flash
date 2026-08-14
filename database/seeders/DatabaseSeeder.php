<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            // Locations/Products/Stock são populados pela importação do CSV:
            //   php artisan stock:import storage/app/carga_inicial.csv
        ]);
    }
}
