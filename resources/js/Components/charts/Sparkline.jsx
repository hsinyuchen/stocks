import { Line, LineChart, ResponsiveContainer } from 'recharts';
import useChartColors from './useChartColors';

/**
 * Tiny inline trend line. `data` is an array of numbers (oldest -> newest).
 * `up` controls color; when omitted it's derived from first vs last value.
 */
export default function Sparkline({ data = [], up }) {
    const colors = useChartColors();

    if (!Array.isArray(data) || data.length < 2) {
        return null;
    }

    const rows = data.map((v, i) => ({ i, v: Number(v) }));
    const isUp = up === undefined
        ? rows[rows.length - 1].v >= rows[0].v
        : Boolean(up);
    const stroke = isUp ? colors.positive : colors.neg;

    return (
        <ResponsiveContainer height={28} width="100%">
            <LineChart data={rows} margin={{ top: 2, right: 2, bottom: 2, left: 2 }}>
                <Line
                    dataKey="v"
                    dot={false}
                    isAnimationActive={false}
                    stroke={stroke}
                    strokeWidth={2}
                    type="monotone"
                />
            </LineChart>
        </ResponsiveContainer>
    );
}
