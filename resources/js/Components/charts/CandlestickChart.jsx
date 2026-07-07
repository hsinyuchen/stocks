import {
    CandlestickSeries,
    HistogramSeries,
    LineSeries,
} from 'lightweight-charts';
import useLightweightChart from './useLightweightChart';

/**
 * 平行指標陣列 zip 成 Lightweight Charts 的 {time,value} 點列。
 * 後端以與 candles 等長、索引對齊的平行陣列回傳指標，暖機期為 null；
 * LW 不吃平行陣列，且 null 會斷線，故在此濾掉 null/undefined 點。
 */
const zip = (times, values) => (values ?? [])
    .map((value, index) => ({ time: times[index], value }))
    .filter((point) => point.value !== null && point.value !== undefined);

/**
 * 副圖 pane 定義。key 對應 visiblePanes 陣列成員與 localStorage 值；
 * paneIndex 於渲染時依可見順序動態分配（主圖固定 0）。
 */
const SUBPANES = ['kd', 'macd', 'rsi', 'obv'];

/**
 * K 線主圖 + 成交量 + MA/布林（主圖 pane 0）＋ KD/MACD/RSI/OBV 副圖 panes。
 *
 * props：
 * - chartData：{ candles, indicators, timeframe }，candles 為 {time,open,high,low,close,volume}[]，
 *   indicators 為與 candles 等長的平行陣列物件。
 * - visiblePanes：目前顯示的副圖 key 陣列（SUBPANES 子集）。
 *
 * timeframe 切換由父層換 chartData 觸發 hook 重建，避免殘留舊 series。
 */
export default function CandlestickChart({ chartData, visiblePanes = [] }) {
    const candles = chartData?.candles ?? [];
    const indicators = chartData?.indicators ?? {};

    const containerRef = useLightweightChart((chart, colors) => {
        // 保留 attribution logo（Apache 2.0 授權要求標注 TradingView）。
        const times = candles.map((candle) => candle.time);

        // 副圖依可見順序分配 pane index（主圖為 0）。
        const paneIndexByKey = {};
        visiblePanes
            .filter((key) => SUBPANES.includes(key))
            .forEach((key, order) => {
                paneIndexByKey[key] = order + 1;
            });

        const candleSeries = chart.addSeries(CandlestickSeries, {
            upColor: colors.positive,
            downColor: colors.neg,
            borderUpColor: colors.positive,
            borderDownColor: colors.neg,
            wickUpColor: colors.positive,
            wickDownColor: colors.neg,
        });
        candleSeries.setData(candles.map((candle) => ({
            time: candle.time,
            open: candle.open,
            high: candle.high,
            low: candle.low,
            close: candle.close,
        })));

        // 成交量：獨立 price scale 壓在主圖底部 20%，不干擾價格軸刻度。
        const volumeSeries = chart.addSeries(HistogramSeries, {
            priceScaleId: 'volume',
            priceFormat: { type: 'volume' },
            color: colors.muted,
            priceLineVisible: false,
            lastValueVisible: false,
        });
        volumeSeries.priceScale().applyOptions({
            scaleMargins: { top: 0.8, bottom: 0 },
        });
        volumeSeries.setData(candles.map((candle) => ({
            time: candle.time,
            value: candle.volume,
        })));

        // 主圖疊線：MA5/MA20/MA60 + 布林上/中/下。null 點由 zip 濾除，
        // 月線 ma60 全 null 自然不畫。
        const overlays = [
            { values: indicators.ma5, color: colors.accent, width: 1.25 },
            { values: indicators.ma20, color: colors.muted, width: 1.25 },
            { values: indicators.ma60, color: colors.text, width: 1.25 },
            { values: indicators.boll_upper, color: colors.positive, width: 1 },
            { values: indicators.boll_middle, color: colors.muted, width: 1 },
            { values: indicators.boll_lower, color: colors.neg, width: 1 },
        ];
        overlays.forEach(({ values, color, width }) => {
            const points = zip(times, values);
            if (points.length === 0) {
                return;
            }
            const line = chart.addSeries(LineSeries, {
                color,
                lineWidth: width,
                priceLineVisible: false,
                lastValueVisible: false,
            });
            line.setData(points);
        });

        // 副圖：依 visiblePanes 動態渲染。
        Object.entries(paneIndexByKey).forEach(([key, paneIndex]) => {
            if (key === 'kd') {
                addLine(chart, times, indicators.k, colors.accent, paneIndex);
                addLine(chart, times, indicators.d, colors.neg, paneIndex);
            } else if (key === 'macd') {
                const hist = chart.addSeries(HistogramSeries, {
                    priceLineVisible: false,
                    lastValueVisible: false,
                }, paneIndex);
                hist.setData(zip(times, indicators.macd_histogram).map((point) => ({
                    time: point.time,
                    value: point.value,
                    color: point.value >= 0 ? colors.positive : colors.neg,
                })));
                addLine(chart, times, indicators.macd, colors.accent, paneIndex);
                addLine(chart, times, indicators.macd_signal, colors.muted, paneIndex);
            } else if (key === 'rsi') {
                const rsi = addLine(chart, times, indicators.rsi, colors.accent, paneIndex);
                // 30/70 超買超賣參考線。
                rsi?.createPriceLine({ price: 70, color: colors.neg, lineWidth: 1, lineStyle: 2 });
                rsi?.createPriceLine({ price: 30, color: colors.positive, lineWidth: 1, lineStyle: 2 });
            } else if (key === 'obv') {
                addLine(chart, times, indicators.obv, colors.accent, paneIndex);
            }
        });

        chart.timeScale().fitContent();
    }, [JSON.stringify(chartData), JSON.stringify(visiblePanes)]);

    if (candles.length === 0) {
        return (
            <div className="chart-container chart-container--empty">
                <span className="chart-empty">尚無足夠價格資料繪製 K 線。</span>
            </div>
        );
    }

    return <div ref={containerRef} className="chart-container" />;
}

/** 於指定 pane 加一條 line series，資料經 zip 濾 null；無有效點回傳 null。 */
function addLine(chart, times, values, color, paneIndex) {
    const points = zip(times, values);
    if (points.length === 0) {
        return null;
    }
    const line = chart.addSeries(LineSeries, {
        color,
        lineWidth: 1.5,
        priceLineVisible: false,
        lastValueVisible: false,
    }, paneIndex);
    line.setData(points);

    return line;
}
