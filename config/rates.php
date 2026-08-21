<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 美債利率體系
    |--------------------------------------------------------------------------
    |
    | 三層：曲線資料 → 環境判定（牛熊陡平四象限）→ 板塊傳導。
    | 設計文件見 docs/superpowers/specs/2026-08-20-us-treasury-rates-regime-design.md。
    |
    */

    /*
     * 天期清單。source 目前只有 'market'（Yahoo chart，走 YieldCurveProvider）。
     *
     * 預留擴充：補 5y(^FVX)/30y(^TYX) 即可做四點曲線形狀；補 2y 需 source='fred'
     * 與對應實作（Yahoo 無 2Y 免費代號，10Y-2Y 是市場常引用的倒掛標準）。
     * 新增天期不需要改任何程式碼，判定層只依賴下方 spread 指定的兩個 key。
     */
    'tenors' => [
        '10y' => ['symbol' => '^TNX', 'source' => 'market', 'label' => '美債 10 年'],
        '3m' => ['symbol' => '^IRX', 'source' => 'market', 'label' => '美債 3 個月'],
    ],

    /*
     * 利差組合。10Y-3M 是紐約聯準銀官方衰退機率模型採用的組合，學術上預測力
     * 強於媒體常引用的 10Y-2Y，且 Yahoo 免費可取得。
     */
    'spread' => [
        'long' => '10y',
        'short' => '3m',
    ],

    /*
     * 取用日線根數。需同時滿足最長窗口（60）與倒掛回看（60 個交易日），
     * 另留緩衝。調整窗口或回看時務必同步調大，否則長窗口會永遠回 null。
     */
    'history_days' => 130,

    /*
     * 判定窗口與門檻（bp）。
     *
     * 門檻取各窗口「Δ 絕對值的中位數」，樣本為 ^TNX 與 ^IRX 各 400 根日線
     * （約 19 個月，量測日 2026-08-20）：
     *   20d  |Δ10Y| p50=10.4  |Δ利差| p50=9.7
     *   60d  |Δ10Y| p50=16.2  |Δ利差| p50=13.0
     *
     * 警告：此樣本涵蓋特定利率環境，利率體制劇變後門檻可能失準，應定期重新量測。
     * 判定採嚴格大於：Δ 剛好等於門檻視為中性（保守側）。
     */
    'windows' => [
        '20d' => ['days' => 20, 'level_bp' => 10.0, 'shape_bp' => 10.0],
        '60d' => ['days' => 60, 'level_bp' => 16.0, 'shape_bp' => 13.0],
    ],

    /*
     * 主窗口：傳導表與大盤翻空的利率維度只吃這個窗口（戰術判定）。
     * 另一個窗口僅作戰略背景敘述，不參與規則命中——否則兩窗口分歧時會同時
     * 命中方向相反的規則。
     */
    'primary_window' => '20d',

    /* 倒掛回看的交易日數，用於判定 recently_uninverted（近期曾倒掛、現已轉正）。 */
    'inversion_lookback_days' => 60,

    /* 有資料時的快取分鐘數；抓不到時用較短的失敗節流，避免每次開頁重打上游。 */
    'cache_minutes' => (int) env('RATES_CACHE_MINUTES', 60),
    'failure_cache_minutes' => (int) env('RATES_FAILURE_CACHE_MINUTES', 5),

    /*
     * 利率環境 → 板塊 → 個股的傳導表。
     *
     * 解析為 first-match 串聯（象限 > level > shape），每個市場最多命中一條方向
     * 規則。倒掛規則獨立附加。
     *
     * 每個 sector 必須填 why：這逼規則作者交代機制，也讓 UI 與 prompt 有東西可
     * 引用，而不是只丟一個方向。direction 可為 positive / negative / mixed；
     * mixed 代表機制上雙向，不得壓成單一方向。
     *
     * 這是規則不是真理：板塊反應在不同升息階段可能反向，銀行是典型（見熊陡與
     * 熊平的相反結論）。conviction 分級即為此而設。
     */
    'transmission' => [

        /*
         * 美股：折現率直接作用，板塊輪動分化明確。
         */
        'us' => [
            [
                'key' => 'us_bear_steepening',
                'when' => ['quadrant' => 'bear_steepening'],
                'conviction' => 'high',
                'chain' => [
                    '期限溢價與通膨預期上升',
                    '長端折現率上升幅度大於短端',
                    '長久期資產評價壓力最重，銀行利差同時擴大',
                ],
                'sectors' => [
                    ['name' => '銀行', 'direction' => 'positive', 'why' => '借短貸長，淨利差隨曲線陡峭化擴大', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '長天期成長股', 'direction' => 'negative', 'why' => '折現率上升，現金流集中在遠期的高評價標的受壓最重', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => 'REITs', 'direction' => 'negative', 'why' => '融資成本上升，且相對股息吸引力被無風險殖利率壓縮', 'symbols' => ['O', 'VNQ']],
                    ['name' => '公用事業', 'direction' => 'negative', 'why' => '債券替代性質，殖利率上行時資金流出且負債成本上升', 'symbols' => ['XLU']],
                    ['name' => '能源與原物料', 'direction' => 'positive', 'why' => '熊陡常伴隨通膨預期升溫，實體資產受惠', 'symbols' => ['XOM', 'CVX']],
                ],
            ],
            [
                'key' => 'us_bear_flattening',
                'when' => ['quadrant' => 'bear_flattening'],
                'conviction' => 'high',
                'chain' => [
                    '政策緊縮預期推升短端幅度大於長端',
                    '曲線收窄，銀行存放利差被壓縮',
                    '折現率仍上升，同時經濟放緩預期升溫',
                ],
                'sectors' => [
                    // 與熊陡同為殖利率上行，但對銀行的結論相反——這是採四象限的核心理由。
                    ['name' => '銀行', 'direction' => 'negative', 'why' => '短端資金成本上升快於長端放款收益，淨利差收窄', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '長天期成長股', 'direction' => 'negative', 'why' => '折現率上升壓抑遠期現金流評價', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => '小型股與高負債', 'direction' => 'negative', 'why' => '再融資成本上升，且對經濟放緩最敏感', 'symbols' => ['IWM']],
                    ['name' => '大型防禦與必需消費', 'direction' => 'positive', 'why' => '現金流穩定、負債比低，緊縮末期相對抗跌', 'symbols' => ['XLP']],
                ],
            ],
            [
                'key' => 'us_bull_steepening',
                'when' => ['quadrant' => 'bull_steepening'],
                'conviction' => 'high',
                'chain' => [
                    '降息預期使短端下行幅度大於長端',
                    '折現率下降，長久期資產評價修復',
                    '但陡峭化也可能是衰退定價，需與信用利差併看',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'direction' => 'positive', 'why' => '折現率下降，遠期現金流評價回升', 'symbols' => ['NVDA', 'TSLA']],
                    ['name' => 'REITs', 'direction' => 'positive', 'why' => '融資成本下降，且相對股息吸引力回升', 'symbols' => ['O', 'VNQ']],
                    ['name' => '小型股', 'direction' => 'positive', 'why' => '再融資壓力緩解，對降息彈性最大', 'symbols' => ['IWM']],
                    // 牛陡對銀行是兩股相反力量，不給單一方向。
                    ['name' => '銀行', 'direction' => 'mixed', 'why' => '利差擴大有利，但若陡峭化源自衰退定價，信用成本上升不利', 'symbols' => ['JPM', 'BAC']],
                ],
            ],
            [
                'key' => 'us_bull_flattening',
                'when' => ['quadrant' => 'bull_flattening'],
                'conviction' => 'high',
                'chain' => [
                    '避險買盤湧入長端，長端下行快於短端',
                    '成長放緩擔憂升溫，資金轉向防禦',
                    '折現率下降但景氣能見度轉差',
                ],
                'sectors' => [
                    ['name' => '公用事業', 'direction' => 'positive', 'why' => '長端殖利率下行時債券替代標的受青睞', 'symbols' => ['XLU']],
                    ['name' => '必需消費', 'direction' => 'positive', 'why' => '需求剛性，景氣轉弱時相對抗跌', 'symbols' => ['XLP']],
                    ['name' => '長天期成長股', 'direction' => 'positive', 'why' => '折現率下降支撐評價', 'symbols' => ['NVDA']],
                    ['name' => '銀行', 'direction' => 'negative', 'why' => '利差收窄疊加景氣放緩下的信用風險', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '景氣循環股', 'direction' => 'negative', 'why' => '曲線平坦化通常反映需求前景轉弱', 'symbols' => ['CAT']],
                ],
            ],
            [
                'key' => 'us_level_bear',
                'when' => ['level' => 'bear'],
                'conviction' => 'medium',
                'chain' => [
                    '殖利率上行，無風險報酬率墊高',
                    '折現率上升壓抑長久期資產評價',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'direction' => 'negative', 'why' => '折現率上升，遠期現金流現值下降', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => 'REITs', 'direction' => 'negative', 'why' => '融資成本上升且股息相對吸引力下降', 'symbols' => ['O', 'VNQ']],
                    ['name' => '公用事業', 'direction' => 'negative', 'why' => '債券替代性質，資金被無風險利率吸走', 'symbols' => ['XLU']],
                ],
            ],
            [
                'key' => 'us_level_bull',
                'when' => ['level' => 'bull'],
                'conviction' => 'medium',
                'chain' => [
                    '殖利率下行，無風險報酬率下降',
                    '折現率下降支撐長久期資產評價',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'direction' => 'positive', 'why' => '折現率下降，遠期現金流現值回升', 'symbols' => ['NVDA', 'TSLA']],
                    ['name' => 'REITs', 'direction' => 'positive', 'why' => '融資成本下降且股息相對吸引力回升', 'symbols' => ['O', 'VNQ']],
                ],
            ],
            [
                'key' => 'us_shape_steepening',
                'when' => ['shape' => 'steepening'],
                'conviction' => 'medium',
                'chain' => ['殖利率方向未明，但曲線陡峭化', '長短端利差擴大'],
                'sectors' => [
                    ['name' => '銀行', 'direction' => 'positive', 'why' => '借短貸長，淨利差隨利差擴大而改善', 'symbols' => ['JPM', 'BAC']],
                ],
            ],
            [
                'key' => 'us_shape_flattening',
                'when' => ['shape' => 'flattening'],
                'conviction' => 'medium',
                'chain' => ['殖利率方向未明，但曲線平坦化', '長短端利差收窄'],
                'sectors' => [
                    ['name' => '銀行', 'direction' => 'negative', 'why' => '存放利差被壓縮，獲利能力承壓', 'symbols' => ['JPM', 'BAC']],
                ],
            ],
            [
                'key' => 'us_inversion',
                'when' => ['inversion' => true],
                'conviction' => 'reference',
                'chain' => [
                    '殖利率曲線倒掛或倒掛後轉正',
                    '歷史上多次領先於衰退，但樣本僅約 6 次',
                    '僅供參考觀察，不構成預測，且領先時間差異極大',
                ],
                'sectors' => [
                    ['name' => '防禦性板塊', 'direction' => 'positive', 'why' => '衰退預期升溫時資金偏好現金流穩定標的', 'symbols' => ['XLP', 'XLU']],
                    ['name' => '景氣循環股', 'direction' => 'negative', 'why' => '需求前景與衰退預期直接相關', 'symbols' => ['CAT']],
                    ['name' => '小型股', 'direction' => 'negative', 'why' => '融資條件與景氣敏感度最高', 'symbols' => ['IWM']],
                ],
            ],
        ],

        /*
         * 台股：美債不直接作用於折現率，而是走「殖利率水準 → 美元 → 外資流向」
         * 的間接鏈，主要輸出是全市場方向，板塊分化為次要。因此刻意不定義象限
         * 規則——曲線形狀對這條傳導鏈沒有可靠的差異化影響，硬套美股的板塊輪動
         * 會失真。
         */
        'tw' => [
            [
                'key' => 'tw_level_bear',
                'when' => ['level' => 'bear'],
                'conviction' => 'medium',
                'chain' => [
                    '美債殖利率上行，美元資產相對吸引力提高',
                    '美元走強，外資對新興市場的配置意願下降',
                    '外資調節台股現貨，權值股首當其衝',
                ],
                'sectors' => [
                    ['name' => '全市場', 'direction' => 'negative', 'why' => '外資是台股主要邊際資金，美元走強時流出壓力上升', 'symbols' => ['0050.TW']],
                    ['name' => '電子權值', 'direction' => 'negative', 'why' => '外資持股比重最高，調節時最先被賣', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                    ['name' => '高股息族群', 'direction' => 'negative', 'why' => '與美債無風險殖利率競爭資金，利差優勢被壓縮', 'symbols' => ['0056.TW']],
                    // 台壽險對美債的反應是雙向的，不可給單一方向。
                    ['name' => '壽險金融', 'direction' => 'mixed', 'why' => '持有大量美元債：殖利率上行使既有部位評價承壓，但新資金再投資收益率上升，且台幣貶值帶來海外部位換算利益；淨效果依帳列分類與升息階段而異', 'symbols' => ['2881.TW', '2882.TW']],
                ],
            ],
            [
                'key' => 'tw_level_bull',
                'when' => ['level' => 'bull'],
                'conviction' => 'medium',
                'chain' => [
                    '美債殖利率下行，美元資產相對吸引力下降',
                    '美元轉弱，外資對新興市場配置意願回升',
                    '外資回補台股現貨，權值股與成長股受惠',
                ],
                'sectors' => [
                    ['name' => '全市場', 'direction' => 'positive', 'why' => '美元轉弱時外資回流，台股邊際資金改善', 'symbols' => ['0050.TW']],
                    ['name' => '電子權值', 'direction' => 'positive', 'why' => '外資回補時最先被買回', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                    ['name' => '高股息族群', 'direction' => 'positive', 'why' => '無風險殖利率下降，股息相對吸引力回升', 'symbols' => ['0056.TW']],
                    ['name' => '壽險金融', 'direction' => 'mixed', 'why' => '既有美元債部位評價回升，但新資金再投資收益率下降，且台幣升值造成海外部位換算損失', 'symbols' => ['2881.TW', '2882.TW']],
                ],
            ],
            [
                'key' => 'tw_inversion',
                'when' => ['inversion' => true],
                'conviction' => 'reference',
                'chain' => [
                    '殖利率曲線倒掛或倒掛後轉正',
                    '台股以出口為主，對美國終端需求高度敏感',
                    '僅供參考觀察，不構成預測，歷史樣本極少',
                ],
                'sectors' => [
                    ['name' => '電子出口', 'direction' => 'negative', 'why' => '美國需求走弱直接反映在台廠接單與稼動率', 'symbols' => ['2330.TW', '2317.TW']],
                    ['name' => '航運', 'direction' => 'negative', 'why' => '貨櫃運量與全球終端需求連動', 'symbols' => ['2603.TW', '2609.TW']],
                ],
            ],
        ],
    ],

];
