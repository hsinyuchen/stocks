import { router } from '@inertiajs/react';
import { useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';
import StatementTable from '../../Components/financials/StatementTable';
import StatementBarChart from '../../Components/financials/StatementBarChart';

/*
 * 三張表的科目清單。鍵名與 config/financial_statements.php 的欄位名一致——
 * payload 一次給滿 33 個科目，切換分頁只是換顯示哪些列，不重新打 request。
 *
 * derivationKey / restatementKey 不是 i18n 鍵，是 financials.periods[] 底下的欄位名
 * （見 FinancialStatementsPayload::period()）：'incomeDerivation'、'cashflowDerivation'
 * 是 DerivationKind 字串值，'incomeRestatementMixed'、'cashflowRestatementMixed' 是布林。
 * 資產負債表是時點快照，沒有推導的概念，兩者皆為 null。
 */
const TABS = {
    income: {
        labelKey: 'financials.tab.income',
        fields: [
            'revenue', 'cost_of_revenue', 'gross_profit', 'research_development',
            'selling_general_admin', 'operating_expenses', 'operating_income',
            'non_operating_income', 'pretax_income', 'income_tax', 'net_income',
            'eps_basic', 'eps_diluted',
        ],
        series: [
            { field: 'revenue', labelKey: 'financials.chart.revenue' },
            { field: 'net_income', labelKey: 'financials.chart.netIncome' },
        ],
        derivationKey: 'incomeDerivation',
        restatementKey: 'incomeRestatementMixed',
    },
    balance: {
        labelKey: 'financials.tab.balance',
        fields: [
            'cash_and_equivalents', 'accounts_receivable', 'inventories',
            'current_assets', 'property_plant_equipment', 'intangible_assets',
            'total_assets', 'accounts_payable', 'current_liabilities',
            'long_term_debt', 'total_liabilities', 'equity', 'retained_earnings',
        ],
        series: [
            { field: 'total_assets', labelKey: 'financials.chart.totalAssets' },
            { field: 'total_liabilities', labelKey: 'financials.chart.totalLiabilities' },
            { field: 'equity', labelKey: 'financials.chart.equity' },
        ],
        derivationKey: null,
        restatementKey: null,
    },
    cashflow: {
        labelKey: 'financials.tab.cashflow',
        fields: [
            'operating_cash_flow', 'investing_cash_flow', 'financing_cash_flow',
            'capex', 'depreciation_amortization', 'share_based_compensation',
            'net_change_in_cash',
        ],
        series: [
            { field: 'operating_cash_flow', labelKey: 'financials.chart.operating' },
            { field: 'investing_cash_flow', labelKey: 'financials.chart.investing' },
            { field: 'financing_cash_flow', labelKey: 'financials.chart.financing' },
        ],
        derivationKey: 'cashflowDerivation',
        restatementKey: 'cashflowRestatementMixed',
    },
};

export default function Financials({ instrumentId, symbol, instrumentName, financials }) {
    const { t } = useI18n();
    const [tab, setTab] = useState('income');

    const inFlight = financials.state === 'fetching' || financials.state === 'refreshing';
    const stalled = useAnalysisPolling(inFlight, ['financials']);

    /*
     * 季／年切換與展開改變的是伺服器端的取數範圍，必須重抓；分頁切換不必。
     *
     * 本專案前端慣例是字串內插組 URL，不是 Ziggy 的 route()（見
     * resources/js/Pages/Stocks/Search.jsx 的 `/stocks/${instrument.id}/...`）——
     * route-model binding 綁的是 id，Instrument 沒有自訂 getRouteKeyName()，
     * 用 symbol 組不出正確網址。
     *
     * only: ['financials'] 讓這變成 Inertia partial 請求，controller 對 partial
     * 請求不派工（見 StockFinancialsController::shouldDispatch()）——這裡本來就
     * 不需要派工：季／年、展開只是換取數範圍，資料已經在資料庫裡，Reader 只讀不抓。
     */
    const reload = (params) => {
        router.get(
            `/stocks/${instrumentId}/financials`,
            { type: financials.periodType, expanded: financials.expanded ? 1 : undefined, ...params },
            { only: ['financials'], preserveScroll: true, preserveState: true }
        );
    };

    const active = TABS[tab];

    return (
        <AppShell title={t('financials.title')}>
            <section className="financials-page">
                <header className="financials-header">
                    <p className="section-kicker">{instrumentName ?? symbol}</p>
                    <h2>{t('financials.title')}</h2>
                </header>

                <StatusBanner
                    state={financials.state}
                    isStale={financials.isStale}
                    errorCategory={financials.errorCategory}
                    stalled={stalled}
                    hasPeriods={financials.periods.length > 0}
                />

                <div className="financials-controls">
                    <div className="financials-tabs" role="tablist">
                        {Object.entries(TABS).map(([key, cfg]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                className={tab === key ? 'is-active' : undefined}
                                onClick={() => setTab(key)}
                            >
                                {t(cfg.labelKey)}
                            </button>
                        ))}
                    </div>

                    <div className="financials-period-switch">
                        {['quarter', 'annual'].map((type) => (
                            <button
                                key={type}
                                type="button"
                                aria-pressed={financials.periodType === type}
                                className={financials.periodType === type ? 'is-active' : undefined}
                                onClick={() => reload({ type })}
                            >
                                {t(`financials.periodType.${type}`)}
                            </button>
                        ))}
                    </div>
                </div>

                {financials.periods.length > 0 && (
                    <>
                        <StatementBarChart
                            periods={financials.periods}
                            series={active.series}
                            unitKey={financials.unit.key}
                        />
                        <StatementTable
                            periods={financials.periods}
                            fields={active.fields}
                            notDisclosed={financials.notDisclosed}
                            unitKey={financials.unit.key}
                            derivationKey={active.derivationKey}
                            restatementKey={active.restatementKey}
                        />
                        <ExpandToggle financials={financials} onToggle={reload} />
                    </>
                )}
            </section>
        </AppShell>
    );
}

/*
 * hasPeriods 傳布林而不是期數：這裡要的是「下面到底畫不畫得出表格」這個是非題，
 * 而那個是非題在上面就是 `financials.periods.length > 0`。傳數量會多出一個
 * 「0 也是數字」的誤用面（`periodCount ? ...` 與 `periodCount > 0` 一字之差、
 * 且前者剛好也對），布林則讓呼叫端只有一種寫法。
 */
function StatusBanner({ state, isStale, errorCategory, stalled, hasPeriods }) {
    const { t } = useI18n();

    if (stalled) {
        return <p className="financials-banner is-warning">{t('financials.state.stalled')}</p>;
    }

    /*
     * unsupported 是唯一「狀態說沒有、表格卻畫得出來」的組合：Reader::state() 對
     * unsupported 刻意不看有沒有舊列（見它的 docblock——asset_type 被更正成 etf、
     * 或 SEC ticker map 查不到時，判定變更要立刻反映在畫面上），但那批已落地的
     * 真實財報仍會照常渲染。沿用 financials.state.unsupported 的話，橫幅說「此標的
     * 沒有可取得的財報」、下面卻是一整頁財報，兩者互相打臉。
     * 這個組合至少要等 unsupported 的 7 天退避到期、下一次成功重抓才會自愈，
     * 期間每一次瀏覽都看得到，所以換一句只講「不再更新」的文案。
     */
    if (state === 'unsupported' && hasPeriods) {
        return <p className="financials-banner">{t('financials.state.unsupportedWithHistory')}</p>;
    }

    /*
     * Reader::state() 對「failed ＋ 有舊列」刻意回 'ready'（不整頁換成錯誤頁），
     * errorCategory 是這個決策唯一留下的痕跡——這裡接住它，優先於下面的
     * isStale 提示。與其他非 ready 狀態的既有規則一致（狀態別的橫幅蓋過單純
     * 的過期提示，兩者不同時顯示）：lastUpdateFailed 的文案已經涵蓋「這是
     * 上次成功取得的內容」，再疊一句「資料可能已過期」是重複資訊。
     */
    if (state === 'ready' && errorCategory) {
        return <p className="financials-banner">{t('financials.state.lastUpdateFailed')}</p>;
    }

    // ready（且沒有最近失敗）是唯一沒有橫幅的狀態；isStale 仍要提醒一句。
    const key = {
        fetching: 'financials.state.fetching',
        refreshing: 'financials.state.refreshing',
        absent: 'financials.state.absent',
        unsupported: 'financials.state.unsupported',
        failed: 'financials.state.failed',
    }[state];

    if (!key) {
        return isStale ? <p className="financials-banner">{t('financials.state.stale')}</p> : null;
    }

    return <p className="financials-banner">{t(key)}</p>;
}

function ExpandToggle({ financials, onToggle }) {
    const { t } = useI18n();

    if (financials.totalCount <= financials.shownCount && !financials.expanded) {
        return null;
    }

    return (
        <div className="financials-expand">
            <button type="button" onClick={() => onToggle({ expanded: financials.expanded ? undefined : 1 })}>
                {financials.expanded ? t('financials.showLess') : t('financials.showAll')}
            </button>
            <span className="financials-total">{t('financials.periodCount', { total: financials.totalCount })}</span>
        </div>
    );
}
