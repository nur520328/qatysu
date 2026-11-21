<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Тек әзірлеу мен сынаққа арналған жасанды аккаунттар.
     * Өндірістік ортада іске қосылмайды: онда алғашқы әкімші бөлек командамен ашылады.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DatabaseSeeder өндірістік ортада іске қосылмайды.');
        }

        User::factory()->admin()->create([
            'full_name' => 'Сынақ Әкімші',
            'login' => 'akimshi',
        ]);

        User::factory()->create([
            'full_name' => 'Сынақ Мұғалім',
            'login' => 'mugalim',
        ]);

        User::factory()->mustChangePassword()->create([
            'full_name' => 'Сынақ Жаңа Мұғалім',
            'login' => 'zhana',
        ]);
    }
}
