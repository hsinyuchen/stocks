<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUserCommand extends Command
{
    protected $signature = 'user:promote {email}';

    protected $description = '將指定 email 的使用者升為總管理員。';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('找不到該 email 的使用者。');

            return self::FAILURE;
        }

        $user->is_admin = true;
        $user->save();

        $this->info("{$user->email} 已升為總管理員。");

        return self::SUCCESS;
    }
}
