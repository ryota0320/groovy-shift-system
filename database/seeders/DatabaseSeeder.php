<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['46', 'オニカイ', '蛸福'] as $storeName) {
            Store::query()->updateOrCreate(
                ['name' => $storeName],
                ['is_active' => true],
            );
        }

        $email = config('initial-admin.email');
        $password = config('initial-admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->warn('INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD が未設定のため管理者作成をスキップしました。');

            return;
        }

        if (app()->isProduction() && $password === 'password') {
            throw new RuntimeException('本番環境では初期管理者のデフォルトパスワードを使用できません。');
        }

        User::query()->updateOrCreate([
            'email' => $email,
        ], [
            'name' => config('initial-admin.name') ?: '開発管理者',
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
