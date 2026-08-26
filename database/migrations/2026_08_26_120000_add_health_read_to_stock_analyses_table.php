<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 體質判讀隨分析保存。
     *
     * 不保存的話，幾天後頁面顯示的是**現在**算出來的判讀，而歷史分析的文字仍在
     * 引用生成當下的那一份——兩者必然不一致，而使用者無從得知哪一個算數。
     *
     * 存的是判讀結果與出處（`short`／`long`／`snapshot` 三份 toArray()），
     * **不是能重算的輸入**：快照的 toArray() 刻意只輸出 metadata（代號、市場、
     * K 棒數、四個日期、取用政策、資產類型），不含 80 根 K 棒、籌碼序列與整份
     * 財報指標。要能重算就得把那些重複資料塞進每一筆分析（每筆數十 KB），
     * 而真正需要的是「這份判讀是哪一天的資料、哪一版公式算的」。
     *
     * 純新增 nullable 欄位、不動既有資料：舊列沒有判讀是事實，補一個空物件會讓
     * 呈現層分不出「當時沒算」與「算了但四塊全不可評估」。
     */
    public function up(): void
    {
        Schema::table('stock_analyses', function (Blueprint $table): void {
            $table->json('health_read')->nullable()->after('rule_signal');
        });
    }

    public function down(): void
    {
        Schema::table('stock_analyses', function (Blueprint $table): void {
            $table->dropColumn('health_read');
        });
    }
};
