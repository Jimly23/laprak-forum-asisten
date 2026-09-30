<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
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
        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@kampus.ac.id')],
            [
                'name' => 'Admin Utama',
                'password' => env('ADMIN_PASSWORD', 'admin123'),
                'role' => 'super_admin',
            ]
        );

        foreach ([
            ['code' => 'TI-1A', 'name' => 'Teknik Informatika — 1A'],
            ['code' => 'TI-1B', 'name' => 'Teknik Informatika — 1B'],
            ['code' => 'SI-2A', 'name' => 'Sistem Informasi — 2A'],
            ['code' => 'SI-2B', 'name' => 'Sistem Informasi — 2B'],
            ['code' => 'MI-3A', 'name' => 'Manajemen Informatika — 3A'],
        ] as $classroom) {
            Classroom::query()->updateOrCreate(['code' => $classroom['code']], $classroom);
        }

        foreach ([
            ['code' => 'IF101', 'name' => 'Pemrograman Web'],
            ['code' => 'IF202', 'name' => 'Basis Data'],
            ['code' => 'SI201', 'name' => 'Analisis Sistem'],
            ['code' => 'MKU110', 'name' => 'Bahasa Indonesia'],
        ] as $course) {
            Course::query()->updateOrCreate(['code' => $course['code']], $course);
        }
    }
}
