<?php

namespace Tests\Feature\News;

use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\News\CnyesNewsProvider;
use App\Services\News\NewsClassifier;
use App\Services\News\NewsIngestionService;
use App\Services\News\SymbolDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CnyesAndSymbolDictionaryTest extends TestCase
{
    use RefreshDatabase;

    /** 實測回傳結構（2026-07-28 取自 news.cnyes.com/api/v3/news/category/headline）。 */
    private function payload(): array
    {
        return [
            'items' => [
                'total' => 1073,
                'data' => [
                    [
                        'newsId' => 6548623,
                        'title' => '光寶科擴大伺服器電源布局',
                        'summary' => '受惠 AI 資料中心需求，預期明年出貨成長。',
                        'publishAt' => 1785232197,
                        'market' => [
                            ['code' => '2301', 'name' => '光寶科', 'symbol' => 'TWS:2301:STOCK'],
                            ['code' => '2317', 'name' => '鴻海', 'symbol' => 'TWS:2317:STOCK'],
                            ['code' => 'USDTWD', 'name' => '美元台幣', 'symbol' => 'FX:USDTWD:FOREX'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function feed(): array
    {
        return ['key' => 'cnyes_headline', 'name' => '鉅亨網', 'driver' => 'cnyes', 'category' => 'headline', 'market' => 'TW', 'language' => 'zh-TW'];
    }

    public function test_maps_json_items_into_news_item_data(): void
    {
        Http::fake(['news.cnyes.com/*' => Http::response($this->payload(), 200)]);

        $items = (new CnyesNewsProvider)->fetch($this->feed());

        $this->assertCount(1, $items);
        $this->assertSame('鉅亨網', $items[0]->source);
        $this->assertSame('光寶科擴大伺服器電源布局', $items[0]->title);
        $this->assertSame('https://news.cnyes.com/news/id/6548623', $items[0]->url);
        $this->assertStringStartsWith('2026-', $items[0]->publishedAt);
    }

    /**
     * 上游直接提供結構化代號，比關鍵字猜測可靠——這是選 JSON API 而非
     * 想辦法找 RSS 的主要理由。
     */
    public function test_taiwan_stock_codes_come_from_the_upstream_market_array(): void
    {
        Http::fake(['news.cnyes.com/*' => Http::response($this->payload(), 200)]);

        $symbols = (new CnyesNewsProvider)->fetch($this->feed())[0]->relatedSymbols;

        $this->assertContains('2301.TW', $symbols);
        $this->assertContains('2317.TW', $symbols);
    }

    /** 非台股市場（外匯、期貨）不得被硬套上 .TW 後綴。 */
    public function test_non_taiwan_market_entries_are_skipped(): void
    {
        Http::fake(['news.cnyes.com/*' => Http::response($this->payload(), 200)]);

        $symbols = (new CnyesNewsProvider)->fetch($this->feed())[0]->relatedSymbols;

        $this->assertNotContains('USDTWD.TW', $symbols);
        $this->assertCount(2, $symbols);
    }

    public function test_upstream_failure_returns_empty_without_throwing(): void
    {
        Http::fake(['news.cnyes.com/*' => Http::response('nope', 500)]);

        $this->assertSame([], (new CnyesNewsProvider)->fetch($this->feed()));
    }

    /**
     * provider 隨項目帶來的代號必須寫進 DB。classifier 的字典認不得「光寶科」
     * 這類公司名，若 upsert 只採用 classifier 結果，上游提供的結構化代號會被
     * 整批丟棄，選 JSON API 的理由就不成立了。
     */
    public function test_provider_supplied_symbols_are_persisted_by_ingestion(): void
    {
        Http::fake(['news.cnyes.com/*' => Http::response($this->payload(), 200)]);
        config(['news.feeds' => [$this->feed()]]);

        app(NewsIngestionService::class)->ingest();

        $stored = NewsItem::query()->firstOrFail();

        $this->assertContains('2301.TW', $stored->related_symbols);
        $this->assertContains('2317.TW', $stored->related_symbols);
    }

    /**
     * 單字母代號（V=Visa、F=Ford）不得進入字典：它會命中任何含該字母的文字。
     * 實測「〈力成法說〉FOPLP攜手超微與博通」曾被判成含 Visa。
     */
    public function test_single_character_symbols_are_excluded_from_the_dictionary(): void
    {
        Instrument::factory()->create(['symbol' => 'V', 'name' => 'V']);
        Cache::flush();

        $this->assertArrayNotHasKey('v', (new SymbolDictionary)->all());

        // 超微＝AMD、博通＝AVGO 是正確命中，必須保留；只有 V 是誤判。
        $symbols = (new NewsClassifier)->classify('〈力成法說〉FOPLP攜手超微與博通', '')['symbols'];

        $this->assertNotContains('V', $symbols);
        $this->assertContains('AMD', $symbols);
        $this->assertContains('AVGO', $symbols);
    }

    /** ASCII 代號比對必須走詞邊界：KOPSI 內含 KO，不該判成可口可樂。 */
    public function test_ascii_symbol_lookup_uses_word_boundaries(): void
    {
        Instrument::factory()->create(['symbol' => 'KO', 'name' => 'Coca-Cola']);
        Cache::flush();

        $classifier = new NewsClassifier;

        $this->assertSame([], $classifier->classify('韓股黑色星期二 KOPSI 崩近 11%', '')['symbols']);
        $this->assertContains('KO', $classifier->classify('KO reports quarterly earnings', '')['symbols']);
    }

    // --- 符號字典 ---

    /** instruments 表的公司名必須能被辨識，這是 config 那 12 檔種子清單的擴充來源。 */
    public function test_dictionary_includes_names_from_the_instruments_table(): void
    {
        Instrument::factory()->create(['symbol' => '2408.TW', 'name' => '南亞科']);
        Cache::flush();

        $result = (new NewsClassifier)->classify('南亞科跌停鎖死', '記憶體族群重挫');

        $this->assertContains('2408.TW', $result['symbols']);
    }

    /** config 的手維護別名優先，不得被 instruments 的同名條目覆蓋。 */
    public function test_config_entries_take_precedence_over_instruments(): void
    {
        Instrument::factory()->create(['symbol' => 'WRONG.TW', 'name' => '台積電']);
        Cache::flush();

        $this->assertSame('2330.TW', (new SymbolDictionary)->all()['台積電']);
    }

    /** instrument 的 symbol 本身也要能比對，讓新聞直接寫代號時命中。 */
    public function test_dictionary_matches_the_symbol_itself(): void
    {
        Instrument::factory()->create(['symbol' => '3231.TW', 'name' => '緯創']);
        Cache::flush();

        $this->assertArrayHasKey('3231.tw', (new SymbolDictionary)->all());
    }

    /**
     * forget() 會清掉快取。
     *
     * 這裡用 query builder 直接寫入以繞過 Instrument 的 model event——正常
     * 路徑建立 instrument 時字典會自動失效（見
     * NewsRelevanceAndDictionaryInvalidationTest），那條路徑測不到 forget() 本身。
     */
    public function test_forget_clears_the_cached_dictionary(): void
    {
        $dictionary = new SymbolDictionary;
        Cache::flush();
        $dictionary->all();

        Instrument::query()->insert([
            'symbol' => '6669.TW',
            'name' => '緯穎',
            'market' => 'TW',
            'asset_type' => 'stock',
            'currency' => 'TWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey('緯穎', $dictionary->all(), '快取未失效前不應看到新標的。');

        $dictionary->forget();
        $this->assertArrayHasKey('緯穎', $dictionary->all());
    }
}
