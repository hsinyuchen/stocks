<?php

namespace App\Services\Rates;

use App\Data\RatesRegimeData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 把利率環境轉成 prompt 文字，並與使用者持有/自選標的交集。
 *
 * 三個分析介面（晚間快報、權值大盤、個股脈絡）都需要同一份敘述，抽在此處避免
 * 三份複製後各自漂移。
 *
 * 刻意不使用 heredoc 組字串：heredoc 內裸 $ 後接中文會被 PHP 當成變數名，造成
 * Undefined variable 並讓 job 直接失敗（本專案踩過）。一律走 sprintf/implode。
 */
class RatesNarrative
{
    public function __construct(
        private readonly RatesRegimeService $rates,
        private readonly RatesTransmissionMapper $transmission,
    ) {}

    /** 命中行的稱謂：呼叫端只給 audience，語言對應在此解析。 */
    private const HIT_LABELS = [
        'zh' => ['watchlist' => '自選股命中', 'basket' => '籃子命中', 'symbol' => '本檔命中'],
        'en' => ['watchlist' => 'Watchlist hit', 'basket' => 'Basket hit', 'symbol' => 'This symbol'],
    ];

    /**
     * @return array{regime: RatesRegimeData, chains: list<array<string, mixed>>}
     */
    public function snapshot(string $market, string $locale = 'zh'): array
    {
        $regime = $this->rates->current();

        return [
            'regime' => $regime,
            'chains' => $this->transmission->map($regime, $market, $locale),
        ];
    }

    /**
     * 三個分析介面的唯一入口：環境敘述 + 標的交集 + 命中行，一次組好。
     *
     * 三個服務原本要各自寫「取 snapshot → 取交集 → 組區塊 → try/catch」四段
     * 幾乎相同的邏輯，只差命中行的稱謂，集中在此避免各自漂移。
     *
     * best-effort：曲線抓不到或上游拋例外時回不可用區塊，呼叫端的報告照常產出。
     *
     * @param  list<string>  $symbols
     * @param  string  $audience  'watchlist' | 'basket' | 'symbol'
     * @return array{available: bool, affected: list<array<string, mixed>>, block: string}
     */
    public function forAudience(string $market, array $symbols, string $audience = 'watchlist', string $locale = 'zh'): array
    {
        try {
            $snapshot = $this->snapshot($market, $locale);
            $affected = $this->affected($snapshot, $symbols);
            $lines = [$this->block($snapshot, $locale)];
            $label = self::HIT_LABELS[$locale][$audience] ?? self::HIT_LABELS['zh']['watchlist'];

            foreach ($affected as $hit) {
                $lines[] = $locale === 'en'
                    ? sprintf('  - %s %s: %s, %s (%s)', $label, $hit['symbol'], $hit['sector'], $hit['direction'], $hit['why'])
                    : sprintf('  - %s %s：%s，%s（%s）', $label, $hit['symbol'], $hit['sector'], $hit['direction'], $hit['why']);
            }

            return [
                'available' => $snapshot['regime']->available,
                'affected' => $affected,
                'block' => implode("\n", $lines),
            ];
        } catch (Throwable $exception) {
            Log::warning('rates: narrative failed', [
                'market' => $market,
                'error' => $exception->getMessage(),
            ]);

            return [
                'available' => false,
                'affected' => [],
                'block' => $locale === 'en'
                    ? '- US Treasury rates data unavailable this run; do not infer a rates regime.'
                    : '- 本次美債利率資料無法取得，請勿據此推論利率環境。',
            ];
        }
    }

    /**
     * prompt 用的純文字區塊。資料缺席時明確說明，絕不以舊值或假值冒充。
     *
     * @param  array{regime: RatesRegimeData, chains: list<array<string, mixed>>}  $snapshot
     */
    public function block(array $snapshot, string $locale = 'zh'): string
    {
        $regime = $snapshot['regime'];

        if (! $regime->available) {
            return $locale === 'en'
                ? '- US Treasury rates data unavailable this run; do not infer a rates regime.'
                : '- 本次美債利率資料無法取得，請勿據此推論利率環境。';
        }

        $lines = [$this->headline($regime, $locale)];

        foreach ($regime->windows as $key => $window) {
            $lines[] = $this->windowLine($key, $window, $locale);
        }

        if ($regime->inverted) {
            $lines[] = $locale === 'en'
                ? '- Curve is inverted. Historically an occasional recession lead indicator, but with very few samples; treat as reference, not a forecast.'
                : '- 殖利率曲線目前倒掛。歷史上偶為衰退領先指標，但樣本極少，僅供參考，不得當作預測。';
        } elseif ($regime->recentlyUninverted) {
            $lines[] = $locale === 'en'
                ? '- Curve recently un-inverted. Reference only, not a forecast; historical samples are very few.'
                : '- 殖利率曲線近期由倒掛轉正。僅供參考，不得當作預測，歷史樣本極少。';
        }

        foreach ($snapshot['chains'] as $chain) {
            $lines[] = $this->chainLines($chain, $locale);
        }

        return implode("\n", $lines);
    }

