<?php

namespace Tests\Feature\News;

use App\Services\News\NewsClassifier;
use App\Services\News\TransmissionMapper;
use Tests\TestCase;

class TransmissionMapperTest extends TestCase
{
    private function map(string $title, string $summary = ''): array
    {
        $domains = (new NewsClassifier)->classify($title, $summary)['domains'];

        return app(TransmissionMapper::class)->map($title, $summary, $domains);
    }

    public function test_hormuz_event_maps_to_shipping_energy_and_airlines(): void
    {
        $chains = $this->map('Iran hosts Hormuz calls with Saudi Arabia and Oman', 'Oil prices climb on supply risk.');

        $this->assertNotEmpty($chains);
        $this->assertSame('hormuz_oil', $chains[0]['key']);

        $sectors = collect($chains[0]['sectors']);

        $this->assertSame('positive', $sectors->firstWhere('name', '航運')['direction']);
        $this->assertSame('negative', $sectors->firstWhere('name', '航空')['direction']);
    }

    /** 傳導路徑必須逐段說明因果，而不是只給結論。 */
    public function test_chain_describes_the_causal_path(): void
    {
        $chains = $this->map('伊朗局勢緊張 荷莫茲海峽航運受阻');

        $this->assertGreaterThanOrEqual(2, count($chains[0]['chain']));
        $this->assertStringContainsString('原油', $chains[0]['chain'][0]);
    }

    public function test_ai_capex_event_maps_to_chip_and_server_chain(): void
    {
        $chains = $this->map('雲端業者上修資料中心資本支出', 'AI 伺服器需求強勁，先進封裝產能吃緊。');

        $keys = array_column($chains, 'key');
        $this->assertContains('ai_capex', $keys);
    }

    public function test_memory_event_maps_without_domain_restriction(): void
    {
        $chains = $this->map('長鑫擴產 DRAM 現貨價下滑');

        $this->assertContains('memory_cycle', array_column($chains, 'key'));
    }

    /**
     * 領域限制的存在理由：「關稅」出現在非地緣政治的新聞裡不該觸發半導體
     * 出口管制的傳導鏈。
     */
    public function test_domain_restriction_prevents_false_triggers(): void
    {
        // 命中 chip_export_control 的關鍵字，但語境是餐飲，不屬 geopolitics。
        $chains = app(TransmissionMapper::class)->map('餐廳關稅成本轉嫁消費者', '', ['other']);

        $this->assertNotContains('chip_export_control', array_column($chains, 'key'));
    }

    public function test_unrelated_news_maps_to_nothing(): void
    {
        $this->assertSame([], $this->map('Local sports team wins the weekend match'));
    }

    /** 一則新聞可同時命中多條傳導鏈（例如關稅同時牽動半導體與匯率）。 */
    public function test_one_item_can_trigger_multiple_chains(): void
    {
        $chains = $this->map('美國對半導體加徵關稅 新台幣同步貶值', '出口管制範圍擴大。');

        $this->assertGreaterThanOrEqual(2, count($chains));
    }

    /** 傳導鏈的個股與 related_symbols 是不同語意，必須分開取用。 */
    public function test_symbols_collects_every_sector_ticker_deduplicated(): void
    {
        $chains = $this->map('Iran hosts Hormuz calls', 'Oil supply risk.');
        $symbols = app(TransmissionMapper::class)->symbols($chains);

        $this->assertContains('2603.TW', $symbols);
        $this->assertSame(array_values(array_unique($symbols)), $symbols);
    }

    /** 關鍵字比對與分類器共用同一套規則，ASCII 須走詞邊界。 */
    public function test_keyword_matching_uses_word_boundaries(): void
    {
        // "aid" 不該命中 ai_capex 的 'ai'。
        $chains = app(TransmissionMapper::class)->map('Humanitarian aid arrives', '', ['tech']);

        $this->assertNotContains('ai_capex', array_column($chains, 'key'));
    }

    /** 每條規則的設定形狀都要完整，缺欄位會讓 UI 與 prompt 拿到破碎資料。 */
    public function test_all_configured_rules_are_well_shaped(): void
    {
        foreach (require database_path('seeders/data/transmission_rules.php') as $rule) {
            $this->assertArrayHasKey('key', $rule);
            $this->assertArrayHasKey('label', $rule);
            $this->assertNotEmpty($rule['chain'], "{$rule['key']} 缺傳導路徑");
            $this->assertNotEmpty($rule['sectors'], "{$rule['key']} 缺受影響板塊");
            $this->assertNotEmpty($rule['when']['keywords'] ?? [], "{$rule['key']} 缺觸發關鍵字");

            foreach ($rule['sectors'] as $sector) {
                $this->assertContains($sector['direction'], ['positive', 'negative', 'neutral']);
                $this->assertNotEmpty($sector['symbols'], "{$rule['key']}／{$sector['name']} 缺觀察標的");
            }
        }
    }

