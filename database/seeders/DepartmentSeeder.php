<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Department::create(['name' => 'Software']);
        Department::create(['name' => 'Academic']);
        Department::create(['name' => 'Graphic']);
        Department::factory()->count(7)->create();
    }
}
