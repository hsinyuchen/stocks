<?php

/**
 * 搬遷前 config('news.transmission') 的凍結快照。
 *
 * 平價測試拿它當基準，驗證「種子資料檔 → 資料庫 → DbTransmissionRuleProvider」
 * 這條來回沒有掉東西或改變形狀。刻意不從種子資料檔推導：種子檔含 curator_note
 * 等管理欄位，而 provider 不輸出它們，兩者不可能逐鍵相等；而且從被測物推導出
 * 的期望值驗證不了任何事。
 *
 * 這份快照不隨管理員的編輯更新。日後若有意變更內建規則，要一併更新這裡並在
 * commit 說明原因。
 */

return [
    0 => [
        'key' => 'hormuz_oil',
        'label' => '中東衝突／荷莫茲海峽',
        'when' => [
            'keywords' => [
                0 => '荷莫茲',
                1 => 'hormuz',
                2 => '伊朗',
                3 => 'iran',
                4 => '油輪',
                5 => 'tanker',
                6 => '中東',
                7 => 'middle east',
            ],
            'domains' => [
                0 => 'geopolitics',
                1 => 'energy',
            ],
        ],
        'chain' => [
            0 => '油運咽喉受威脅，原油與天然氣價格上行',
            1 => '海運運費與戰爭保險費同步走高',
            2 => '能源與航運營收受惠；航空、塑化、運輸成本上升',
        ],
        'sectors' => [
            0 => [
                'name' => '航運',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2603.TW',
                    1 => '2609.TW',
                    2 => '2615.TW',
                ],
            ],
            1 => [
                'name' => '石油與天然氣',
                'direction' => 'positive',
                'symbols' => [
                    0 => 'XOM',
                    1 => 'CVX',
                ],
            ],
            2 => [
                'name' => '航空',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2610.TW',
                    1 => '2618.TW',
                ],
            ],
            3 => [
                'name' => '塑化',
                'direction' => 'negative',
                'symbols' => [
                    0 => '1301.TW',
                    1 => '1303.TW',
                ],
            ],
        ],
    ],
    1 => [
        'key' => 'chip_export_control',
        'label' => '半導體出口管制／關稅',
        'when' => [
            'keywords' => [
                0 => '出口管制',
                1 => 'export control',
                2 => '關稅',
                3 => 'tariff',
                4 => '晶片法案',
                5 => 'chip act',
                6 => '實體清單',
                7 => 'entity list',
            ],
            'domains' => [
                0 => 'geopolitics',
            ],
        ],
        'chain' => [
            0 => '先進製程設備與晶片跨境流動受限',
            1 => '客戶提前拉貨或轉單，短期出貨波動放大',
            2 => '代工與 IC 設計承壓；本土替代與設備在地化受惠',
        ],
        'sectors' => [
            0 => [
                'name' => '晶圓代工',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2330.TW',
                    1 => '2303.TW',
                ],
            ],
            1 => [
                'name' => 'IC 設計',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2454.TW',
                ],
            ],
            2 => [
                'name' => '半導體設備',
                'direction' => 'negative',
                'symbols' => [
                    0 => 'ASML',
                    1 => 'AMAT',
                ],
            ],
        ],
    ],
    2 => [
        'key' => 'memory_cycle',
        'label' => '記憶體價格與產能',
        'when' => [
            'keywords' => [
                0 => '記憶體',
                1 => 'dram',
                2 => 'nand',
                3 => '快閃',
                4 => '長鑫',
                5 => 'cxmt',
                6 => '美光',
                7 => 'micron',
            ],
            'domains' => [
            ],
        ],
        'direction_cues' => [
            'forward' => [
                0 => '漲價',
                1 => '調漲',
                2 => '報價上揚',
                3 => '缺貨',
                4 => '供不應求',
                5 => 'price hike',
                6 => 'shortage',
            ],
            'reverse' => [
                0 => '跌價',
                1 => '下滑',
                2 => '走跌',
                3 => '殺價',
                4 => '庫存去化',
                5 => '供過於求',
                6 => 'price drop',
                7 => 'oversupply',
                8 => 'glut',
            ],
        ],
        'chain' => [
            0 => '記憶體現貨與合約價變動',
            1 => '模組與封測稼動率跟隨調整',
            2 => '記憶體廠獲利彈性最大；下游組裝的料件成本反向變動',
        ],
        'sectors' => [
            0 => [
                'name' => '記憶體',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2408.TW',
                    1 => '2344.TW',
                    2 => 'MU',
                ],
            ],
            1 => [
                'name' => '封測',
                'direction' => 'positive',
                'symbols' => [
                    0 => '3711.TW',
                ],
            ],
        ],
    ],
    3 => [
        'key' => 'ai_capex',
        'label' => 'AI 資料中心資本支出',
        'when' => [
            'keywords' => [
                0 => '資料中心',
                1 => 'data center',
                2 => '人工智慧',
                3 => 'ai',
                4 => 'gpu',
                5 => 'cowos',
                6 => '伺服器',
                7 => 'server',
                8 => '雲端資本支出',
                9 => 'capex',
            ],
            'domains' => [
                0 => 'tech',
            ],
        ],
        'chain' => [
            0 => '雲端業者上修資本支出',
            1 => 'AI 伺服器與加速器訂單增加，先進封裝產能吃緊',
            2 => '晶片、代工、伺服器組裝、散熱與電源依序受惠',
        ],
        'sectors' => [
            0 => [
                'name' => 'AI 晶片',
                'direction' => 'positive',
                'symbols' => [
                    0 => 'NVDA',
                    1 => 'AMD',
                    2 => 'AVGO',
                ],
            ],
            1 => [
                'name' => '晶圓代工與封裝',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2330.TW',
                ],
            ],
            2 => [
                'name' => '伺服器組裝',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2317.TW',
                    1 => '3231.TW',
                    2 => '2382.TW',
                ],
            ],
        ],
    ],
    4 => [
        'key' => 'rate_policy',
        'label' => '利率與貨幣政策',
        'when' => [
            'keywords' => [
                0 => '升息',
                1 => '降息',
                2 => 'rate cut',
                3 => 'rate hike',
                4 => 'fomc',
                5 => '通膨',
                6 => 'inflation',
                7 => 'cpi',
                8 => '殖利率',
                9 => 'yield',
            ],
            'domains' => [
                0 => 'finance',
            ],
        ],
        'chain' => [
            0 => '政策利率預期改變，公債殖利率同向調整',
            1 => '折現率變動，長天期成長股評價敏感度最高',
            2 => '方向與強度以實際殖利率環境為準，見利率環境區塊',
        ],
        'sectors' => [
            0 => [
                'name' => '金融（利差與投資收益）',
                'direction' => 'neutral',
                'symbols' => [
                    0 => '2881.TW',
                    1 => '2882.TW',
                    2 => 'JPM',
                ],
            ],
            1 => [
                'name' => '長天期成長股（評價對利率敏感）',
                'direction' => 'neutral',
                'symbols' => [
                    0 => 'TSLA',
                    1 => 'NVDA',
                ],
            ],
        ],
    ],
    5 => [
        'key' => 'natural_disaster',
        'label' => '天災與供應鏈中斷',
        'when' => [
            'keywords' => [
                0 => '地震',
                1 => '強震',
                2 => '海嘯',
                3 => '颱風',
                4 => '停電',
                5 => '斷電',
                6 => '洪水',
                7 => '火災',
                8 => '疏散',
                9 => 'earthquake',
                10 => 'tsunami',
                11 => 'typhoon',
                12 => 'hurricane',
                13 => 'blackout',
                14 => 'power outage',
                15 => 'flood',
            ],
            'domains' => [
            ],
        ],
        'chain' => [
            0 => '生產基地或港口停擺，產線與物流中斷',
            1 => '關鍵零組件與材料交期拉長，客戶轉單或提前備貨',
            2 => '當地設廠者受損；具替代產能者短期受惠',
        ],
        'sectors' => [
            0 => [
                'name' => '半導體製造（設廠當地）',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2330.TW',
                ],
            ],
            1 => [
                'name' => '被動元件（轉單）',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2327.TW',
                    1 => '2492.TW',
                ],
            ],
            2 => [
                'name' => '航運與物流',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2603.TW',
                    1 => '2609.TW',
                ],
            ],
        ],
    ],
    6 => [
        'key' => 'market_shock',
        'label' => '大盤系統性震盪',
        'when' => [
            'keywords' => [
                0 => '重挫',
                1 => '崩跌',
                2 => '暴跌',
                3 => '大跌',
                4 => '恐慌',
                5 => '融資追繳',
                6 => '斷頭',
                7 => '違約交割',
                8 => 'selloff',
                9 => 'sell-off',
                10 => 'rout',
                11 => 'plunge',
                12 => 'crash',
                13 => 'margin call',
                14 => 'correction',
            ],
            'domains' => [
                0 => 'market',
            ],
        ],
        'chain' => [
            0 => '指數大幅下跌，風險偏好收縮',
            1 => 'ETF 贖回與融資追繳引發被動賣壓',
            2 => '權值股與高融資個股跌幅放大；防禦性類股相對抗跌',
        ],
        'sectors' => [
            0 => [
                'name' => '權值股',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2330.TW',
                    1 => '2317.TW',
                    2 => '2454.TW',
                ],
            ],
            1 => [
                'name' => '金融',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2881.TW',
                    1 => '2882.TW',
                ],
            ],
        ],
    ],
    7 => [
        'key' => 'twd_fx',
        'label' => '新台幣匯率',
        'when' => [
            'keywords' => [
                0 => '新台幣',
                1 => '台幣',
                2 => '匯率',
                3 => '貶值',
                4 => '升值',
                5 => 'usdtwd',
            ],
            'domains' => [
                0 => 'currency',
            ],
        ],
        'direction_cues' => [
            'forward' => [
                0 => '貶值',
                1 => '走貶',
                2 => '重貶',
                3 => 'depreciat',
                4 => 'weaken',
            ],
            'reverse' => [
                0 => '升值',
                1 => '走升',
                2 => '強升',
                3 => 'appreciat',
                4 => 'strengthen',
            ],
        ],
        'chain' => [
            0 => '新台幣兌美元變動',
            1 => '以美元計價的出口收入換回台幣的金額改變',
            2 => '電子出口商匯兌損益直接反映；進口原料成本反向變動',
        ],
        'sectors' => [
            0 => [
                'name' => '電子出口',
                'direction' => 'positive',
                'symbols' => [
                    0 => '2330.TW',
                    1 => '2317.TW',
                    2 => '2454.TW',
                ],
            ],
            1 => [
                'name' => '航空與進口',
                'direction' => 'negative',
                'symbols' => [
                    0 => '2610.TW',
                    1 => '2618.TW',
                ],
            ],
        ],
    ],
];
