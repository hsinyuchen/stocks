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
                'chain_en' => [
                    'Term premium and inflation expectations rise',
                    'Long-end discount rates rise more than short-end rates',
                    'Long-duration assets face the heaviest valuation pressure, while bank spreads widen at the same time',
                ],
                'sectors' => [
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'positive', 'why' => '借短貸長，淨利差隨曲線陡峭化擴大', 'why_en' => 'Borrows short and lends long, so net interest margin widens as the curve steepens', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'negative', 'why' => '折現率上升，現金流集中在遠期的高評價標的受壓最重', 'why_en' => 'As discount rates rise, richly-valued names with cash flows concentrated far in the future face the heaviest pressure', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => 'REITs', 'name_en' => 'REITs', 'direction' => 'negative', 'why' => '融資成本上升，且相對股息吸引力被無風險殖利率壓縮', 'why_en' => 'Financing costs rise, and relative dividend appeal is squeezed by the higher risk-free yield', 'symbols' => ['O', 'VNQ']],
                    ['name' => '公用事業', 'name_en' => 'Utilities', 'direction' => 'negative', 'why' => '債券替代性質，殖利率上行時資金流出且負債成本上升', 'why_en' => 'As a bond-proxy sector, capital flows out and debt costs rise as yields climb', 'symbols' => ['XLU']],
                    ['name' => '能源與原物料', 'name_en' => 'Energy & materials', 'direction' => 'positive', 'why' => '熊陡常伴隨通膨預期升溫，實體資產受惠', 'why_en' => 'Bear steepening is often accompanied by rising inflation expectations, which benefits real assets', 'symbols' => ['XOM', 'CVX']],
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
                'chain_en' => [
                    'Policy-tightening expectations push the short end up more than the long end',
                    'The curve narrows, compressing banks\' deposit-to-loan spread',
                    'Discount rates keep rising while expectations of an economic slowdown build',
                ],
                'sectors' => [
                    // 與熊陡同為殖利率上行，但對銀行的結論相反——這是採四象限的核心理由。
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'negative', 'why' => '短端資金成本上升快於長端放款收益，淨利差收窄', 'why_en' => 'Short-end funding costs rise faster than long-end lending yields, narrowing net interest margin', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'negative', 'why' => '折現率上升壓抑遠期現金流評價', 'why_en' => 'Rising discount rates weigh on the valuation of far-future cash flows', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => '小型股與高負債', 'name_en' => 'Small caps & leveraged', 'direction' => 'negative', 'why' => '再融資成本上升，且對經濟放緩最敏感', 'why_en' => 'Refinancing costs rise, and this group is the most sensitive to an economic slowdown', 'symbols' => ['IWM']],
                    ['name' => '大型防禦與必需消費', 'name_en' => 'Large-cap defensives & staples', 'direction' => 'positive', 'why' => '現金流穩定、負債比低，緊縮末期相對抗跌', 'why_en' => 'Stable cash flows and low leverage make this group relatively resilient late in a tightening cycle', 'symbols' => ['XLP']],
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
                'chain_en' => [
                    'Rate-cut expectations push the short end down more than the long end',
                    'Discount rates fall, and long-duration asset valuations recover',
                    'But steepening can also reflect recession pricing, so it must be read together with credit spreads',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'positive', 'why' => '折現率下降，遠期現金流評價回升', 'why_en' => 'As discount rates fall, the valuation of far-future cash flows recovers', 'symbols' => ['NVDA', 'TSLA']],
                    ['name' => 'REITs', 'name_en' => 'REITs', 'direction' => 'positive', 'why' => '融資成本下降，且相對股息吸引力回升', 'why_en' => 'Financing costs fall, and relative dividend appeal recovers', 'symbols' => ['O', 'VNQ']],
                    ['name' => '小型股', 'name_en' => 'Small caps', 'direction' => 'positive', 'why' => '再融資壓力緩解，對降息彈性最大', 'why_en' => 'Refinancing pressure eases, and this group has the highest sensitivity to rate cuts', 'symbols' => ['IWM']],
                    // 牛陡對銀行是兩股相反力量，不給單一方向。
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'mixed', 'why' => '利差擴大有利，但若陡峭化源自衰退定價，信用成本上升不利', 'why_en' => 'A wider spread is favorable, but if the steepening stems from recession pricing, rising credit costs are unfavorable', 'symbols' => ['JPM', 'BAC']],
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
                'chain_en' => [
                    'Safe-haven buying floods into the long end, pulling it down faster than the short end',
                    'Concerns about slowing growth build, and capital rotates into defensives',
                    'Discount rates fall even as economic visibility worsens',
                ],
                'sectors' => [
                    ['name' => '公用事業', 'name_en' => 'Utilities', 'direction' => 'positive', 'why' => '長端殖利率下行時債券替代標的受青睞', 'why_en' => 'Bond-proxy names are favored when long-end yields fall', 'symbols' => ['XLU']],
                    ['name' => '必需消費', 'name_en' => 'Consumer staples', 'direction' => 'positive', 'why' => '需求剛性，景氣轉弱時相對抗跌', 'why_en' => 'Demand is inelastic, making this group relatively resilient when the economy weakens', 'symbols' => ['XLP']],
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'positive', 'why' => '折現率下降支撐評價', 'why_en' => 'Falling discount rates support valuations', 'symbols' => ['NVDA']],
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'negative', 'why' => '利差收窄疊加景氣放緩下的信用風險', 'why_en' => 'A narrowing spread compounds with the credit risk from a slowing economy', 'symbols' => ['JPM', 'BAC']],
                    ['name' => '景氣循環股', 'name_en' => 'Cyclicals', 'direction' => 'negative', 'why' => '曲線平坦化通常反映需求前景轉弱', 'why_en' => 'Curve flattening typically reflects a weakening demand outlook', 'symbols' => ['CAT']],
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
                'chain_en' => [
                    'Yields rise, lifting the risk-free rate of return',
                    'Rising discount rates weigh on long-duration asset valuations',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'negative', 'why' => '折現率上升，遠期現金流現值下降', 'why_en' => 'As discount rates rise, the present value of far-future cash flows falls', 'symbols' => ['TSLA', 'NVDA']],
                    ['name' => 'REITs', 'name_en' => 'REITs', 'direction' => 'negative', 'why' => '融資成本上升且股息相對吸引力下降', 'why_en' => 'Financing costs rise and relative dividend appeal declines', 'symbols' => ['O', 'VNQ']],
                    ['name' => '公用事業', 'name_en' => 'Utilities', 'direction' => 'negative', 'why' => '債券替代性質，資金被無風險利率吸走', 'why_en' => 'As a bond-proxy sector, capital is drawn away by the higher risk-free rate', 'symbols' => ['XLU']],
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
                'chain_en' => [
                    'Yields fall, lowering the risk-free rate of return',
                    'Falling discount rates support long-duration asset valuations',
                ],
                'sectors' => [
                    ['name' => '長天期成長股', 'name_en' => 'Long-duration growth', 'direction' => 'positive', 'why' => '折現率下降，遠期現金流現值回升', 'why_en' => 'As discount rates fall, the present value of far-future cash flows recovers', 'symbols' => ['NVDA', 'TSLA']],
                    ['name' => 'REITs', 'name_en' => 'REITs', 'direction' => 'positive', 'why' => '融資成本下降且股息相對吸引力回升', 'why_en' => 'Financing costs fall and relative dividend appeal recovers', 'symbols' => ['O', 'VNQ']],
                ],
            ],
            [
                'key' => 'us_shape_steepening',
                'when' => ['shape' => 'steepening'],
                'conviction' => 'medium',
                'chain' => ['殖利率方向未明，但曲線陡峭化', '長短端利差擴大'],
                'chain_en' => ['Yield direction is unclear, but the curve is steepening', 'The long-short spread widens'],
                'sectors' => [
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'positive', 'why' => '借短貸長，淨利差隨利差擴大而改善', 'why_en' => 'Borrows short and lends long, so net interest margin improves as the spread widens', 'symbols' => ['JPM', 'BAC']],
                ],
            ],
            [
                'key' => 'us_shape_flattening',
                'when' => ['shape' => 'flattening'],
                'conviction' => 'medium',
                'chain' => ['殖利率方向未明，但曲線平坦化', '長短端利差收窄'],
                'chain_en' => ['Yield direction is unclear, but the curve is flattening', 'The long-short spread narrows'],
                'sectors' => [
                    ['name' => '銀行', 'name_en' => 'Banks', 'direction' => 'negative', 'why' => '存放利差被壓縮，獲利能力承壓', 'why_en' => 'Deposit-to-loan spread is compressed, pressuring profitability', 'symbols' => ['JPM', 'BAC']],
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
                'chain_en' => [
                    'The yield curve is inverted, or has just un-inverted after being inverted',
                    'Historically this has often led recessions, but the sample is only about 6 instances',
                    'Reference only, not a forecast, and the lead time varies enormously',
                ],
                'sectors' => [
                    ['name' => '防禦性板塊', 'name_en' => 'Defensive sectors', 'direction' => 'positive', 'why' => '衰退預期升溫時資金偏好現金流穩定標的', 'why_en' => 'As recession expectations build, capital favors names with stable cash flows', 'symbols' => ['XLP', 'XLU']],
                    ['name' => '景氣循環股', 'name_en' => 'Cyclicals', 'direction' => 'negative', 'why' => '需求前景與衰退預期直接相關', 'why_en' => 'Demand outlook is directly tied to recession expectations', 'symbols' => ['CAT']],
                    ['name' => '小型股', 'name_en' => 'Small caps', 'direction' => 'negative', 'why' => '融資條件與景氣敏感度最高', 'why_en' => 'Highest sensitivity to financing conditions and the economic cycle', 'symbols' => ['IWM']],
                ],
            ],
        ],

        /*
         * 台股：美債不直接作用於折現率，而是走「殖利率水準 → 美元 → 外資流向」
         * 的間接鏈，主要輸出是全市場方向，板塊分化為次要。原則上不需要象限
         * 規則——曲線形狀對這條傳導鏈沒有可靠的差異化影響，硬套美股的板塊輪動
         * 會失真。
         *
         * 例外是 bull_flattening（見下方該規則的註解）：這個象限下「殖利率水準
         * → 美元」這一步本身方向相反，level-only 設計的前提不成立，故補一條
         * 象限規則。其餘三個象限的美元通道方向確實不隨曲線形狀改變，維持只用
         * level 規則。
         */
        'tw' => [
            [
                /*
                 * 牛平（bull flattening）是本表唯一的象限規則，因為它是唯一一個
                 * 「殖利率水準 → 美元」這一步方向會反轉的象限：牛平的領先力量是
                 * 避險買盤湧入長端（衰退定價、risk-off），美元傾向轉強而非轉弱，
                 * 外資不必然回流——這與 tw_level_bull 假設的「殖利率下行→美元
                 * 轉弱→外資回補」鏈條相反。其餘三象限（熊陡/熊平/牛陡）的美元
                 * 通道方向不隨曲線形狀改變，故不需要各自的象限規則。
                 */
                'key' => 'tw_bull_flattening',
                'when' => ['quadrant' => 'bull_flattening'],
                'conviction' => 'high',
                'chain' => [
                    '避險買盤湧入美債長端，長端殖利率下行快於短端，通常對應景氣衰退定價而非趨勢性降息',
                    '此情境下美元傾向轉強而非轉弱，外資對新興市場的配置意願不必然回升，與典型的「殖利率下行→美元轉弱→外資回補」鏈條相反',
                    '折現率下降本身支撐評價，但風險趨避下的資金外流與美國終端需求走弱同時施壓，全市場方向由兩股力量拉鋸決定',
                ],
                'chain_en' => [
                    'Safe-haven buying floods into the US Treasury long end, pulling long-end yields down faster than the short end, which typically reflects recession pricing rather than a trending rate cut',
                    'In this setting the dollar tends to strengthen rather than weaken, so foreign investors\' willingness to allocate to emerging markets does not necessarily recover — the reverse of the typical "yields fall, dollar weakens, foreign investors return" chain',
                    'The fall in discount rates itself supports valuations, but risk-averse capital outflows and weakening US end demand press in the opposite direction at the same time, so the broad-market direction is a tug-of-war between the two forces',
                ],
                'sectors' => [
                    // 兩股相反力量：折現率下降支撐評價，但避險情境下美元轉強、外資流出，不給單一方向。
                    ['name' => '全市場', 'name_en' => 'Broad market', 'direction' => 'mixed', 'why' => '殖利率下行原可支撐評價，但牛平屬避險買盤而非降息趨勢，美元轉強、外資對新興市場配置意願不升反降；評價支撐與資金外流兩股力量方向相反，無法給單一結論', 'why_en' => 'Falling yields would normally support valuations, but bull flattening reflects safe-haven buying rather than a rate-cut trend: the dollar strengthens and foreign investors\' willingness to allocate to emerging markets does not rise — it falls. Valuation support and capital outflows pull in opposite directions, so no single direction can be given', 'symbols' => ['0050.TW']],
                    // 與 tw_level_bull 的電子權值（正向）不同：牛平常伴隨美國衰退定價，需求面壓力是與匯率/資金流無關的獨立負向因子。
                    ['name' => '電子出口', 'name_en' => 'Tech exporters', 'direction' => 'negative', 'why' => '牛平常對應美國衰退定價，終端電子需求展望轉弱直接壓抑台廠接單與稼動率；此效應獨立於匯率與資金流方向，即使美元走勢對其他板塊呈現拉鋸，出口電子的需求壓力仍偏空', 'why_en' => 'Bull flattening typically corresponds to recession pricing, and a weakening outlook for end electronics demand directly pressures Taiwanese makers\' order intake and utilization; this effect is independent of the FX and fund-flow direction, so even where the dollar\'s path is a tug-of-war for other sectors, export electronics still faces net demand pressure', 'symbols' => ['2330.TW', '2317.TW']],
                    // 與 tw_level_bull 的壽險金融（mixed）同源但機制不同：這裡美元傾向轉強而非轉弱，換算損益方向與典型牛市相反。
                    ['name' => '壽險金融', 'name_en' => 'Life insurers', 'direction' => 'mixed', 'why' => '既有美元債部位評價隨長端殖利率下行回升，但新資金再投資收益率同步下降；牛平屬避險買盤而非趨勢性降息，美元傾向轉強而非轉弱，海外部位換算損益方向與典型牛市相反，淨效果仍難判定單一方向', 'why_en' => 'The mark on existing USD bond positions recovers as long-end yields fall, but reinvestment yields on new money fall in tandem; because bull flattening reflects safe-haven buying rather than a trending rate cut, the dollar tends to strengthen rather than weaken, so the translation gain or loss on overseas positions runs opposite to a typical bull market, and the net effect remains hard to pin down as a single direction', 'symbols' => ['2881.TW', '2882.TW']],
                ],
            ],
            [
                'key' => 'tw_level_bear',
                'when' => ['level' => 'bear'],
                'conviction' => 'medium',
                'chain' => [
                    '美債殖利率上行，美元資產相對吸引力提高',
                    '美元走強，外資對新興市場的配置意願下降',
                    '外資調節台股現貨，權值股首當其衝',
                ],
                'chain_en' => [
                    'US Treasury yields rise, increasing the relative appeal of dollar assets',
                    'The dollar strengthens, reducing foreign investors\' willingness to allocate to emerging markets',
                    'Foreign investors trim Taiwan equity holdings, hitting large-cap weighted stocks first',
                ],
                'sectors' => [
                    ['name' => '全市場', 'name_en' => 'Broad market', 'direction' => 'negative', 'why' => '外資是台股主要邊際資金，美元走強時流出壓力上升', 'why_en' => 'Foreign investors are the main marginal capital in Taiwan equities; outflow pressure rises as the dollar strengthens', 'symbols' => ['0050.TW']],
                    ['name' => '電子權值', 'name_en' => 'Large-cap tech', 'direction' => 'negative', 'why' => '外資持股比重最高，調節時最先被賣', 'why_en' => 'Foreign ownership is highest here, so these names are sold first when positions are trimmed', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                    ['name' => '高股息族群', 'name_en' => 'High-dividend names', 'direction' => 'negative', 'why' => '與美債無風險殖利率競爭資金，利差優勢被壓縮', 'why_en' => 'Competes for capital against the risk-free yield on US Treasuries, and its yield-spread advantage is squeezed', 'symbols' => ['0056.TW']],
                    // 台壽險對美債的反應是雙向的，不可給單一方向。
                    ['name' => '壽險金融', 'name_en' => 'Life insurers', 'direction' => 'mixed', 'why' => '持有大量美元債：殖利率上行使既有部位評價承壓，但新資金再投資收益率上升，且台幣貶值帶來海外部位換算利益；淨效果依帳列分類與升息階段而異', 'why_en' => 'Holds large USD bond positions: rising yields put the mark on existing holdings under pressure, but reinvestment yields on new money rise, and TWD depreciation produces translation gains on overseas positions; the net effect depends on accounting classification and the stage of the rate cycle', 'symbols' => ['2881.TW', '2882.TW']],
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
                'chain_en' => [
                    'US Treasury yields fall, reducing the relative appeal of dollar assets',
                    'The dollar weakens, and foreign investors\' willingness to allocate to emerging markets recovers',
                    'Foreign investors add back Taiwan equity holdings, benefiting large-cap weighted and growth stocks',
                ],
                'sectors' => [
                    ['name' => '全市場', 'name_en' => 'Broad market', 'direction' => 'positive', 'why' => '美元轉弱時外資回流，台股邊際資金改善', 'why_en' => 'As the dollar weakens, foreign capital flows back in, improving Taiwan equities\' marginal funding', 'symbols' => ['0050.TW']],
                    ['name' => '電子權值', 'name_en' => 'Large-cap tech', 'direction' => 'positive', 'why' => '外資回補時最先被買回', 'why_en' => 'These names are bought back first when foreign investors add back positions', 'symbols' => ['2330.TW', '2317.TW', '2454.TW']],
                    ['name' => '高股息族群', 'name_en' => 'High-dividend names', 'direction' => 'positive', 'why' => '無風險殖利率下降，股息相對吸引力回升', 'why_en' => 'As the risk-free yield falls, relative dividend appeal recovers', 'symbols' => ['0056.TW']],
                    ['name' => '壽險金融', 'name_en' => 'Life insurers', 'direction' => 'mixed', 'why' => '既有美元債部位評價回升，但新資金再投資收益率下降，且台幣升值造成海外部位換算損失', 'why_en' => 'The mark on existing USD bond positions recovers, but reinvestment yields on new money fall, and TWD appreciation produces translation losses on overseas positions', 'symbols' => ['2881.TW', '2882.TW']],
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
                'chain_en' => [
                    'The yield curve is inverted, or has just un-inverted after being inverted',
                    'Taiwan equities are export-driven and highly sensitive to US end demand',
                    'Reference only, not a forecast, and the historical sample is very small',
                ],
                'sectors' => [
                    ['name' => '電子出口', 'name_en' => 'Tech exporters', 'direction' => 'negative', 'why' => '美國需求走弱直接反映在台廠接單與稼動率', 'why_en' => 'Weaker US demand shows up directly in Taiwanese manufacturers\' order intake and utilization rates', 'symbols' => ['2330.TW', '2317.TW']],
                    ['name' => '航運', 'name_en' => 'Shipping', 'direction' => 'negative', 'why' => '貨櫃運量與全球終端需求連動', 'why_en' => 'Container volumes track global end demand', 'symbols' => ['2603.TW', '2609.TW']],
                ],
            ],
        ],
    ],

];
