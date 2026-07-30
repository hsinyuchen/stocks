<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 放寬 news_items 的 title 與 url 長度。
 *
 * 兩欄原本都是 string()（varchar 255）。SQLite 不檢查宣告長度，所以超長值一直
 * 存得進去也讀得出來；換成 MySQL 後 strict mode 會直接拒收，抓取新聞時會以
 * 「Data too long」失敗。實測既有資料：url 最長 834（2957 筆中有 869 筆超過
 * 255），title 最長 252。
 *
 * url 改 text 而非更長的 varchar：Google News RSS 的連結帶有簽章參數，長度沒有
 * 自然上限，訂一個更大的數字只是把同樣的問題往後推。title 有自然上限，維持
 * varchar 以保留日後建索引的空間（目前 news_items 的索引在 url_hash、market、
 * domain、kind、source、relevant+published_at、created_at，兩欄都沒有索引，
 * 改型別不影響任何索引）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            $table->string('title', 512)->change();
            $table->text('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // 還原會截斷既有資料，故先把超長值砍到 255 再縮欄位，避免 strict mode 擋下。
        DB::table('news_items')
            ->whereRaw('LENGTH(title) > 255')
            ->update(['title' => DB::raw('SUBSTRING(title, 1, 255)')]);

        DB::table('news_items')
            ->whereRaw('LENGTH(url) > 255')
            ->update(['url' => null]);

        Schema::table('news_items', function (Blueprint $table): void {
            $table->string('title')->change();
            $table->string('url')->nullable()->change();
        });
    }
};
