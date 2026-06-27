import {
    Area,
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import useChartColors from './useChartColors';

function tooltipStyle(colors) {
    return {
        background: colors.surface,
        border: `1px solid ${colors.border}`,
        borderRadius: 8,
        color: colors.text,
        fontSize: 12,
    };
}

/**
 * Price area chart with MA5/MA20 overlays and a faint volume bar series.
 * `indicators` is the series object produced by TechnicalIndicatorService::series().
 */
export default function PriceChart({ indicators }) {
    const colors = useChartColors();

    if (!indicators?.close?.length) {
        return null;
    }

    const rows = indicators.dates.map((date, i) => ({
        date,
        close: indicators.close[i],
        volume: indicators.volume[i],
        ma5: indicators.ma5[i],
        ma20: indicators.ma20[i],
    }));

    // Thin out axis ticks so dense series stay readable.
    const step = Math.max(1, Math.ceil(rows.length / 6));
    const tickInterval = step - 1;

    return (
        <ResponsiveContainer height={240} width="100%">
            <ComposedChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                <defs>
                    <linearGradient id="priceCloseFill" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stopColor={colors.positive} stopOpacity={0.18} />
                        <stop offset="100%" stopColor={colors.positive} stopOpacity={0} />
                    </linearGradient>
                </defs>
                <CartesianGrid stroke={colors.border} strokeOpacity={0.4} vertical={false} />
                <XAxis
                    dataKey="date"
                    interval={tickInterval}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                />
                <YAxis
                    domain={['auto', 'auto']}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                    width={40}
                    yAxisId="price"
                />
                <YAxis domain={['auto', 'auto']} hide yAxisId="volume" />
                <Tooltip
                    contentStyle={tooltipStyle(colors)}
                    labelStyle={{ color: colors.muted }}
                />
                <Bar
                    dataKey="volume"
                    fill={colors.muted}
                    fillOpacity={0.18}
                    isAnimationActive={false}
                    yAxisId="volume"
                />
                <Area
                    dataKey="close"
                    fill="url(#priceCloseFill)"
                    isAnimationActive={false}
                    stroke={colors.positive}
                    strokeWidth={2}
                    type="monotone"
                    yAxisId="price"
                />
                <Line
                    dataKey="ma5"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.accent}
                    strokeWidth={1.25}
                    type="monotone"
                    yAxisId="price"
                />
                <Line
                    dataKey="ma20"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.muted}
                    strokeWidth={1.25}
                    type="monotone"
                    yAxisId="price"
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}
