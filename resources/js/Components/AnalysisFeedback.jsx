/**
 * 非同步分析共用的呈現元件。
 *
 * 個股分析與個股問答都要顯示同一種失敗說明與同一種佇列停擺提示。各自複製一份
 * 等於保證兩邊日後會分岔——其中一邊修好的文案，另一邊還留著舊的。
 */

import { useI18n } from '../i18n';

/**
 * AI 失敗的原因與下一步。
 *
 * 逾時、金鑰失效、模型名稱錯誤原本都顯示同一句話，使用者無從判斷該重試還是
 * 該去改設定；分類由後端 LlmFailureReason 提供，前端只負責呈現。
 *
 * failure 可能不存在——job 自己炸掉時只來得及改狀態，來不及寫原因，此時由呼叫端
 * 傳入 fallback，不要讓畫面出現一張沒有任何說明的空卡片。
 */
export function FailureNote({ failure }) {
    if (!failure) {
        return null;
    }

    return (
        <div className="analysis-failure">
            <strong>{failure.message}</strong>
            <span>{failure.hint}</span>
        </div>
    );
}

/**
 * 只在輪詢等到逾時才出現。最常見的原因是開發環境只啟動了 web server，
 * 沒有 queue worker，job 因此永遠不會被取出執行。
 */
export function QueueStalledHint() {
    const { t } = useI18n();

    return (
        <p className="queue-stalled-hint">
            {t('analysisFeedback.stalledPart1')}<code>composer dev</code>
            {t('analysisFeedback.stalledPart2')}<code>php artisan queue:work</code>
            {t('analysisFeedback.stalledPart3')}
        </p>
    );
}

export function formatDateTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString('zh-TW', { dateStyle: 'medium', timeStyle: 'short' });
}
