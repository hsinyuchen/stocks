<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class DemoteUserCommand extends Command
{
    protected $signature = 'user:demote {email}';

    protected $description = '將指定 email 的管理員降為一般使用者（保留最後一位有效 admin）。';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('找不到該 email 的使用者。');

            return self::FAILURE;
        }

        // 系統必須至少保留一位未停用的 admin，否則管理功能會被鎖死。
        $isLastActiveAdmin = $user->is_admin
            && User::query()->where('is_admin', true)->whereNull('disabled_at')->count() === 1;

        if ($isLastActiveAdmin) {
            $this->error('這是最後一位有效管理員，不可降級。');

            return self::FAILURE;
        }

        $user->is_admin = false;
        $user->save();

        $this->info("{$user->email} 已降為一般使用者。");

        return self::SUCCESS;
    }
}
