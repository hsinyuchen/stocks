<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest Schedule
    |--------------------------------------------------------------------------
    |
    | The news stream is intentionally not real-time. The ingestion command
    | runs at each of these wall-clock times in the given timezone.
    |
    */

    'schedule' => [
        'times' => ['08:00', '14:30', '22:00'],
        'timezone' => 'Asia/Taipei',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Items older than this many days are pruned during ingestion.
    |
    | 保留 90 天。依實測約 300 則/日推算，穩定後約 2.7 萬筆、20 MB 上下。
    |
    | 取捨要講明：舊新聞不進任何分析 prompt，縮短保留期不影響當前判讀。但過期
    | 就再也抓不回來——各家 RSS 只暴露最近的窗口，這是不可逆的資料流失。若日後
    | 要做「事件當時市場怎麼報導」的回顧或訊號回測，缺的那段補不回來。
    |
    */

    'retention_days' => (int) env('NEWS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Per-feed HTTP timeout (seconds) used when fetching a feed.
    |
    */

    'http_timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Dashboard Freshness Window
    |--------------------------------------------------------------------------
    |
    | When the dashboard is opened (first entry of a session) and the newest
    | stored item is older than this many minutes, a live ingest runs before
    | the page is assembled. The "更新最新資料" button always forces an ingest.
    |
    */

    'dashboard_freshness_minutes' => (int) env('NEWS_DASHBOARD_FRESHNESS_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Symbol News（個股新聞）
    |--------------------------------------------------------------------------
    |
    | 個股頁載入時依 symbol 抓 Google News RSS。timeout 較一般 feed 短，
    | 因為在頁面請求內同步執行；freshness 為每 symbol 的抓取節流窗口。
    |
    */

    'symbol_http_timeout' => (int) env('NEWS_SYMBOL_HTTP_TIMEOUT', 8),
    'symbol_freshness_minutes' => (int) env('NEWS_SYMBOL_FRESHNESS_MINUTES', 60),

    // 符號字典（config ∪ instruments）的快取時效。instruments 增長不快，
    // 長 TTL 即可；新增 instrument 後可呼叫 SymbolDictionary::forget() 立即失效。
    'symbol_dictionary_ttl_minutes' => (int) env('NEWS_SYMBOL_DICTIONARY_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Feeds
    |--------------------------------------------------------------------------
    |
    | Curated, reliable feed list (Hermes blacklist applied). Each entry:
    | key, name, url, market (TW|US|INTL), language.
    |
    | 清單於 2026-07-28 逐一實測（HTTP 狀態、可解析性、項目數、最新項目時間），
    | 只保留當時能回傳新鮮內容者。已移除：
    |   - WSJ Markets：回 HTTP 200 但最新項目為 547 天前，插入即被 prune。
    |   - Nikkei Asia：回 HTTP 200 但零項目。
    | 兩者皆非「抓取失敗」，因此舊的 try/catch 監控完全無法發現，DB 中長期 0 筆。
    | 同批實測中確認不可用而未納入：AP（401）、Reuters（404）、US Treasury（404）、
    | DigiTimes（404）、DW Business（0 項）、鉅亨網（連線失敗）。
    |
    | 新增 feed 前請先實測，勿憑記憶填 URL。
    */

    'feeds' => [
        // 國際綜合財經
        ['key' => 'cnbc', 'name' => 'CNBC', 'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html', 'market' => 'US', 'language' => 'en'],
        ['key' => 'cnbc_world', 'name' => 'CNBC World', 'url' => 'https://www.cnbc.com/id/100727362/device/rss/rss.html', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'cnbc_economy', 'name' => 'CNBC Economy', 'url' => 'https://www.cnbc.com/id/20910258/device/rss/rss.html', 'market' => 'US', 'language' => 'en'],
        ['key' => 'yahoo_us', 'name' => 'Yahoo Finance', 'url' => 'https://finance.yahoo.com/news/rssindex', 'market' => 'US', 'language' => 'en'],
        ['key' => 'marketwatch', 'name' => 'MarketWatch', 'url' => 'https://feeds.content.dowjones.io/public/rss/mw_topstories', 'market' => 'US', 'language' => 'en'],
        ['key' => 'ft_home', 'name' => 'Financial Times', 'url' => 'https://www.ft.com/rss/home', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'fox_markets', 'name' => 'Fox Business Markets', 'url' => 'https://moxie.foxbusiness.com/google-publisher/markets.xml', 'market' => 'US', 'language' => 'en'],

        // 國際新聞與地緣政治
        ['key' => 'bbc_business', 'name' => 'BBC Business', 'url' => 'https://feeds.bbci.co.uk/news/business/rss.xml', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'bbc_world', 'name' => 'BBC World', 'url' => 'https://feeds.bbci.co.uk/news/world/rss.xml', 'market' => 'INTL', 'language' => 'en'],

        // 官方一手來源：發布頻率低但屬事實而非報導，品質高於媒體轉述。
        // 低頻是常態，health.stale_runs_threshold 對這類來源會較寬鬆（見 health 設定）。
        ['key' => 'fed_press', 'name' => 'Federal Reserve', 'url' => 'https://www.federalreserve.gov/feeds/press_all.xml', 'market' => 'US', 'language' => 'en', 'low_frequency' => true],
        ['key' => 'sec_press', 'name' => 'SEC', 'url' => 'https://www.sec.gov/news/pressreleases.rss', 'market' => 'US', 'language' => 'en', 'low_frequency' => true],
        ['key' => 'eia_energy', 'name' => 'EIA', 'url' => 'https://www.eia.gov/rss/todayinenergy.xml', 'market' => 'INTL', 'language' => 'en', 'low_frequency' => true],
        ['key' => 'ecb_press', 'name' => 'ECB', 'url' => 'https://www.ecb.europa.eu/rss/press.html', 'market' => 'INTL', 'language' => 'en', 'low_frequency' => true],

        // 科技與半導體
        ['key' => 'cnbc_tech', 'name' => 'CNBC Tech', 'url' => 'https://www.cnbc.com/id/19854910/device/rss/rss.html', 'market' => 'US', 'language' => 'en'],
        ['key' => 'techcrunch', 'name' => 'TechCrunch', 'url' => 'https://techcrunch.com/feed/', 'market' => 'US', 'language' => 'en'],
        ['key' => 'toms_hardware', 'name' => "Tom's Hardware", 'url' => 'https://www.tomshardware.com/feeds/all', 'market' => 'US', 'language' => 'en'],
        ['key' => 'ars_technica', 'name' => 'Ars Technica', 'url' => 'https://feeds.arstechnica.com/arstechnica/index', 'market' => 'US', 'language' => 'en'],

        // 國防與軍工
        ['key' => 'defense_news', 'name' => 'Defense News', 'url' => 'https://www.defensenews.com/arc/outboundfeeds/rss/', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'breaking_defense', 'name' => 'Breaking Defense', 'url' => 'https://breakingdefense.com/feed/', 'market' => 'INTL', 'language' => 'en'],

        // 台灣
        ['key' => 'udn_money', 'name' => '經濟日報', 'url' => 'https://money.udn.com/rssfeed/news/1001/5588', 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'ltn_business', 'name' => '自由財經', 'url' => 'https://news.ltn.com.tw/rss/business.xml', 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'yahoo_tw', 'name' => 'Yahoo 股市(台)', 'url' => 'https://tw.stock.yahoo.com/rss?category=tw-market', 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'cna_finance', 'name' => '中央社財經', 'url' => 'https://feeds.feedburner.com/rsscna/finance', 'market' => 'TW', 'language' => 'zh-TW'],

        // 鉅亨網沒有 RSS（/rss/news/cat/headline、/rss/cat/headline、舊 cnyes-cdn
        // media RSS 實測皆 404），改用站方公開 JSON API。回傳附結構化的 market
        // 陣列，個股代號由上游直接提供，不必靠關鍵字猜。
        ['key' => 'cnyes_headline', 'name' => '鉅亨網', 'driver' => 'cnyes', 'category' => 'headline', 'limit' => 30, 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'cnyes_tw_stock', 'name' => '鉅亨網台股', 'driver' => 'cnyes', 'category' => 'tw_stock', 'limit' => 30, 'market' => 'TW', 'language' => 'zh-TW'],

        // YouTube 頻道。官方 feeds/videos.xml 就是標準 Atom，RssNewsProvider
        // 直接可解析——純 PHP，不需要 Python，共享主機可跑。影片描述在
        // media:group/media:description，由 atomSummary() 取出。
        // 逐字稿需要 Python（youtube-transcript-api），共享主機無法執行，
        // 因此正式環境只取標題與描述；逐字稿為選配增強，見 config/youtube.php。
        ['key' => 'yt_cnbc', 'name' => 'CNBC（影片）', 'url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCvJJ_dzjViJCoLf5uKUTwoA', 'market' => 'US', 'language' => 'en'],
        ['key' => 'yt_bloomberg', 'name' => 'Bloomberg（影片）', 'url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCIALMKvObZNtJ6AmdCLP7Lg', 'market' => 'US', 'language' => 'en'],
        ['key' => 'yt_yahoo_finance', 'name' => 'Yahoo Finance（影片）', 'url' => 'https://www.youtube.com/feeds/videos.xml?channel_id=UCEAZeUIeJs0IjQiqTCdVSIg', 'market' => 'US', 'language' => 'en'],

        // Google Finance 本身沒有 RSS 或公開 API（實測 /finance/beta 回 200 但零
        // 項目，頁面也沒有 RSS 宣告），它是純前端應用。最接近的可用替代是
        // Google News 的財經查詢——同一個 Google 新聞索引，只是換個入口。
        //
        // 代價是 Google News 會帶進內容農場，因此 blocked_sources 對這幾個 feed
        // 特別重要。
        ['key' => 'gnews_markets', 'name' => 'Google News 市場', 'url' => 'https://news.google.com/rss/search?q=stock+market+OR+earnings+when:1d&hl=en-US&gl=US&ceid=US:en', 'site' => 'https://www.google.com/finance/beta', 'market' => 'US', 'language' => 'en'],
        ['key' => 'gnews_tw', 'name' => 'Google News 財經(台)', 'url' => 'https://news.google.com/rss/search?q=%E8%B2%A1%E7%B6%93+OR+%E8%82%A1%E5%B8%82+when:1d&hl=zh-TW&gl=TW&ceid=TW:zh-Hant', 'site' => 'https://www.google.com/finance/beta', 'market' => 'TW', 'language' => 'zh-TW'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 來源封鎖清單
    |--------------------------------------------------------------------------
    |
    | Google News（全站財經查詢與個股新聞）會把內容農場一併帶進共用新聞流：
    | 實測有 96 個非白名單來源、702 筆，其中不乏投資內容農場與生活理財網站。
    | 這些與 CNBC、FT 混在同一張表且權重相同，會稀釋分析品質。
    |
    | 比對方式為來源名稱的子字串（不分大小寫），命中即整筆不入庫。
    |
    */

    'blocked_sources' => [
        // 注意子字串方向：寫 'CMoney' 才能同時擋掉「CMoney」與「CMoney投資網誌」；
        // 反過來寫子品牌會漏掉母品牌（實測母品牌 63 筆全部漏網）。同理 'Guardian'
        // 一併涵蓋「Guardian Business」與 Google News 的「The Guardian」。

        // 綜合新聞媒體的財經版：本身混雜大量非財經內容（體育、影劇、社會案件），
        // 靠關鍵字分類追不完。實測這三家佔被過濾雜訊的四成。同時列在這裡而非
        // 只移除 feed，是因為 Google News 會用原始媒體名把同樣的文章送進來。
        'Al Jazeera',
        'Guardian',
        'New York Times',
        'NYT Business',

        // 投資內容農場／個股推薦文工廠。
        'Insider Monkey',
        'CMoney',
        'Motley Fool',
        'Yahoo Personal Finance',
        '富聯網',
        '24/7 Wall St',
        '旺得富',
        'TheStreet',
        'thestreet.com',
        'Argus Research',
        'Moneywise',
        'Barchart',
        'MarketBeat',
        'Trefis',
        'Proactive',
        'CryptoProwl',
        'Simply Wall St',
        'simplywall.st',
        'StockInvest.us',
        'Zacks',
        '玩股網',
        '商周財富網',
        '優分析',
        '富果直送',
        '豐雲學堂',
        '方格子',
        'Mobile01',

        // 非財經垂直媒體：GlobalData 系列的產業快訊與生活／汽車頻道，
        // 對台美股分析沒有可用訊號，卻會佔用新聞流與 LLM 成本。
        'Just Style',
        'Just Auto',
        'Just Drinks',
        'Packaging Gateway',
        'Pharmaceutical Technology',
        'Offshore Technology',
        'Retail Insight Network',
        'Retail Banker International',
        'Private Banker International',
        'The Accountant',
        'POWER Magazine',
        'Yahoo - 汽機車',
        'U-CAR',
        '自由電子報汽車頻道',
        '上下游',
        'University of Chicago News',
        'Times of Suriname',
        'The Daily Times',
        'News On 6',
        'The Twelfth Magpie',

        // 綜合電視台：財經報導多為當日盤勢轉述，深度不足且與既有台灣來源重疊。
        // 注意子字串會一併涵蓋同集團的財經頻道（例：'東森' 也擋東森財經新聞）。
        'TVBS',
        '三立',
        '東森',
        '民視',
        '台視',
        '中視',
        '華視',
        '公視',
        '非凡',

        // 週刊與八卦媒體：偶有獨家，但雜訊比例高且常為轉載。
        '鏡週刊',
        '鏡新聞',
        '鏡報',
        '壹蘋',
        '知新聞',

        // 券商與投資平台自營內容：行銷導向，非新聞。
        'sinotrade',
        'capitalfutures',
        'Moomoo',
        'PChome',

        // 聚合器：內容來自其他來源，入庫只會產生重複。
        'LINE TODAY',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feed 健康度
    |--------------------------------------------------------------------------
    |
    | 判定依據是「本次抓到的新鮮項目數」，不是 HTTP 狀態或項目數——死掉的 feed
    | 可能照樣回 200 與滿滿的陳年項目（實測 WSJ 為 547 天前）。連續多次沒有新鮮
    | 項目才告警，避免單日無新聞就誤報。官方低頻來源（low_frequency）本來就可能
    | 數日無更新，門檻另計。
    |
    */

    'health' => [
        'stale_runs_threshold' => (int) env('NEWS_FEED_STALE_RUNS', 6),

        // 「新鮮」的定義刻意與 retention_days 脫鉤。
        //
        // 早期版本用保留窗口當判定基準，在 retention 只有 30 天時剛好堪用；
        // 但保留窗口是可調的（曾設為一年），拉長後就失效——停止更新兩百天的 feed 仍會被算成有新鮮
        // 內容。保留窗口回答「要存多久」，健康度回答「上游還活著嗎」，是兩件事。
        'fresh_within_days' => (int) env('NEWS_FEED_FRESH_WITHIN_DAYS', 7),

        // 標記 low_frequency 的官方來源（Fed、SEC、ECB、EIA）數天才發布一次。
        // 套用一般門檻會誤報：以每日三次排程與 6 次門檻計，正常的低頻來源約
        // 兩天就會被標成失效。
        'low_frequency_stale_runs' => (int) env('NEWS_FEED_LOW_FREQ_STALE_RUNS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | 空間回收
    |--------------------------------------------------------------------------
    |
    | SQLite 的 DELETE 不會縮小資料庫檔案：釋放的頁面保留給後續寫入重用，檔案
    | 大小停在歷史高點。穩定運行時這沒問題（會自然停在高原），但縮短保留期或
    | 大批清除之後，空間不會還給磁碟。
    |
    | 因此在單次 prune 刪除量超過門檻時才執行 VACUUM。VACUUM 會重寫整個檔案並
    | 鎖定資料庫，不適合每次抓取都跑——日常 prune 只會刪掉個位數到數十筆。
    |
    | 設為 0 可停用自動回收（改以人工在維護窗口執行）。
    |
    */

    'vacuum_after_pruned' => (int) env('NEWS_VACUUM_AFTER_PRUNED', 500),

    // 傳導規則已搬進資料庫（transmission_rules / transmission_sectors），
    // 由 App\Contracts\TransmissionRuleProvider 供應，內建 8 組見
    // database/seeders/data/transmission_rules.php。管理頁：/admin/topics。

    /*
    |--------------------------------------------------------------------------
    | Domain Keyword Rules
    |--------------------------------------------------------------------------
    |
    | Lowercased title+summary is matched against these keyword lists. Items are
    | never discarded based on domain.
    |
    | 多標籤：一則新聞可同時屬於多個領域（例：對半導體加徵關稅＝tech＋geopolitics）。
    | 舊版為單標籤且「先中先贏」，跨領域新聞只會留下第一個命中的領域，而跨領域
    | 恰恰是影響最大的那類。
    |
    | 關鍵字以實際未分類樣本回填。原清單漏掉的實例：
    |   「Iran hosts Hormuz calls」→ 缺 iran / hormuz
    |   「Oil extends losses」→ 缺 oil / 油價
    |   「記憶體也逃難！華邦電、南亞科跌停」→ tech 缺「記憶體」
    |   「長鑫掛牌添壓力」→ 缺 dram / 長鑫
    */

    'domains' => [
        'tech' => [
            '半導體', '晶片', '台積電', '人工智慧', '資料中心', '伺服器', 'capex',
            '記憶體', '快閃', '面板', '封測', '晶圓', '製程', '奈米', '散熱', '機器人',
            '雲端', '軟體', '手機', '電動車', '自駕',
            'semiconductor', 'chip', 'nvidia', 'ai', 'data center', 'foundry',
            'dram', 'nand', 'memory', 'wafer', 'lithography', 'euv', 'gpu', 'tsmc',
            'asml', 'robot', 'cloud', 'software', 'quantum',
            // 製程與供應鏈用詞：DUV 與 EUV 同樣是設備管制焦點，先前只收了 EUV。
            'duv', 'packaging', 'advanced packaging', 'hbm', 'chipmaking', 'fab',
        ],
        'defense' => [
            '國防', '軍工', '飛彈', '軍事', '國艦國造', '無人機',
            'defense', 'defence', 'military', 'missile', 'warship', 'fighter jet', 'drone',
            // 軍購新聞多半只出現機構名或裝備名，不會出現「defense」這個詞。
            'pentagon', 'rocket', 'munition', 'artillery', 'radar', 'submarine', 'aircraft',
            '五角大廈', '國防部', '軍購', '標案', '戰機', '潛艦',
        ],
        'geopolitics' => [
            '地緣', '戰爭', '制裁', '關稅', '外交', '衝突', '停火', '和談', '出口管制',
            '伊朗', '以色列', '烏克蘭', '俄羅斯', '中東', '北約', '台海', '南海', '荷莫茲',
            '空襲', '恐攻', '政變', '大選', '選舉', '川普', '白宮', '國會', '北韓',
            '阿富汗', '巴基斯坦', '敘利亞', '葉門',
            'geopolit', 'sanction', 'tariff', 'ceasefire', 'war', 'conflict', 'export control',
            'iran', 'israel', 'ukraine', 'russia', 'nato', 'hormuz', 'taiwan strait', 'trade war',
            'airstrike', 'election', 'white house', 'congress', 'coup', 'north korea',
        ],
        'energy' => [
            '油價', '原油', '天然氣', '能源', '電價', '核電', '再生能源', '減產',
            'oil', 'crude', 'natural gas', 'opec', 'energy', 'lng', 'refinery', 'barrel',
        ],
        'finance' => [
            '央行', '升息', '降息', '通膨', '利率', '公債', '殖利率', '衰退', '就業數據',
            'fed', 'federal reserve', 'cpi', 'inflation', 'rate cut', 'rate hike', 'yield',
            'bond', 'recession', 'ecb', 'payroll', 'gdp', 'treasury',
            // 央行決策文件與信用環境：FOMC 會議紀要、ECB 放款調查等官方發布
            // 先前完全比對不到，而那是總經新聞裡資訊量最高的一類。
            'fomc', 'federal open market', 'monetary policy', 'basis point',
            'lending', 'credit condition', 'jobless', 'unemployment',
            '會議紀要', '貨幣政策', '放款', '信貸', '失業率', '基點', '財政',
            // 金融業本身：銀行、保險、支付先前完全沒有對應詞。
            'bank', 'banking', 'insurance', 'fintech', 'payment', 'mortgage',
            '銀行', '金融', '保險', '支付', '房貸', '債務',
        ],
        'currency' => [
            '匯率', '新台幣', '美元', '日圓', '人民幣', '貶值', '升值',
            'exchange rate', 'dollar index', 'yen', 'yuan', 'currency', 'forex',
        ],
        'supply_chain' => [
            '供應鏈', '產能', '缺貨', '庫存', '港口', '物流', '轉單', '斷鏈',
            'supply chain', 'shortage', 'inventory', 'logistics', 'port', 'capacity',
            // 運輸與天災：荷莫茲、紅海、強震都會直接打到供應鏈與運價，先前只有
            // transmission 規則收了這些詞，domains 沒有，導致新聞被判為不相關。
            'shipping', 'tanker', 'freight', 'red sea', 'houthi', 'earthquake',
            '航運', '油輪', '運價', '紅海', '地震', '強震', '斷料', '停工',
            // 在手訂單與交期是製造業最直接的營運指標。
            'backlog', 'lead time', 'order book', 'production line',
            '訂單', '在手訂單', '交機', '交期', '產線',
        ],

        // market 刻意排在最後：它涵蓋面最廣（幾乎所有財經新聞都提到股價或大盤），
        // 放在前面會讓它吃掉所有主領域。排最後代表「有更具體的領域就用具體的，
        // 都沒有才歸為一般市場新聞」，同時把原本大量落在 other 的個股／盤勢新聞
        // 收進來——那些不是無法分類，只是先前沒有對應的領域。
        'market' => [
            '焦點股', '除息', '除權', '法說', '財報', '營收', '目標價', '分析師',
            '大盤', '台股', '美股', '港股', '陸股', '日股', '漲停', '跌停', '融資',
            '法人', '外資', '投信', '自營商', '股價', '個股', '上市', '掛牌', '增資',
            '本益比', '殖利率', '配息', '庫藏股', '財富自由', '基金', 'etf',
            'earnings', 'analyst', 'stock', 'shares', 'ipo', 'dividend', 'buyback',
            'guidance', 'wall street', 'nasdaq', 's&p 500', 'dow jones', 'valuation',
            // 「財經」「經濟」本身沒被收錄，導致大量標題（東協財經、財經快訊）
            // 落在無領域而被判為不相關。
            '財經', '經濟', '股市', '指數', '恒指', '恒生', '道瓊', '那斯達克',
            '半年報', '季報', '財測', '獲利', '虧損', '成交量', '報酬率', '定存',
            'economy', 'economic', 'forecast', 'outlook', 'revenue', 'profit',
            'quarterly', 'market cap', 'index', 'hang seng', 'equities', 'investor',
            // 公司事件：裁員與併購是最常見的個股題材，兩者都沒被收錄。
            'layoff', 'merger', 'acquisition', 'restructuring', 'bankruptcy',
            // 英文裁員有多種說法，只收 layoff 會漏掉「GSK to cut jobs」這類標題。
            'job cut', 'cut jobs', 'cost-cutting', 'cost cutting', 'divest', 'spin-off',
            '裁員', '併購', '收購', '重整', '破產', '下市', '減資',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 非投資相關關鍵字
    |--------------------------------------------------------------------------
    |
    | 僅在「完全沒有命中任何領域，且沒有關聯個股」時才用來判定不相關——
    | 有領域或個股訊號的新聞一律保留，寧可留雜訊也不要漏掉真訊號。
    |
    | 實際混入的例子：西雅圖美食節槍擊案、台灣美食飄香全球、退休阿公、包租公
    | 避坑指南。這些會被塞進每日總結的 LLM prompt，稀釋訊號並增加成本。
    |
    */

    /*
     * 官方監理與統計機關：發布內容本質上全屬總經，一律視為相關。
     *
     * 這些 feed 是人工挑選的官方來源，發文量低且全是政策文件、統計與新聞稿。
     * 靠關鍵字碰運氣會漏掉最重要的一類——實測「Minutes of the FOMC」與
     * 「Survey on the Access to Finance of Enterprises」都比對不到任何領域。
     *
     * 比對的是入庫的 source 名稱，與 blocked_sources 同一套判定方式。
     */
    'always_relevant_sources' => [
        'Federal Reserve', 'ECB', 'SEC', 'EIA',
    ],

    /*
    |--------------------------------------------------------------------------
    | Company-Name -> Symbol Dictionary
    |--------------------------------------------------------------------------
    |
    | Case-insensitive name matches resolve to a canonical symbol. Seed list,
    | expandable later. Keys may be Chinese or English names.
    |
    */

    'symbols' => [
        '台積電' => '2330.TW', 'tsmc' => '2330.TW',
        '聯發科' => '2454.TW', 'mediatek' => '2454.TW',
        '鴻海' => '2317.TW', 'foxconn' => '2317.TW',
        '輝達' => 'NVDA', 'nvidia' => 'NVDA',
        '蘋果' => 'AAPL', 'apple' => 'AAPL',
        '特斯拉' => 'TSLA', 'tesla' => 'TSLA',
        '微軟' => 'MSFT', 'microsoft' => 'MSFT',
        '超微' => 'AMD', 'amd' => 'AMD',
        '谷歌' => 'GOOGL', 'google' => 'GOOGL',
        '亞馬遜' => 'AMZN', 'amazon' => 'AMZN',
        '美光' => 'MU', 'micron' => 'MU',
        '博通' => 'AVGO', 'broadcom' => 'AVGO',
    ],

];
