import {
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    LineChart,
    ReferenceLine,
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

function tickInterval(length) {
    return Math.max(1, Math.ceil(length / 6)) - 1;
}

function buildRows(indicators) {
    return indicators.dates.map((date, i) => ({
        date,
        k: indicators.k[i],
        d: indicators.d[i],
        macd: indicators.macd[i],
        signal: indicators.signal[i],
        histogram: indicators.histogram[i],
    }));
}

/**
 * Stochastic KD chart with overbought/oversold reference lines.
 */
export function KdChart({ indicators }) {
    const colors = useChartColors();

    if (!indicators?.k?.length) {
        return null;
    }

    const rows = buildRows(indicators);

    return (
        <ResponsiveContainer height={150} width="100%">
            <LineChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                <CartesianGrid stroke={colors.border} strokeOpacity={0.4} vertical={false} />
                <XAxis
                    dataKey="date"
                    interval={tickInterval(rows.length)}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                />
                <YAxis
                    domain={[0, 100]}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                    ticks={[20, 50, 80]}
                    width={28}
                />
                <ReferenceLine stroke={colors.border} strokeDasharray="3 3" y={80} />
                <ReferenceLine stroke={colors.border} strokeDasharray="3 3" y={20} />
                <Tooltip
                    contentStyle={tooltipStyle(colors)}
                    labelStyle={{ color: colors.muted }}
                />
                <Line
                    dataKey="k"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.accent}
                    strokeWidth={1.5}
                    type="monotone"
                />
                <Line
                    dataKey="d"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.muted}
                    strokeWidth={1.5}
                    type="monotone"
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

/**
 * MACD chart: histogram bars (colored by sign) plus MACD and signal lines.
 */
export function MacdChart({ indicators }) {
    const colors = useChartColors();

    if (!indicators?.macd?.length) {
        return null;
    }

    const rows = buildRows(indicators);

    return (
        <ResponsiveContainer height={150} width="100%">
            <ComposedChart data={rows} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                <CartesianGrid stroke={colors.border} strokeOpacity={0.4} vertical={false} />
                <XAxis
                    dataKey="date"
                    interval={tickInterval(rows.length)}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                />
                <YAxis
                    domain={['auto', 'auto']}
                    stroke={colors.muted}
                    tick={{ fill: colors.muted, fontSize: 10 }}
                    tickLine={false}
                    width={36}
                />
                <ReferenceLine stroke={colors.border} y={0} />
                <Tooltip
                    contentStyle={tooltipStyle(colors)}
                    labelStyle={{ color: colors.muted }}
                />
                <Bar dataKey="histogram" isAnimationActive={false}>
                    {rows.map((row, i) => (
                        <Cell
                            fill={Number(row.histogram) >= 0 ? colors.positive : colors.neg}
                            key={`macd-bar-${i}`}
                        />
                    ))}
                </Bar>
                <Line
                    dataKey="macd"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.accent}
                    strokeWidth={1.5}
                    type="monotone"
                />
                <Line
                    dataKey="signal"
                    dot={false}
                    isAnimationActive={false}
                    stroke={colors.muted}
                    strokeWidth={1.5}
                    type="monotone"
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}
