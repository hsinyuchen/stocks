<?php

namespace Tests\Feature\News;

use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\News\NewsClassifier;
use App\Services\News\NewsIngestionService;
use App\Services\News\SymbolDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NewsRelevanceAndDictionaryInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(string $title, bool $relevant, string $source = 'Test'): NewsItem
    {
        return NewsItem::create([
            'source' => $source,
            'title' => $title,
            'summary' => '',
            'url' => 'https://x.test/'.md5($title),
            'url_hash' => sha1($title),
            'published_at' => now()->subHour(),
            'language' => 'zh-TW',
            'market' => 'TW',
            'domain' => $relevant ? 'market' : 'other',
            'relevant' => $relevant,
            'related_symbols' => [],
        ]);
    }

    // --- relevant 套用於 /news ---

    public function test_news_list_hides_irrelevant_items_by_default(): void
    {
        $this->makeItem('台積電法說會釋出樂觀展望', true);
        $this->makeItem('西雅圖美食節槍擊案疑幫派械鬥', false);

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.title', '台積電法說會釋出樂觀展望'));
    }

    /** 保留人工檢查誤判的出口，否則被誤標的新聞就永遠看不到了。 */
    public function test_include_irrelevant_flag_shows_everything(): void
    {
        $this->makeItem('台積電法說會釋出樂觀展望', true);
        $this->makeItem('西雅圖美食節槍擊案疑幫派械鬥', false);

        $this->actingAs(User::factory()->create())
            ->get('/news?include_irrelevant=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 2)
                ->where('filters.include_irrelevant', true));
    }

    /** 篩選選項不得列出只存在於雜訊新聞中的值，否則選了會得到空清單。 */
    public function test_facets_only_reflect_relevant_items(): void
    {
        $this->makeItem('台積電法說會', true, '經濟日報');
        $this->makeItem('退休阿公的美食日記', false, '某內容農場');

        $this->actingAs(User::factory()->create())
            ->get('/news')
            ->assertInertia(function (Assert $page): void {
                $sources = $page->toArray()['props']['facets']['sources'];

                $this->assertContains('經濟日報', $sources);
                $this->assertNotContains('某內容農場', $sources);
            });
    }

    // --- 字典快取失效 ---

    /**
     * 使用者搜尋新股票會建立 instrument，但字典有 60 分鐘快取。沒有自動失效
     * 的話，該檔在快取到期前都不會被新聞辨識出來。
     */
    public function test_creating_an_instrument_invalidates_the_symbol_dictionary(): void
    {
        $classifier = new NewsClassifier;

        // 先讓字典建立快取（此時尚無「聯詠」）。
        $this->assertSame([], $classifier->classify('聯詠營收創高', '')['symbols']);

        Instrument::factory()->create(['symbol' => '3034.TW', 'name' => '聯詠']);

        $this->assertContains('3034.TW', $classifier->classify('聯詠營收創高', '')['symbols']);
    }

    /** 名稱補正（provider 先用 symbol 當名稱、之後補正式名稱）同樣要失效。 */
    public function test_renaming_an_instrument_invalidates_the_dictionary(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '6669.TW', 'name' => '6669.TW']);
        $classifier = new NewsClassifier;

        $this->assertSame([], $classifier->classify('緯穎伺服器出貨暢旺', '')['symbols']);

        $instrument->update(['name' => '緯穎']);

        $this->assertContains('6669.TW', $classifier->classify('緯穎伺服器出貨暢旺', '')['symbols']);
    }

    /** 無關欄位變更不應清快取，否則每次寫入都退化成全表重建。 */
    public function test_unrelated_update_does_not_invalidate_the_dictionary(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2454.TW', 'name' => '聯發科']);
        $dictionary = app(SymbolDictionary::class);
        $dictionary->all();

        // 直接改 DB，繞過 model event——若快取仍在，讀到的會是舊值。
        Instrument::query()->whereKey($instrument->id)->update(['name' => '改名了']);
        $instrument->update(['exchange' => 'TWSE']);

        $this->assertSame('2454.TW', $dictionary->all()['聯發科'] ?? null);
    }

    // --- 封鎖清單涵蓋度 ---

    /** 母品牌與子品牌都要被擋：實測寫子品牌會漏掉母品牌 63 筆。 */
    public function test_blocklist_covers_both_parent_and_sub_brands(): void
    {
        $service = app(NewsIngestionService::class);

        $this->assertTrue($service->isBlocked('CMoney'));
        $this->assertTrue($service->isBlocked('CMoney投資網誌'));
        $this->assertTrue($service->isBlocked('TheStreet'));
        $this->assertTrue($service->isBlocked('thestreet.com'));
    }

    /** 正規媒體不得被誤擋。 */
    public function test_blocklist_does_not_catch_reputable_outlets(): void
    {
        $service = app(NewsIngestionService::class);

        foreach (['Reuters', 'Bloomberg', '工商時報', '中央社 CNA', 'DIGITIMES', 'Morningstar', 'BeInCrypto'] as $source) {
            $this->assertFalse($service->isBlocked($source), "{$source} 不應被封鎖");
        }
    }
}
