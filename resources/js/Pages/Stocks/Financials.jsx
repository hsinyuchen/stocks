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
        <AppShell title={`${symbol} ${t('financials.title')}`}>
            <section className="financials-page">
                <header className="financials-header">
                    <p className="section-kicker">{instrumentName ?? symbol}</p>
                    <h2>{t('financials.title')}</h2>
                </header>

                <StatusBanner state={financials.state} isStale={financials.isStale} stalled={stalled} />

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

function StatusBanner({ state, isStale, stalled }) {
    const { t } = useI18n();

    if (stalled) {
        return <p className="financials-banner is-warning">{t('financials.state.stalled')}</p>;
    }

    // ready 是唯一沒有橫幅的狀態；isStale 仍要提醒一句。
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
