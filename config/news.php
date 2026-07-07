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
    */

    'retention_days' => (int) env('NEWS_RETENTION_DAYS', 30),

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

    /*
    |--------------------------------------------------------------------------
    | Feeds
    |--------------------------------------------------------------------------
    |
    | Curated, reliable feed list (Hermes blacklist applied). Each entry:
    | key, name, url, market (TW|US|INTL), language.
    |
    */

    'feeds' => [
        ['key' => 'cnbc', 'name' => 'CNBC', 'url' => 'https://www.cnbc.com/id/100003114/device/rss/rss.html', 'market' => 'US', 'language' => 'en'],
        ['key' => 'yahoo_us', 'name' => 'Yahoo Finance', 'url' => 'https://finance.yahoo.com/news/rssindex', 'market' => 'US', 'language' => 'en'],
        ['key' => 'wsj_markets', 'name' => 'WSJ Markets', 'url' => 'https://feeds.a.dj.com/rss/RSSMarketsMain.xml', 'market' => 'US', 'language' => 'en'],
        ['key' => 'fox_markets', 'name' => 'Fox Business Markets', 'url' => 'https://moxie.foxbusiness.com/google-publisher/markets.xml', 'market' => 'US', 'language' => 'en'],
        ['key' => 'nikkei_asia', 'name' => 'Nikkei Asia', 'url' => 'https://asia.nikkei.com/rss/feed/nar', 'market' => 'INTL', 'language' => 'en'],
        ['key' => 'udn_money', 'name' => '經濟日報', 'url' => 'https://money.udn.com/rssfeed/news/1001/5588', 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'ltn_business', 'name' => '自由財經', 'url' => 'https://news.ltn.com.tw/rss/business.xml', 'market' => 'TW', 'language' => 'zh-TW'],
        ['key' => 'yahoo_tw', 'name' => 'Yahoo 股市(台)', 'url' => 'https://tw.stock.yahoo.com/rss?category=tw-market', 'market' => 'TW', 'language' => 'zh-TW'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain Keyword Rules
    |--------------------------------------------------------------------------
    |
    | Lowercased title+summary is matched against these keyword lists. First
    | matching domain wins; no match falls back to "other". Items are never
    | discarded based on domain.
    |
    */

    'domains' => [
        'tech' => ['半導體', '晶片', '台積電', '人工智慧', '資料中心', '伺服器', 'capex', 'semiconductor', 'chip', 'nvidia', 'ai ', 'data center', 'foundry'],
        'defense' => ['國防', '軍工', '飛彈', '地緣', '戰爭', '制裁', 'defense', 'military', 'missile', 'geopolit', 'sanction', 'tariff', '關稅'],
        'finance' => ['央行', '升息', '降息', '通膨', '利率', 'fed', 'federal reserve', 'cpi', 'inflation', 'rate cut', 'rate hike', 'yield', 'bond'],
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