    /**
     * 詞邊界比對必須容許複數。實測「Trump's Section 301 Tariffs」對不上關鍵字
     * tariff、「targeting drone makers」對不上 drone——新聞標題大量使用複數形，
     * 漏掉它們會靜默吃掉覆蓋率。
     */
    public function test_plural_keywords_still_match(): void
    {
        $classifier = new NewsClassifier;

        $this->assertTrue($classifier->matchesKeyword('trump section 301 tariffs map', 'tariff'));
        $this->assertTrue($classifier->matchesKeyword('china targets drone makers', 'drone'));
        $this->assertTrue($classifier->matchesKeyword('new sanctions on moscow', 'sanction'));

        // 複數放寬不得破壞既有的誤判防護。
        $this->assertFalse($classifier->matchesKeyword('software update released', 'war'));
        $this->assertFalse($classifier->matchesKeyword('a senator spoke today', 'nato'));
        $this->assertFalse($classifier->matchesKeyword('local sports results', 'port'));
    }

    /**
     * 反向事件必須翻轉板塊方向。
     *
     * 記憶體價格上漲與下跌共用一條規則，direction_cues 應翻轉板塊方向。
     * (原本用升息／降息測試此行為，但 rate_policy 已中性化：關鍵字只能偵測事件，
     * 方向由實際殖利率數據決定。用 memory_cycle 確認 direction_cues 機制仍可運作。)
     */
    public function test_reverse_direction_cue_flips_every_sector(): void
    {
        $priceHike = $this->map('記憶體報價上揚供不應求缺貨');
        $priceDrop = $this->map('長鑫擴產 DRAM 現貨價下滑庫存去化');

        $this->assertSame('forward', $priceHike[0]['polarity']);
        $this->assertSame('reverse', $priceDrop[0]['polarity']);

        $memoryOnHike = collect($priceHike[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '記憶體');
        $memoryOnDrop = collect($priceDrop[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '記憶體');

        $this->assertSame('positive', $memoryOnHike['direction']);
        $this->assertSame('negative', $memoryOnDrop['direction']);
    }

    public function test_currency_direction_flips_between_depreciation_and_appreciation(): void
    {
        $weak = $this->map('新台幣重貶 電子出口商受惠');
        $strong = $this->map('新台幣強升 出口商匯損擴大');

        $exportOnWeak = collect($weak[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '電子出口');
        $exportOnStrong = collect($strong[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '電子出口');

        $this->assertSame('positive', $exportOnWeak['direction']);
        $this->assertSame('negative', $exportOnStrong['direction']);
    }

    /** 只提到主題而未指明漲跌時不可猜方向，一律降為中性。 */
    public function test_ambiguous_direction_degrades_to_neutral(): void
    {
        $chains = $this->map('記憶體族群今日表現');

        $memory = collect($chains[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '記憶體');

        $this->assertSame('unknown', $chains[0]['polarity']);
        $this->assertSame('neutral', $memory['direction']);
    }

    /** 「DRAM 現貨價下滑」不該被標成記憶體正向。 */
    public function test_memory_price_drop_is_negative_for_memory_makers(): void
    {
        $chains = $this->map('長鑫擴產 DRAM 現貨價下滑');

        $memory = collect($chains[0]['sectors'])->first(fn (array $s): bool => $s['name'] === '記憶體');

        $this->assertSame('negative', $memory['direction']);
    }

    /** 單向事件（天災、出口管制）沒有 direction_cues，維持宣告方向。 */
    public function test_rules_without_cues_keep_declared_direction(): void
    {
        $chains = $this->map('日本熊本規模7.1強震 廠區疏散');
        $disaster = collect($chains)->firstWhere('key', 'natural_disaster');

        $this->assertSame('forward', $disaster['polarity']);
        $this->assertSame('positive', collect($disaster['sectors'])->firstWhere('name', '航運與物流')['direction']);
    }

    /** 天災規則來自 gap 分析：熊本強震在未覆蓋新聞中詞頻排名極前。 */
    public function test_natural_disaster_maps_to_supply_chain_impact(): void
    {
        $chains = $this->map('日本熊本規模7.1強震 台積電廠區疏散', '半導體供應鏈受關注。');

        $this->assertContains('natural_disaster', array_column($chains, 'key'));
    }

    /** 大盤系統性下跌自成一類事件，傳導路徑與個別產業新聞不同。 */
    public function test_market_shock_maps_to_index_heavyweights(): void
    {
        $chains = $this->map('台股崩跌2030點 融資追繳壓力湧現', '權值股全面重挫。');

        $this->assertContains('market_shock', array_column($chains, 'key'));
    }
}
