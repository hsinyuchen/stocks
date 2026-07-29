import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * 遮罩出現的延遲。
 *
 * 本機頁面切換多半在 200ms 內完成，沒有延遲的話每次點連結都會閃一下全螢幕遮罩，
 * 比不做還吵。超過這個時間才算「使用者感覺得到的等待」。
 */
const SHOW_DELAY_MS = 250;

/**
 * 強制解鎖的保險絲。
 *
 * 攔截導覽的代價是：只要有一次 visit 沒走到 finish，整個站就再也點不動。Inertia
 * 保證 finish 會觸發，但這裡的失效模式是「使用者卡死」而不是「少一個遮罩」，
 * 不值得賭。時間取得比任何前景請求都長（LLM 呼叫已改由佇列執行，前景請求都是
 * 幾秒內的事）。
 */
const FAILSAFE_MS = 30000;

/**
 * 背景請求不該觸發遮罩，也不該被攔截。
 *
 * 分析輪詢用的是帶 only 的局部重載（見 hooks/useAnalysisPolling.js），每 3 秒
 * 一次；把它當成前景請求會讓畫面每 3 秒鎖一次。
 */
function isBackgroundVisit(visit) {
    return Array.isArray(visit?.only) && visit.only.length > 0;
}

/**
 * 前景請求進行中的全域遮罩。
 *
 * 目的是避免請求送到一半被使用者點掉：Inertia 的 visit 一旦被新的導覽取代就會
 * 中止，表單送出中途換頁等於這次操作直接消失，而使用者只會看到「按了沒反應」。
 *
 * 只涵蓋短等待。已排入佇列的分析不鎖畫面——job 在後端跑，離開頁面不影響它，
 * 那段改由 useAnalysisPolling 的離開提醒負責。
 */
export default function BusyOverlay() {
    const [visible, setVisible] = useState(false);
    const [method, setMethod] = useState('get');
    // 攔截 handler 在 effect 建立時就綁定，讀 state 會拿到當下的閉包值，故用 ref。
    const activeRef = useRef(false);
    const timerRef = useRef(null);
    const failsafeRef = useRef(null);

    useEffect(() => {
        const release = () => {
            activeRef.current = false;
            setVisible(false);

            if (timerRef.current !== null) {
                clearTimeout(timerRef.current);
                timerRef.current = null;
            }

            if (failsafeRef.current !== null) {
                clearTimeout(failsafeRef.current);
                failsafeRef.current = null;
            }
        };

        // 回傳 false 會取消該次 visit。只擋使用者主動觸發的導覽，不擋輪詢。
        const offBefore = router.on('before', (event) => {
            if (activeRef.current && !isBackgroundVisit(event.detail.visit)) {
                return false;
            }
        });

        const offStart = router.on('start', (event) => {
            if (isBackgroundVisit(event.detail.visit)) {
                return;
            }

            release();
            activeRef.current = true;
            setMethod(event.detail.visit?.method ?? 'get');
            timerRef.current = setTimeout(() => setVisible(true), SHOW_DELAY_MS);
            failsafeRef.current = setTimeout(release, FAILSAFE_MS);
        });

        // finish 在成功、失敗、取消時都會觸發，是唯一保證會跑的收尾點。
        const offFinish = router.on('finish', (event) => {
            if (isBackgroundVisit(event.detail.visit)) {
                return;
            }

            release();
        });

        const warnOnUnload = (event) => {
            if (!activeRef.current) {
                return;
            }

            // 現代瀏覽器只認 preventDefault，顯示的是瀏覽器自己的固定文案。
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warnOnUnload);

        return () => {
            offBefore();
            offStart();
            offFinish();
            window.removeEventListener('beforeunload', warnOnUnload);
            release();
        };
    }, []);

    if (!visible) {
        return null;
    }

    return (
        <div aria-busy="true" aria-live="assertive" className="busy-overlay" role="status">
            <div className="busy-overlay__box">
                <span aria-hidden="true" className="busy-overlay__spinner" />
                <strong>{method === 'get' ? '載入中…' : '處理中…'}</strong>
                <span className="busy-overlay__note">請稍候，完成前請勿離開或切換頁面。</span>
            </div>
        </div>
    );
}
