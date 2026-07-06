import { AreaSeries } from 'lightweight-charts';
import useLightweightChart from './useLightweightChart';
import useChartColors from './useChartColors';

/**
 * 迷你趨勢線。`data` 為數字陣列（oldest -> newest）。
 * `up` 控制顏色；省略時由頭尾值推導。
 * props 介面與舊 recharts 版本一致：`{ data = [], up }`。
 */
export default function Sparkline({ data = [], up }) {
    const colors = useChartColors();
    const isUp = up === undefined
        ? Number(data[data.length - 1]) >= Number(data[0])
        : Boolean(up);
    const stroke = isUp ? colors.positive : colors.neg;

    const containerRef = useLightweightChart((chart) => {
        // sparkline 非主圖，關閉一切軸線/格線/十字線/attribution，
        // attribution logo 由個股頁主圖承擔。
        chart.applyOptions({
            layout: { attributionLogo: false },
            grid: { vertLines: { visible: false }, horzLines: { visible: false } },
            timeScale: { visible: false },
            rightPriceScale: { visible: false },
            crosshair: { mode: 0, vertLine: { visible: false }, horzLine: { visible: false } },
            handleScroll: false,
            handleScale: false,
        });

        const series = chart.addSeries(AreaSeries, {
            lineColor: stroke,
            lineWidth: 2,
            topColor: 'transparent',
            bottomColor: 'transparent',
            priceLineVisible: false,
            lastValueVisible: false,
        });

        // sparkline 無真實日期，用序號當 time（遞增即可）。
        series.setData(data.map((v, i) => ({ time: i, value: Number(v) })));
        chart.timeScale().fitContent();
    }, [JSON.stringify(data), stroke]);

    if (!Array.isArray(data) || data.length < 2) {
        return null;
    }

    return <div ref={containerRef} style={{ height: 28, width: '100%' }} />;
}
