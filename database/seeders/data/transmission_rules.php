<?php

/**
 * 題材傳導規則的內建種子。
 *
 * 這份資料只負責**首次 bootstrap**。規則進入資料庫之後，管理員可在 /admin/topics
 * 編輯；`TransmissionRuleSeeder` 以 key 做 firstOrCreate、永不 update，避免
 * 重跑 db:seed 洗掉人工策展成果。
 *
 * 因此：**日後修正這個檔案不會進到正式站**。既有規則的資料修正一律寫具名
 * data migration 或專用 artisan 指令，不要期待 db:seed 會套用。
 *
 * curator_note 是這次搬遷才新增的欄位，內容取自搬遷前 config/news.php 的行內
 * 註解——那些註解記錄了決策依據（例如 rate_policy 為何全填 neutral），
 * 不搬進 DB 的話，管理頁上任何人都會把「刻意留白」當成「漏填」。
 */

return [
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
        'curator_note' => 'sectors 的方向以「報價上漲」為基準；跌價時整組翻轉。只提到「記憶體」而未指明漲跌者判為 unknown，一律降為中性——「長鑫擴產 DRAM 現貨價下滑」不該被標成記憶體正向。',
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
            [
                'name' => '封測',
                'direction' => 'positive',
                'symbols' => ['3711.TW'],
                'curator_note' => '只留 3711（日月光投控）。2311 與 2325 於 2026-07-09 後即無交易資料（實測 60 日窗口內僅 6 個交易日，而 3711 為 60 日），掛在這裡會讓使用者點進一檔沒有行情的標的。',
            ],
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
        'curator_note' => '所有 sector 方向刻意全為 neutral，且刻意不設 direction_cues。本規則只負責「偵測到利率事件、指出相關板塊」，方向一律由實際殖利率走勢決定（config(\'rates.transmission\') 與 RatesRegimeService）。原因：關鍵字只能猜方向，殖利率是事實。舊版把「升息」判為金融正向，但升息若伴隨曲線平坦化（熊平），銀行利差反而收窄，結論相反；而這個錯誤結論會同時進到 UI 與 LLM prompt。',
        'when' => [
            'keywords' => ['升息', '降息', 'rate cut', 'rate hike', 'fomc', '通膨', 'inflation', 'cpi', '殖利率', 'yield'],
            'domains' => ['finance'],
        ],
        // 刻意不設 direction_cues，且所有 sector 方向為 neutral：本規則只負責
        // 「偵測到利率事件、指出相關板塊」，方向一律由實際殖利率走勢決定
        // （config('rates.transmission') 與 RatesRegimeService）。
        //
        // 原因：關鍵字只能猜方向，殖利率是事實。舊版把「升息」判為金融正向，
        // 但升息若伴隨曲線平坦化（熊平），銀行利差反而收窄，結論相反；而
        // 這個錯誤結論會同時進到 UI 與 LLM prompt。
        'chain' => [
            '政策利率預期改變，公債殖利率同向調整',
            '折現率變動，長天期成長股評價敏感度最高',
            '方向與強度以實際殖利率環境為準，見利率環境區塊',
        ],
        'sectors' => [
            ['name' => '金融（利差與投資收益）', 'direction' => 'neutral', 'symbols' => ['2881.TW', '2882.TW', 'JPM']],
            ['name' => '長天期成長股（評價對利率敏感）', 'direction' => 'neutral', 'symbols' => ['TSLA', 'NVDA']],
        ],
    ],
    [
        // 由 news:transmission-gaps 找出：熊本強震相關詞在未覆蓋新聞中排名
        // 極前（熊本 44、強震 33、疏散 18），而熊本正是台積電 JASM 與 Sony
        // 影像感測器廠所在地，屬半導體供應鏈重鎮。
        'key' => 'natural_disaster',
        'label' => '天災與供應鏈中斷',
        'curator_note' => '由 news:transmission-gaps 找出：熊本強震相關詞在未覆蓋新聞中排名極前（熊本 44、強震 33、疏散 18），而熊本正是台積電 JASM 與 Sony 影像感測器廠所在地，屬半導體供應鏈重鎮。',
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
            [
                'name' => '半導體製造（設廠當地）',
                'direction' => 'negative',
                'symbols' => ['2330.TW'],
                'curator_note' => '方向依「災區是否為其產能所在」而定，非單一結論：台積電在熊本有 JASM，日本強震對它是負向。',
            ],
            [
                'name' => '被動元件（轉單）',
                'direction' => 'positive',
                'symbols' => ['2327.TW', '2492.TW'],
                'curator_note' => '與「半導體製造」相反方向是刻意的：被動元件的日系供應鏈（村田、TDK）受阻時，台系同業是轉單受惠。',
            ],
            ['name' => '航運與物流', 'direction' => 'positive', 'symbols' => ['2603.TW', '2609.TW']],
        ],
    ],
    [
        // 未覆蓋高頻詞中「台股 43／股市 37／重挫 14／韓股 12」佔比顯著。
        // 這類系統性下跌本身就是事件，其傳導路徑與個別產業新聞不同。
        'key' => 'market_shock',
        'label' => '大盤系統性震盪',
        'curator_note' => '未覆蓋高頻詞中「台股 43／股市 37／重挫 14／韓股 12」佔比顯著。這類系統性下跌本身就是事件，其傳導路徑與個別產業新聞不同。',
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
        'curator_note' => 'sectors 的方向以「新台幣貶值」為基準；升值時整組翻轉。',
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
];
