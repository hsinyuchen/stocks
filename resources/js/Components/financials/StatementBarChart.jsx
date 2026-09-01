import { useI18n } from '../../i18n';

const VIEW_WIDTH = 800;
const VIEW_HEIGHT = 260;
const PADDING = { top: 16, right: 8, bottom: 28, left: 8 };
const SERIES_COLORS = ['var(--chart-series-1)', 'var(--chart-series-2)', 'var(--chart-series-3)'];

// 展開後最多 20 期（見 config/financial_statements.php 的 quarters）。期數一多，
// 水平置中的期間標籤會互相疊字，改成斜放讓每期占用的水平寬度變窄。
const ROTATE_LABEL_THRESHOLD = 10;
const ROTATE_DEGREES = -40;

/**
 * 分組長條圖，自繪 SVG。
 *
 * 不用 lightweight-charts：它的 HistogramSeries 沒有分組長條，兩個數列會疊在
 * 同一根上，而本圖的重點正是並排比較。X 軸也只是 4~20 個離散期間，不需要時間軸。
 *
 * 用 viewBox 做響應式而不是量測容器：量測要 ResizeObserver ＋ ref，而 viewBox
 * 天生就能等比縮放，SVG 也會依 viewBox 推導出正確的長寬比。
 *
 * periods 是新→舊排序（與 StatementTable 一致），這裡不重新排序，
 * 直接依序由左至右畫，讓兩處的期間順序看起來一致（最新在左）。
 */
export default function StatementBarChart({ periods, series, unitKey }) {
    const { t } = useI18n();

    const values = periods
        .flatMap((period) => series.map((s) => period.values[s.field]))
        .filter((value) => value !== null && value !== undefined);

    // 一個能畫的值都沒有（例如全部科目都無資料）時不畫圖，交給表格顯示「—」。
    if (values.length === 0) {
        return null;
    }

    const max = Math.max(0, ...values);
    const min = Math.min(0, ...values);
    // 全部數值都是 0 時 max === min === 0，span 會是 0，除下去得到 Infinity/NaN——
    // 這是最容易炸的邊界，用 || 1 兜底（此時所有柱子高度都會算成 0）。
    const span = max - min || 1;

    const plotHeight = VIEW_HEIGHT - PADDING.top - PADDING.bottom;
    const plotWidth = VIEW_WIDTH - PADDING.left - PADDING.right;
    const groupWidth = plotWidth / periods.length;
    const barWidth = (groupWidth * 0.7) / series.length;
    // 零軸位置由 min/max 決定：全部正值時零軸貼底，全部負值時零軸貼頂，
    // 有正有負時落在中間某處，比例對應 max 占整個 span 的份量。
    const zeroY = PADDING.top + (max / span) * plotHeight;
    const rotateLabels = periods.length > ROTATE_LABEL_THRESHOLD;

    const seriesNames = series.map((s) => t(s.labelKey)).join('、');

    return (
        <figure className="financials-chart">
            <figcaption className="financials-unit">{t(unitKey)}</figcaption>

            <svg
                viewBox={`0 0 ${VIEW_WIDTH} ${VIEW_HEIGHT}`}
                role="img"
                aria-label={t('financials.chart.ariaLabel', { series: seriesNames })}
            >
                <line
                    className="financials-chart-zero"
                    x1={PADDING.left}
                    y1={zeroY}
                    x2={VIEW_WIDTH - PADDING.right}
                    y2={zeroY}
                    // viewBox 縮放時線寬也會跟著等比縮小，容器窄的時候 1 個座標單位
                    // 的寬度可能不到 1 實體像素而變得看不見；non-scaling-stroke
                    // 讓描邊寬度永遠以實際像素計算，不受縮放影響。
                    vectorEffect="non-scaling-stroke"
                />

                {periods.map((period, groupIndex) => {
                    const groupLeft = PADDING.left + groupIndex * groupWidth + groupWidth * 0.15;
                    const labelX = groupLeft + (groupWidth * 0.7) / 2;
                    const labelY = VIEW_HEIGHT - 8;

                    return (
                        <g key={period.label}>
                            {series.map((s, seriesIndex) => {
                                const value = period.values[s.field];

                                // null 不畫柱子——畫成 0 會讓「無資料」看起來像「剛好打平」，
                                // 兩者在財報裡是不同的事。
                                if (value === null || value === undefined) {
                                    return null;
                                }

                                // 先夾住最小可視高度再算 y，兩者必須用同一個 height：
                                // 如果 y 用未夾住的原始高度、height 屬性另外夾到 >=1，
                                // 極小的正值會算出 y≈zeroY，疊上被撐大的 1px 高度後，
                                // 那 1px 會整條畫到零軸「下面」，變成看起來像負值——
                                // 值的正負號被最小可視高度的保底邏輯吃掉了。
                                const height = Math.max(1, (Math.abs(value) / span) * plotHeight);
                                // 正值從零軸向上長，負值從零軸向下長。
                                const y = value >= 0 ? zeroY - height : zeroY;

                                return (
                                    <rect
                                        key={s.field}
                                        x={groupLeft + seriesIndex * barWidth}
                                        y={y}
                                        width={Math.max(1, barWidth - 1)}
                                        height={height}
                                        fill={SERIES_COLORS[seriesIndex % SERIES_COLORS.length]}
                                    />
                                );
                            })}
                            <text
                                className="financials-chart-label"
                                x={labelX}
                                y={labelY}
                                textAnchor={rotateLabels ? 'end' : 'middle'}
                                transform={rotateLabels ? `rotate(${ROTATE_DEGREES} ${labelX} ${labelY})` : undefined}
                            >
                                {period.label}
                            </text>
                        </g>
                    );
                })}
            </svg>

            <ul className="financials-chart-legend">
                {series.map((s, i) => (
                    <li key={s.field}>
                        <span
                            className="financials-legend-swatch"
                            style={{ background: SERIES_COLORS[i % SERIES_COLORS.length] }}
                        />
                        {t(s.labelKey)}
                    </li>
                ))}
            </ul>
        </figure>
    );
}
