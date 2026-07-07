import { useEffect, useRef } from 'react';
import { createChart } from 'lightweight-charts';
import useChartColors from './useChartColors';

/**
 * 建立/銷毀 Lightweight Charts 實例的共用 hook。
 * - containerRef 掛到目標 div。
 * - build(chart, colors) 於掛載時呼叫一次，負責 addSeries 與 setData。
 * - 主題色變化（useChartColors 讀 CSS 變數）時整個 chart 重建，
 *   避免逐一 applyOptions 造成部分 series 顏色殘留。
 * - ResizeObserver 由 autoSize 內建，跟隨容器寬度。
 */
export default function useLightweightChart(build, deps = []) {
    const containerRef = useRef(null);
    const colors = useChartColors();

    useEffect(() => {
        const el = containerRef.current;

        if (!el) {
            return undefined;
        }

        const chart = createChart(el, {
            autoSize: true,
            layout: {
                background: { color: 'transparent' },
                textColor: colors.muted,
            },
            grid: {
                vertLines: { color: colors.border, visible: false },
                horzLines: { color: colors.border },
            },
            timeScale: { borderColor: colors.border },
            rightPriceScale: { borderColor: colors.border },
        });

        build(chart, colors);

        return () => chart.remove();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [colors, ...deps]);

    return containerRef;
}
