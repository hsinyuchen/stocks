import { useI18n } from '../../i18n';

/**
 * 算出單一期間要顯示哪些標記文字（已翻譯）。
 *
 * 抽成獨立函式（而非只留在元件內）是因為手機版需要同一組文字：手機版把
 * `<thead>` 整個隱藏（見 app.css），`<th>` 裡的 JSX 徽章跟著一起消失，
 * 純 CSS 的 `content: attr(data-label)` 又只能塞字串、塞不進帶樣式的
 * `<span>`。所以 StatementTable 把這裡算出的文字接到每個 `td` 的
 * `data-label`，讓手機版至少看得到「期間名稱 · 標記文字」。
 *
 * 資產負債表是時點快照、沒有推導的概念，所以 derivationKey 為 null 時
 * 不算推導與重編標記——在那個分頁標「推導值」會讓讀者以為餘額是算出來的。
 */
export function periodBadgeLabels(t, { period, derivationKey, restatementKey, typicalLength }) {
    const labels = [];

    if (derivationKey) {
        const kind = period[derivationKey];

        if (kind === 'derived') {
            labels.push(t('financials.badge.derived'));
        } else if (kind === 'mixed') {
            labels.push(t('financials.badge.mixed'));
        }

        if (restatementKey && period[restatementKey]) {
            labels.push(t('financials.badge.restated'));
        }
    }

    if (period.fiscalYearComplete === false) {
        labels.push(t('financials.badge.incompleteYear'));
    }

    // COST FY2017 以前的第四季是 16 週（111 天）而非 13 週——不標出來會讓
    // 讀者拿它跟其他季直接比較。typicalLength 為 null 表示樣本太少或太分散、
    // 算不出「典型長度」（見 StatementTable 的 typicalLength()），此時寧可不標
    // 也不要亂標。
    if (typicalLength && Math.abs(period.lengthDays - typicalLength) > 10) {
        labels.push(t('financials.badge.oddLength', { days: period.lengthDays }));
    }

    return labels;
}

/** 期間層級的標記，桌面版渲染在表頭欄位裡。 */
export default function PeriodBadges({ period, derivationKey, restatementKey, typicalLength }) {
    const { t } = useI18n();
    const labels = periodBadgeLabels(t, { period, derivationKey, restatementKey, typicalLength });

    if (labels.length === 0) {
        return null;
    }

    return (
        <span className="financials-badges">
            {labels.map((label) => (
                <span key={label} className="financials-badge">{label}</span>
            ))}
        </span>
    );
}
