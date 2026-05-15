<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles (idempotent)
        foreach (['admin', 'encoder', 'viewer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $accounts = [
            [
                'name'     => 'OSCA Admin',
                'email'    => 'admin@osca.local',
                'password' => Hash::make('Admin@OSCA2026!'),
                'role'     => 'admin',
            ],
            [
                'name'     => 'OSCA Encoder',
                'email'    => 'encoder@osca.local',
                'password' => Hash::make('Encoder@OSCA2026!'),
                'role'     => 'encoder',
            ],
            [
                'name'     => 'OSCA Viewer',
                'email'    => 'viewer@osca.local',
                'password' => Hash::make('Viewer@OSCA2026!'),
                'role'     => 'viewer',
            ],
        ];

        foreach ($accounts as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => $data['password']]
            );
            $user->syncRoles([$data['role']]);
        }
    }
}
