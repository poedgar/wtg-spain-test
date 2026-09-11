<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(
            ['slug' => 'supplier-a'],
            ['name' => 'Supplier A'],
        );

        Supplier::updateOrCreate(
            ['slug' => 'supplier-b'],
            ['name' => 'Supplier B'],
        );
    }
}