import { useI18n } from '../../i18n';
import PeriodBadges, { periodBadgeLabels } from './PeriodBadges';

const EPS_FIELDS = ['eps_basic', 'eps_diluted'];

/**
 * 桌面期間當欄、手機一期一卡。
 *
 * 兩種版面用同一份 DOM ＋ 兩套 CSS，不在 JS 裡判斷視窗寬度：matchMedia 在首次
 * 渲染時的結果不穩，而且螢幕旋轉還要自己接事件（見 app.css 的
 * `.financials-table` 手機斷點）。
 */
export default function StatementTable({
    periods, fields, notDisclosed, unitKey, derivationKey, restatementKey,
}) {
    const { t } = useI18n();
    const typical = typicalLength(periods);

    return (
        <div className="financials-table-wrap">
            <p className="financials-unit">{t(unitKey)}</p>

            <table className="financials-table">
                <thead>
                    <tr>
                        <th scope="col">{/* 科目欄，標題留白 */}</th>
                        {periods.map((period) => (
                            <th key={period.label} scope="col">
                                <span className="financials-period-label">{period.label}</span>
                                <PeriodBadges
                                    period={period}
                                    derivationKey={derivationKey}
                                    restatementKey={restatementKey}
                                    typicalLength={typical}
                                />
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {fields.map((field) => (
                        <tr key={field}>
                            <th scope="row">{t(`financials.field.${field}`)}</th>
                            {periods.map((period) => (
                                <td
                                    key={period.label}
                                    data-label={mobileLabel(t, period, { derivationKey, restatementKey, typicalLength: typical })}
                                >
                                    <Cell
                                        field={field}
                                        value={period.values[field]}
                                        notDisclosed={notDisclosed}
                                    />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * 手機版的 `data-label`：期間名稱 + 標記文字。
 *
 * 手機斷點把 `<thead>` 整個隱藏（含桌面版的 `PeriodBadges`），若不把標記文字
 * 併進這裡，手機版就完全看不到「推導值」「16 週 Q4」這類標記——這是純 CSS
 * `content: attr(data-label)` 的限制：它只能塞字串，塞不進獨立的 `<span>`
 * 徽章。犧牲手機版的徽章樣式，換取標記文字至少看得到。
 */
function mobileLabel(t, period, { derivationKey, restatementKey, typicalLength }) {
    const labels = periodBadgeLabels(t, { period, derivationKey, restatementKey, typicalLength });

    return labels.length === 0 ? period.label : `${period.label} · ${labels.join(' ・ ')}`;
}

/**
 * 缺料有兩種，必須分開講：
 * - 制度性不揭露（台股不單獨揭露研發費用）→ 講明白是這個市場的制度。
 * - 公司不適用或該期無資料 → 「—」。
 * 兩者的值都是 null，只能靠 payload 給的 notDisclosed 清單區分。
 * 而 0 是合法的財報數字，要照常顯示，不能歸進上面任何一種。
 */
function Cell({ field, value, notDisclosed }) {
    const { t } = useI18n();

    if (value === null || value === undefined) {
        return notDisclosed.includes(field)
            ? <span className="financials-not-disclosed">{t('financials.notDisclosed')}</span>
            : <span className="financials-no-value">{t('financials.noValue')}</span>;
    }

    return <span className="financials-value">{formatAmount(field, value)}</span>;
}

/**
 * payload 已經縮放過，這裡只做千分位與小數位。
 *
 * 絕對不要在這裡再乘除任何倍率——spec 點名過的「100 倍陷阱」就是同一種錯：
 * 同一個數字在兩個地方各被調整一次。
 */
function formatAmount(field, value) {
    const digits = EPS_FIELDS.includes(field) ? 4 : 2;

    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(value);
}

/**
 * 算「典型長度」，用來判斷哪個期間該標「期間長度異常」。
 *
 * 原計畫草稿用中位數，但期數少時中位數會反著標：例如只有 2 期、其中 1 期是
 * COST 16 週 Q4，`[91, 116]` 排序後取 `floor(2/2)=1` 一定是取到較大值 116，
 * 於是把「正常的 91 天」標成異常、「真正異常的 116 天」反而被當成基準。
 * 改用眾數＋過半數才採用：找不到過半數的眾數（樣本太少或太分散）時回傳
 * null，`periodBadgeLabels` 看到 null 就完全不標——寧可不標，也不要在樣本
 * 不足以判斷「典型」時亂標。
 */
function typicalLength(periods) {
    const lengths = periods.map((p) => p.lengthDays).filter((n) => Number.isFinite(n));

    if (lengths.length < 3) {
        return null;
    }

    const counts = new Map();
    for (const length of lengths) {
        counts.set(length, (counts.get(length) ?? 0) + 1);
    }

    let mode = null;
    let modeCount = 0;
    for (const [length, count] of counts) {
        if (count > modeCount) {
            mode = length;
            modeCount = count;
        }
    }

    return modeCount > lengths.length / 2 ? mode : null;
}
