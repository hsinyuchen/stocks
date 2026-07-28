<?php

namespace App\Services\News;

use App\Models\Instrument;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 公司名 → 代號的對照表，供新聞分類判斷關聯個股。
 *
 * 來源是 config('news.symbols') ∪ instruments 表。config 那份是手維護的種子
 * 清單（僅 12 檔），涵蓋率是關聯個股偵測的硬上限——南亞科、華邦電、聯電、
 * 台達電這些常出現在新聞裡的標的都不在其中。instruments 表則會隨使用者搜尋、
 * 自選股、選股器股池自然成長，是免費且持續擴充的名稱來源。
 *
 * config 的條目優先：手維護的別名（tsmc → 2330.TW）不會被 instruments 覆蓋。
 */
class SymbolDictionary
{
    private const CACHE_KEY = 'news:symbol-dictionary';

    /** 名稱過短會誤配（例如單字名稱出現在任意句子裡），一律略過。 */
    private const MIN_NAME_LENGTH = 2;

    /**
     * 小寫名稱 => 正規代號。
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $config = $this->fromConfig();

        // instruments 查詢失敗（未跑 migration、純單元測試無 DB）時退回 config，
        // 分類功能維持可用，不因為字典擴充而讓整條 ingest 掛掉。
        try {
            $dynamic = Cache::remember(
                self::CACHE_KEY,
                (int) config('news.symbol_dictionary_ttl_minutes', 60) * 60,
                fn (): array => $this->fromInstruments(),
            );
        } catch (Throwable) {
            $dynamic = [];
        }

        // config 覆蓋 instruments：手維護的別名優先。
        return array_merge($dynamic, $config);
    }

    /** @return array<string, string> */
    private function fromConfig(): array
    {
        $out = [];

        foreach ((array) config('news.symbols', []) as $name => $symbol) {
            $name = mb_strtolower(trim((string) $name));

            if (mb_strlen($name) >= self::MIN_NAME_LENGTH) {
                $out[$name] = (string) $symbol;
            }
        }

        return $out;
    }

    /**
     * instruments 的 name 與 symbol 都當成可比對的名稱。
     *
     * name 等於 symbol 時（provider 建立 instrument 時的預設值）只留一份。
     * 名稱含市場後綴的代號本身也收錄，讓新聞直接寫 "2330.TW" 時能命中。
     *
     * @return array<string, string>
     */
    private function fromInstruments(): array
    {
        $out = [];

        Instrument::query()
            ->select(['symbol', 'name'])
            ->orderBy('id')
            ->chunk(500, function ($instruments) use (&$out): void {
                foreach ($instruments as $instrument) {
                    $symbol = (string) $instrument->symbol;
                    $name = mb_strtolower(trim((string) $instrument->name));

                    // 長度下限必須同時套用在代號上，不能只檢查名稱。單字母代號
                    // （V=Visa、F=Ford、T=AT&T）作為比對鍵會命中任何含該字母的
                    // 文字——實測「力成法說…超微與博通」就被判成含 Visa。
                    if (mb_strlen($symbol) >= self::MIN_NAME_LENGTH) {
                        $out[mb_strtolower($symbol)] = $symbol;
                    }

                    if ($name !== '' && mb_strlen($name) >= self::MIN_NAME_LENGTH) {
                        $out[$name] = $symbol;
                    }
                }
            });

        return $out;
    }

    /** 新增 instrument 後呼叫，讓字典在下次分類時反映新標的。 */
    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // 快取不可用時無需處理：all() 本來就會退回 config。
        }
    }
}
