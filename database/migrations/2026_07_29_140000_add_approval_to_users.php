<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 自助註冊改為需要管理員核准。
 *
 * 與 disabled_at 分開：disabled_at 是「曾經可用、被管理員收回」，approved_at 是
 * 「從未被放行」。合成同一個欄位的話，管理員在後台就分不出待審申請和自己停用的
 * 帳號，也無法只列出待辦。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('disabled_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });

        // 既有帳號一律視為已核准。少了這步，所有現存使用者（含唯一的管理員）
        // 會在下一次登入時被擋在門外，沒有人能進去核准任何人。
        DB::table('users')->whereNull('approved_at')->update([
            'approved_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
