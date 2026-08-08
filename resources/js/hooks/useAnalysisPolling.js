import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * 前 15 秒用密集間隔，之後退避。
 *
 * 一次分析實測 28～47 秒、問答約 10～40 秒，全程 3 秒一次等於每題多打十幾次
 * request。而共享主機的瓶頸是 entry process 與 CPU 配額，不是延遲，所以前段保持
 * 靈敏、後段拉長是划算的交換。
 */
const FAST_INTERVAL_MS = 3000;
const SLOW_INTERVAL_MS = 6000;
const FAST_PHASE_MS = 15000;

/**
 * 放棄輪詢的門檻。
 *
 * 單則新聞分析實測約 47 秒，每日總結更久，上游塞住時 LLM 端還有 120 秒逾時，
 * 所以門檻要遠高於正常耗時；真正會撞到這個上限的情況通常是 queue worker 沒啟動
 * （只跑了 `php artisan serve`），此時要停止輪詢並讓畫面說清楚原因，而不是無限
 * 打 request。
 */
const MAX_WAIT_MS = 10 * 60 * 1000;

/**
 * 分析改為佇列執行後，頁面需要自己等結果。
 *
 * @param {boolean} hasPending 目前畫面上是否還有未完成的分析
 * @param {string[]} only 要重新抓取的 Inertia props（局部重載，不動其他區塊）
 * @returns {boolean} 是否已等過久而停止輪詢
 */
export default function useAnalysisPolling(hasPending, only) {
    const [stalled, setStalled] = useState(false);
    // only 每次 render 都是新陣列，直接放進依賴會讓 effect 每次重跑並重置計時。
    const onlyKey = only.join(',');

    /*
     * 排隊中的分析不鎖畫面——job 在後端跑，切換頁面不會中斷它。但關閉分頁就看不到
     * 結果了（結果只寫回資料庫，沒有通知管道），所以這裡提醒一次。
     */
    useEffect(() => {
        if (!hasPending) {
            return undefined;
        }

        const warn = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [hasPending]);

    useEffect(() => {
        if (!hasPending) {
            setStalled(false);

            return undefined;
        }

        const startedAt = Date.now();
        let timer = null;
        let cancelled = false;

        const tick = () => {
            if (cancelled) {
                return;
            }

            const elapsed = Date.now() - startedAt;

            if (elapsed > MAX_WAIT_MS) {
                setStalled(true);

                return;
            }

            /*
             * 分頁不在前景就跳過這一輪，但計時器繼續跑。使用者切走後結果仍會在
             * 後端完成，切回來時下一輪就抓得到；而放著不管的分頁原本會空打滿
             * 10 分鐘的 request，那些流量沒有任何人看得到。
             */
            if (typeof document === 'undefined' || document.visibilityState === 'visible') {
                // preserveState 保住表單與圖表狀態，preserveScroll 避免每次跳回頂端。
                router.reload({ only: onlyKey.split(','), preserveScroll: true, preserveState: true });
            }

            timer = setTimeout(tick, elapsed > FAST_PHASE_MS ? SLOW_INTERVAL_MS : FAST_INTERVAL_MS);
        };

        timer = setTimeout(tick, FAST_INTERVAL_MS);

        return () => {
            cancelled = true;

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, [hasPending, onlyKey]);

    return stalled;
}
