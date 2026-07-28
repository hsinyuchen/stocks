<?php

namespace App\Services\News;

/**
 * Rule-based classifier: assigns a domain and related stock symbols to a news
 * item using keyword lists and a company-name dictionary from config/news.php.
 *
 * Pure and side-effect free; no DB, no HTTP.
 */
class NewsClassifier
{
    public function __construct(private readonly SymbolDictionary $dictionary = new SymbolDictionary) {}

    /**
     * @return array{domain: string, domains: list<string>, symbols: list<string>, relevant: bool}
     */
    public function classify(string $title, string $summary): array
    {
        $haystack = strtolower(trim($title.' '.$summary));

        $domains = $this->domains($haystack);
        $symbols = $this->symbols($title.' '.$summary, $haystack);

        return [
            // domain 保留為單一主領域：既有 news_items.domain 欄位、前端篩選與
            // 既有分析資料都依賴它，語意不變（多領域時取第一個命中者）。
            'domain' => $domains === [] ? 'other' : $domains[0],
            'domains' => $domains,
            'symbols' => $symbols,
            'relevant' => $this->isRelevant($domains, $symbols),
        ];
    }

    /**
     * 所有命中的領域，依 config 宣告順序。
     *
     * 舊版在第一個命中就 return，導致「對半導體加徵關稅」只會標成 tech，
     * geopolitics 訊號消失——而跨領域新聞往往影響最大。
     *
     * @return list<string>
     */
    private function domains(string $haystack): array
    {
        /** @var array<string, list<string>> $domains */
        $domains = (array) config('news.domains', []);
        $matched = [];

        foreach ($domains as $domain => $keywords) {
            foreach ((array) $keywords as $keyword) {
                if ($this->matches($haystack, (string) $keyword)) {
                    $matched[] = (string) $domain;

                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * 對外公開比對規則，供 TransmissionMapper 共用。
     *
     * 兩處若各自實作，詞邊界這類細節必然分歧——分類器已經因為子字串比對踩過
     * war/software、KO/KOPSI 的坑，不該讓傳導鏈再踩一次。
     */
    public function matchesKeyword(string $haystack, string $keyword): bool
    {
        return $this->matches($haystack, $keyword);
    }

    /**
     * 關鍵字比對：ASCII 關鍵字用詞邊界（含複數），CJK 用子字串。
     *
     * 純子字串比對在英文會大量誤判，且都是安靜地錯：
     *   war → software、nato → senator、coup → couple、dram → drama、
     *   port → sports、ai → air/aid/aim
     *
     * 但純詞邊界又會漏掉複數，而新聞標題大量使用複數形：實測
     * 「Trump's Section 301 Tariffs」對不上關鍵字 tariff，
     * 「targeting drone makers」對不上 drone。因此允許結尾的 s / es。
     * 這仍受詞邊界保護——tariffs 會命中，software 不會命中 war。
     *
     * 中文沒有詞邊界也沒有複數變化，維持子字串比對。
     */
    private function matches(string $haystack, string $keyword): bool
    {
        $keyword = strtolower(trim($keyword));

        if ($keyword === '') {
            return false;
        }

        // 含非 ASCII（中日文）→ 無詞邊界可言，只能子字串。
        if (preg_match('/[^\x00-\x7F]/', $keyword) === 1) {
            return str_contains($haystack, $keyword);
        }

        return preg_match('/\b'.preg_quote($keyword, '/').'(?:es|s)?\b/u', $haystack) === 1;
    }

    /**
     * 是否與投資相關。
     *
     * 需要正向訊號：命中任一領域或任一關聯個股才算相關。
     *
     * 舊版反過來——只有「無領域」且「無個股」且「命中排除關鍵字」三者同時成立
     * 才判為不相關，等於黑名單。實測 1035 則新聞有 99% 被標為相關，其中包含
     * 世界盃、影劇、社會案件與旅遊報導：綜合媒體（BBC、NYT、Guardian、
     * Al Jazeera）的 Business 版本身就混雜大量非財經內容，靠一份排除詞表追不完。
     *
     * 代價是關鍵字表的覆蓋率直接決定召回率，漏收的詞會變成誤殺。因此改動同時
     * 補齊了 finance / market 的常用詞，並讓官方監理機關來源免判定。
     *
     * 原本搭配的 news.irrelevant 排除詞表一併移除：它唯一的作用是把「無訊號」
     * 判為不相關，而那已是現在的預設。改成硬性覆蓋則會誤殺複合標題——
     * 「台積電法說會後美食街人潮回流」是真訊號，不該因為出現「美食」被丟掉。
     *
     * @param  list<string>  $domains
     * @param  list<string>  $symbols
     */
    private function isRelevant(array $domains, array $symbols): bool
    {
        return $domains !== [] || $symbols !== [];
    }

    /**
     * Explicit ticker regex (TW + US) filtered against known symbols, plus
     * company-name dictionary matches. De-duplicated, canonical form preserved.
     *
     * @return list<string>
     */
    private function symbols(string $original, string $haystack): array
    {
        // 字典 = config 種子清單 ∪ instruments 表（見 SymbolDictionary）。
        // 只靠 config 的 12 檔會讓南亞科、華邦電、聯電、台達電這類常見標的
        // 永遠抓不到；instruments 隨使用者搜尋與自選股自然成長。
        $dictionary = $this->dictionary->all();

        $canonical = array_values(array_unique(array_map('strval', array_values($dictionary))));
        $knownTwBare = $this->knownTaiwanBareCodes($canonical);

        $symbols = [];

        // 1. Company-name dictionary matches（大小寫不敏感，ASCII 走詞邊界）。
        //
        // 這裡與 domain 判定用同一套比對規則：純子字串會讓英文代號與名稱大量
        // 誤配，且錯得很安靜。實測案例：「韓股黑色星期二 KOPSI 崩近 11%」被判成
        // 含 KO（可口可樂），因為 "kopsi" 內含 "ko"。誤判的後果是新聞被掛到
        // 不相關的個股上，直接汙染使用者的個股新聞頁。
        foreach ($dictionary as $name => $symbol) {
            if ($this->matches($haystack, (string) $name)) {
                $symbols[] = (string) $symbol;
            }
        }

        // 2. Explicit Taiwan tickers: \b\d{4}(\.TWO?)? .
        if (preg_match_all('/\b(\d{4})(\.TWO?)?\b/i', $original, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $code = $match[1];
                $suffix = isset($match[2]) ? strtoupper($match[2]) : '';

                if ($suffix !== '') {
                    // Suffix present -> normalize to canonical .TW/.TWO form.
                    $symbols[] = $code.$suffix;
                } elseif (isset($knownTwBare[$code])) {
                    // Bare 4-digit kept only if it is a known TW symbol.
                    $symbols[] = $knownTwBare[$code];
                }
            }
        }

        // 3. Explicit US tickers: \b[A-Z]{1,5}\b kept only if in the dictionary.
        if (preg_match_all('/\b[A-Z]{1,5}\b/', $original, $usMatches)) {
            $canonicalIndex = array_flip($canonical);
            foreach ($usMatches[0] as $candidate) {
                if (isset($canonicalIndex[$candidate])) {
                    $symbols[] = $candidate;
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Map of bare 4-digit code => canonical TW symbol, from dictionary values
     * that look like Taiwan tickers (e.g. "2330.TW" => ["2330" => "2330.TW"]).
     *
     * @param  list<string>  $canonical
     * @return array<string, string>
     */
    private function knownTaiwanBareCodes(array $canonical): array
    {
        $map = [];

        foreach ($canonical as $symbol) {
            if (preg_match('/^(\d{4})\.TWO?$/i', $symbol, $m)) {
                $map[$m[1]] = $symbol;
            }
        }

        return $map;
    }
}
