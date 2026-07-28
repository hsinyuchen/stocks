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
        ['key' => 'aljazeera', 'name' => 'Al Jazeera', 'url' => 'https://www.aljazeera.com/xml/rss/all.xml', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'guardian_business', 'name' => 'Guardian Business', 'url' => 'https://www.theguardian.com/uk/business/rss', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'nyt_business', 'name' => 'NYT Business', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/Business.xml', 'market' => 'US', 'language' => 'en'],

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
        // 投資內容農場／個股推薦文工廠。
        // 注意子字串方向：寫 'CMoney' 才能同時擋掉「CMoney」與「CMoney投資網誌」；
        // 反過來寫子品牌會漏掉母品牌（實測母品牌 63 筆全部漏網）。
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

    /*
    |--------------------------------------------------------------------------
    | 傳導鏈：事件 → 產業 → 個股
    |--------------------------------------------------------------------------
    |
    | 新聞分析原本止於「這則新聞屬於哪個領域」，但投資判斷需要的是下一步：
    | 這個事件會透過什麼路徑影響哪些產業，以及該產業有哪些代表個股。
    |
    | 每條規則：
    |   when.keywords  觸發關鍵字（任一命中即可，比對規則同 domains：
    |                  ASCII 走詞邊界、CJK 走子字串）
    |   when.domains   額外限制須命中的領域（留空代表不限）
    |   chain          傳導路徑的文字描述，逐段說明因果
    |   sectors        受影響板塊，direction 為 positive / negative
    |
    | direction 是「該事件對此板塊的方向性」，不是買賣建議，也不保證後續走勢。
    |
    | 代表個股清單為人工維護，用意是提供觀察起點而非完整覆蓋。新增或調整前
    | 請自行確認代號正確，本檔不會驗證代號是否存在於 instruments。
    |
    */

    'transmission' => [
        [
            'key' => 'hormuz_oil',
            'label' => '中東衝突／荷莫茲海峽',
            'when' => [
                'keywords' => ['荷莫茲', 'hormuz', '伊朗', 'iran', '油輪', 'tanker', '中東', 'middle east'],
                'domains' => ['geopolitics', 'energy'],
            ],
            'chain' => [
                '油運咽喉受威脅，原油與天然氣價格上行',
                '海運運費與戰爭保險費同步走高',
                '能源與航運營收受惠；航空、塑化、運輸成本上升',
            ],
            'sectors' => [
                ['name' => '航運', 'direction' => 'positive', 'symbols' => ['2603.TW', '2609.TW', '2615.TW']],
                ['name' => '石油與天然氣', 'direction' => 'positive', 'symbols' => ['XOM', 'CVX']],
                ['name' => '航空', 'direction' => 'negative', 'symbols' => ['2610.TW', '2618.TW']],
                ['name' => '塑化', 'direction' => 'negative', 'symbols' => ['1301.TW', '1303.TW']],
            ],
        ],
        [
            'key' => 'chip_export_control',
            'label' => '半導體出口管制／關稅',
            'when' => [
                'keywords' => ['出口管制', 'export control', '關稅', 'tariff', '晶片法案', 'chip act', '實體清單', 'entity list'],
                'domains' => ['geopolitics'],
            ],
            'chain' => [
                '先進製程設備與晶片跨境流動受限',
                '客戶提前拉貨或轉單，短期出貨波動放大',
                '代工與 IC 設計承壓；本土替代與設備在地化受惠',
            ],
            'sectors' => [
                ['name' => '晶圓代工', 'direction' => 'negative', 'symbols' => ['2330.TW', '2303.TW']],
                ['name' => 'IC 設計', 'direction' => 'negative', 'symbols' => ['2454.TW']],
                ['name' => '半導體設備', 'direction' => 'negative', 'symbols' => ['ASML', 'AMAT']],
            ],
        ],
        [
            'key' => 'memory_cycle',
            'label' => '記憶體價格與產能',
            'when' => [
                'keywords' => ['記憶體', 'dram', 'nand', '快閃', '長鑫', 'cxmt', '美光', 'micron'],
                'domains' => [],
            ],
            // sectors 的方向以「報價上漲」為基準；跌價時整組翻轉。
            // 只提到「記憶體」而未指明漲跌者判為 unknown，一律降為中性——
            // 「長鑫擴產 DRAM 現貨價下滑」不該被標成記憶體正向。
            'direction_cues' => [
                'forward' => ['漲價', '調漲', '報價上揚', '缺貨', '供不應求', 'price hike', 'shortage'],
                'reverse' => ['跌價', '下滑', '走跌', '殺價', '庫存去化', '供過於求', 'price drop', 'oversupply', 'glut'],
            ],
            'chain' => [
                '記憶體現貨與合約價變動',
                '模組與封測稼動率跟隨調整',
                '記憶體廠獲利彈性最大；下游組裝的料件成本反向變動',
            ],
            'sectors' => [
                ['name' => '記憶體', 'direction' => 'positive', 'symbols' => ['2408.TW', '2344.TW', 'MU']],
                // 只留 3711（日月光投控）。2311 與 2325 於 2026-07-09 後即無交易
                // 資料（實測 60 日窗口內僅 6 個交易日，而 3711 為 60 日），
                // 掛在這裡會讓使用者點進一檔沒有行情的標的。
                ['name' => '封測', 'direction' => 'positive', 'symbols' => ['3711.TW']],
            ],
        ],
        [
            'key' => 'ai_capex',
            'label' => 'AI 資料中心資本支出',
            'when' => [
                'keywords' => ['資料中心', 'data center', '人工智慧', 'ai', 'gpu', 'cowos', '伺服器', 'server', '雲端資本支出', 'capex'],
                'domains' => ['tech'],
            ],
            'chain' => [
                '雲端業者上修資本支出',
                'AI 伺服器與加速器訂單增加，先進封裝產能吃緊',
                '晶片、代工、伺服器組裝、散熱與電源依序受惠',
            ],
            'sectors' => [
                ['name' => 'AI 晶片', 'direction' => 'positive', 'symbols' => ['NVDA', 'AMD', 'AVGO']],
                ['name' => '晶圓代工與封裝', 'direction' => 'positive', 'symbols' => ['2330.TW']],
                ['name' => '伺服器組裝', 'direction' => 'positive', 'symbols' => ['2317.TW', '3231.TW', '2382.TW']],
            ],
        ],
        [
            'key' => 'rate_policy',
            'label' => '利率與貨幣政策',
            'when' => [
                'keywords' => ['升息', '降息', 'rate cut', 'rate hike', 'fomc', '通膨', 'inflation', 'cpi', '殖利率', 'yield'],
                'domains' => ['finance'],
            ],
            // sectors 的方向以「升息」為基準；降息時整組翻轉。
            'direction_cues' => [
                'forward' => ['升息', '緊縮', 'rate hike', 'hawkish', 'tightening'],
                'reverse' => ['降息', '寬鬆', 'rate cut', 'dovish', 'easing'],
            ],
            'chain' => [
                '政策利率預期改變，公債殖利率同向調整',
                '折現率變動，長天期成長股評價敏感度最高',
                '銀行利差與壽險投資收益反向於降息；高負債與高評價族群反向於升息',
            ],
            'sectors' => [
                // 方向以「升息」為基準：金融受惠於利差擴大，長天期成長股受折現率
                // 上升壓抑。降息時兩者反向，UI 的方向標示需搭配新聞本身判讀。
                ['name' => '金融（利差與壽險投資收益）', 'direction' => 'positive', 'symbols' => ['2881.TW', '2882.TW', 'JPM']],
                // 這裡列的是「長天期高評價」這個特性的概念代表，不是嚴謹的產業
                // 分類——挑美股是因為該特性在美股表現得最純粹。台股對利率的直接
                // 反應主要在上方金融股，故不另列台股成長股以免誤導。
                ['name' => '長天期成長股（評價對利率敏感）', 'direction' => 'negative', 'symbols' => ['TSLA', 'NVDA']],
            ],
        ],
        [
            // 由 news:transmission-gaps 找出：熊本強震相關詞在未覆蓋新聞中排名
            // 極前（熊本 44、強震 33、疏散 18），而熊本正是台積電 JASM 與 Sony
            // 影像感測器廠所在地，屬半導體供應鏈重鎮。
            'key' => 'natural_disaster',
            'label' => '天災與供應鏈中斷',
            'when' => [
                'keywords' => [
                    '地震', '強震', '海嘯', '颱風', '停電', '斷電', '洪水', '火災', '疏散',
                    'earthquake', 'tsunami', 'typhoon', 'hurricane', 'blackout', 'power outage', 'flood',
                ],
                'domains' => [],
            ],
            'chain' => [
                '生產基地或港口停擺，產線與物流中斷',
                '關鍵零組件與材料交期拉長，客戶轉單或提前備貨',
                '當地設廠者受損；具替代產能者短期受惠',
            ],
            'sectors' => [
                // 方向依「災區是否為其產能所在」而定，非單一結論：
                // 台積電在熊本有 JASM，日本強震對它是負向；被動元件的日系
                // 供應鏈（村田、TDK）受阻時，台系同業則是轉單受惠。
                ['name' => '半導體製造（設廠當地）', 'direction' => 'negative', 'symbols' => ['2330.TW']],
                ['name' => '被動元件（轉單）', 'direction' => 'positive', 'symbols' => ['2327.TW', '2492.TW']],
                ['name' => '航運與物流', 'direction' => 'positive', 'symbols' => ['2603.TW', '2609.TW']],
            ],
        ],
        [
            // 未覆蓋高頻詞中「台股 43／股市 37／重挫 14／韓股 12」佔比顯著。
            // 這類系統性下跌本身就是事件，其傳導路徑與個別產業新聞不同。
            'key' => 'market_shock',
            'label' => '大盤系統性震盪',
            'when' => [
                'keywords' => [
                    '重挫', '崩跌', '暴跌', '大跌', '恐慌', '融資追繳', '斷頭', '違約交割',
                    'selloff', 'sell-off', 'rout', 'plunge', 'crash', 'margin call', 'correction',
                ],
                'domains' => ['market'],
            ],
            'chain' => [
                '指數大幅下跌，風險偏好收縮',
                'ETF 贖回與融資追繳引發被動賣壓',
                '權值股與高融資個股跌幅放大；防禦性類股相對抗跌',
            ],
            'sectors' => [
                ['name' => '權值股', 'direction' => 'negative', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                ['name' => '金融', 'direction' => 'negative', 'symbols' => ['2881.TW', '2882.TW']],
            ],
        ],
        // 已移除 earnings_season 規則。
        //
        // 它原本是為了吃下未覆蓋詞中的 earnings（排名 28）而加，但財報事件不
        // 指向任何特定產業——受影響的是誰完全取決於是誰的財報，硬填 2330／
        // 2454／NVDA 只是讓每則財報新聞都掛上同一組權值股，方向還只能標中性。
        // 這正是「為了湊覆蓋率寫出牽強的因果」，比沒有規則更誤導。
        //
        // 財報要真正接進傳導鏈，需要的是「發布財報的公司 → 其供應鏈位置」的
        // 對照，而不是關鍵字規則。
        [
            'key' => 'twd_fx',
            'label' => '新台幣匯率',
            'when' => [
                'keywords' => ['新台幣', '台幣', '匯率', '貶值', '升值', 'usdtwd'],
                'domains' => ['currency'],
            ],
            // sectors 的方向以「新台幣貶值」為基準；升值時整組翻轉。
            'direction_cues' => [
                'forward' => ['貶值', '走貶', '重貶', 'depreciat', 'weaken'],
                'reverse' => ['升值', '走升', '強升', 'appreciat', 'strengthen'],
            ],
            'chain' => [
                '新台幣兌美元變動',
                '以美元計價的出口收入換回台幣的金額改變',
                '電子出口商匯兌損益直接反映；進口原料成本反向變動',
            ],
            'sectors' => [
                ['name' => '電子出口', 'direction' => 'positive', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                ['name' => '航空與進口', 'direction' => 'negative', 'symbols' => ['2610.TW', '2618.TW']],
            ],
        ],
    ],

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
        ],
        'defense' => [
            '國防', '軍工', '飛彈', '軍事', '國艦國造', '無人機',
            'defense', 'defence', 'military', 'missile', 'warship', 'fighter jet', 'drone',
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
        ],
        'currency' => [
            '匯率', '新台幣', '美元', '日圓', '人民幣', '貶值', '升值',
            'exchange rate', 'dollar index', 'yen', 'yuan', 'currency', 'forex',
        ],
        'supply_chain' => [
            '供應鏈', '產能', '缺貨', '庫存', '港口', '物流', '轉單', '斷鏈',
            'supply chain', 'shortage', 'inventory', 'logistics', 'port', 'capacity',
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

    'irrelevant' => [
        '美食', '餐廳', '旅遊', '景點', '娛樂', '明星', '藝人', '球賽', '運動賽事',
        '星座', '命理', '寵物', '食譜', '減肥', '養生', '婚姻', '感情', '育兒',
        '槍擊', '車禍', '命案', '竊盜', '詐騙集團', '天氣', '颱風假',
        '熱浪', '遊行', '脫軌', '愛孫', '存摺', '避暑', '手環', '長者', '獨居',
        '量販店', '超市', '飲料', '益生', '高蛋白', '民調', '婚禮', '喪禮',
        'recipe', 'celebrity', 'sports', 'horoscope', 'travel guide',
        'heatwave', 'parade', 'protein', 'dairy',
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