    /**
     * 與給定代號清單交集，回報命中的板塊、方向與機制。
     *
     * @param  array{regime: RatesRegimeData, chains: list<array<string, mixed>>}  $snapshot
     * @param  list<string>  $symbols
     * @return list<array{symbol: string, sector: string, direction: string, why: string, conviction: string}>
     */
    public function affected(array $snapshot, array $symbols): array
    {
        $wanted = array_map(static fn (string $s): string => strtoupper(trim($s)), $symbols);
        $out = [];

        foreach ($snapshot['chains'] as $chain) {
            foreach ((array) ($chain['sectors'] ?? []) as $sector) {
                foreach ((array) ($sector['symbols'] ?? []) as $symbol) {
                    if (! in_array(strtoupper((string) $symbol), $wanted, true)) {
                        continue;
                    }

                    $out[] = [
                        'symbol' => (string) $symbol,
                        'sector' => (string) ($sector['name'] ?? ''),
                        // mixed 原樣保留：壓成單一方向就是編造結論。
                        'direction' => (string) ($sector['direction'] ?? 'neutral'),
                        'why' => (string) ($sector['why'] ?? ''),
                        'conviction' => (string) ($chain['conviction'] ?? 'medium'),
                    ];
                }
            }
        }

        return $out;
    }

    private function headline(RatesRegimeData $regime, string $locale): string
    {
        return $locale === 'en'
            ? sprintf(
                '- US Treasuries as of %s: 10Y %.3f%%, 3M %.3f%%, 10Y-3M spread %+.1f bp.',
                (string) $regime->asOf, (float) $regime->longYield, (float) $regime->shortYield, (float) $regime->spreadBp,
            )
            : sprintf(
                '- 美債（資料日 %s）：10 年 %.3f%%、3 個月 %.3f%%，10Y-3M 利差 %+.1f bp。',
                (string) $regime->asOf, (float) $regime->longYield, (float) $regime->shortYield, (float) $regime->spreadBp,
            );
    }

    /**
     * @param  array{days: int, level: string, shape: string, quadrant: ?string, delta_level_bp: ?float, delta_shape_bp: ?float}  $window
     */
    private function windowLine(string $key, array $window, string $locale): string
    {
        $labels = $locale === 'en'
            ? ['bear' => 'yields rising', 'bull' => 'yields falling', 'neutral' => 'direction unclear',
                'steepening' => 'curve steepening', 'flattening' => 'curve flattening']
            : ['bear' => '殖利率上行', 'bull' => '殖利率下行', 'neutral' => '方向不明',
                'steepening' => '曲線陡峭化', 'flattening' => '曲線平坦化'];

        $shapeNeutral = $locale === 'en' ? 'shape unclear' : '形狀不明';
        $level = $labels[$window['level']] ?? $labels['neutral'];
        $shape = $window['shape'] === 'neutral' ? $shapeNeutral : ($labels[$window['shape']] ?? $shapeNeutral);

        $deltaLevel = $window['delta_level_bp'];
        $deltaShape = $window['delta_shape_bp'];

        return $locale === 'en'
            ? sprintf(
                '- %dd window: %s (%s), %s (%s)%s',
                $window['days'],
                $level,
                $deltaLevel === null ? 'n/a' : sprintf('%+.1f bp', $deltaLevel),
                $shape,
                $deltaShape === null ? 'n/a' : sprintf('%+.1f bp', $deltaShape),
                $window['quadrant'] === null ? '' : sprintf('; quadrant: %s', $window['quadrant']),
            )
            : sprintf(
                '- %d 日窗口：%s（%s）、%s（%s）%s',
                $window['days'],
                $level,
                $deltaLevel === null ? '資料不足' : sprintf('%+.1f bp', $deltaLevel),
                $shape,
                $deltaShape === null ? '資料不足' : sprintf('%+.1f bp', $deltaShape),
                $window['quadrant'] === null ? '；兩維未同時決定性，不判象限' : sprintf('；象限：%s', $window['quadrant']),
            );
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function chainLines(array $chain, string $locale): string
    {
        $conviction = (string) ($chain['conviction'] ?? 'medium');
        $header = $locale === 'en'
            ? sprintf('- Transmission (%s confidence): %s', $conviction, implode(' -> ', (array) ($chain['chain'] ?? [])))
            : sprintf('- 傳導鏈（信度 %s）：%s', $conviction, implode(' → ', (array) ($chain['chain'] ?? [])));

        $lines = [$header];

        foreach ((array) ($chain['sectors'] ?? []) as $sector) {
            $lines[] = sprintf(
                '  - %s：%s（%s）',
                (string) ($sector['name'] ?? ''),
                (string) ($sector['direction'] ?? 'neutral'),
                (string) ($sector['why'] ?? ''),
            );
        }

        return implode("\n", $lines);
    }
}
