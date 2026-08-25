import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import { ListChecks, Loader2, ScanSearch } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

/**
 * 規則的必要說明。
 *
 * **無條件渲染、不隨勾選出現、不可摺疊。** 這兩條規則的名字會讓使用者推論出
 * 系統沒有驗證過的事（「社交」套利實際只有新聞熱度、「產業加速」是回顧性指標
 * 且只對台股有效），而選股器原本只拿得到 label，沒有任何更正機會。
 * 25 條規則裡只有 2 條帶說明，所以全部攤開也只有幾行——用「選了才顯示」換那
 * 幾行版面，代價是使用者在**決定要不要選**的當下看不到更正。
 *
 * 文案來自 i18n 鍵而不是後端字串：這是硬性揭露，英文介面漏掉會變成中文露出。
 */
function RuleNotes({ rules }) {
    const { t } = useI18n();
    const annotated = rules.filter((rule) => (rule.notes ?? []).length > 0);

    if (annotated.length === 0) {
        return null;
    }

    return (
        <div className="screener-rule-notes">
            <p className="screener-rule-notes__heading">{t('screener.ruleNotesLabel')}</p>
            {annotated.map((rule) => (
                <div className="screener-rule-notes__item" key={rule.key}>
                    <strong>{rule.label}</strong>
                    <ul>
                        {rule.notes.map((note) => (
                            <li key={note}>{t(note)}</li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}


function formatPrice(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return '-';
    }

    return value.toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// 漲跌%：正綠負紅沿用全站 is-up/is-down；前一根 close <= 0 時 service 回 null。
function ChangePercent({ value }) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return <span className="screener-muted">-</span>;
    }

    const className = value > 0 ? 'is-up' : value < 0 ? 'is-down' : '';
    const sign = value > 0 ? '+' : '';

    return <span className={className}>{`${sign}${value.toFixed(2)}%`}</span>;
}

// 每列「加自選」控制項：選 watchlist → Inertia post 既有 addItem（symbol 模式）。
// 防雙擊沿用 Admin/Users 的 per-row submitting state。
function AddToWatchlist({ symbol, name, watchlists }) {
    const { t } = useI18n();
    const [targetId, setTargetId] = useState(watchlists[0]?.id ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [added, setAdded] = useState(false);
    const [error, setError] = useState(null);

    if (watchlists.length === 0) {
        return (
            <Link className="panel-link" href="/watchlists">
                {t('screener.createWatchlistFirst')}
            </Link>
        );
    }

    const submit = () => {
        if (submitting || !targetId) {
            return;
        }

        setSubmitting(true);
        setError(null);
        router.post(
            `/watchlists/${targetId}/items`,
            { symbol, name },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setAdded(true);
                    setError(null);
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? t('screener.addFailed')),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="screener-add">
            <select
                aria-label={t('screener.selectWatchlistFor', { symbol })}
                disabled={submitting}
                onChange={(event) => setTargetId(event.target.value)}
                value={targetId}
            >
                {watchlists.map((watchlist) => (
                    <option key={watchlist.id} value={watchlist.id}>
                        {watchlist.name}
                    </option>
                ))}
            </select>
            <button
                className="button-secondary"
                disabled={submitting}
                onClick={submit}
                type="button"
            >
                {added ? t('screener.added') : t('screener.add')}
            </button>
            {error ? <span className="field-error">{error}</span> : null}
        </div>
    );
}

/**
 * 掃描範圍的說明。
 *
 * 不用加減算式：自選股必然已在標的清單裡（watchlist 只能指向既有標的），寫成
 * 「標的清單 68 支 ＋ 自選 11 支 − 重複 11 支」只會讓人更難懂。直接說總數，
 * 再補一句「其中幾支是你追蹤的」。
 */
function PoolSummary({ poolCount, watchlistCount }) {
    const { t } = useI18n();

    return watchlistCount > 0
        ? t('screener.poolSummaryWithWatchlist', { poolCount, watchlistCount })
        : t('screener.poolSummary', { count: poolCount });
}

/**
 * 完整股池清單。
 *
 * 預設收合：它是「掃描範圍」的佐證資料，不是每次都要讀的內容，但使用者需要能
 * 確認自己關心的標的有沒有被涵蓋。
 */
function PoolList({ pool }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);

    if (pool.length === 0) {
        return null;
    }

    return (
        <div className="screener-pool">
            <button className="screener-pool__toggle" onClick={() => setOpen((v) => !v)} type="button">
                <ListChecks aria-hidden="true" size={14} />
                {open ? t('screener.collapseStockList') : t('screener.viewAllStocks', { count: pool.length })}
            </button>

            {open ? (
                <div className="screener-pool__body">
                    <p className="screener-pool__legend">
                        <span className="screener-pool__tag screener-pool__tag--watchlist">{t('screener.watchlistTag')}</span> {t('screener.inYourWatchlist')}
                    </p>
                    <ul className="screener-pool__list">
                        {pool.map((entry) => (
                            <li className="screener-pool__item" key={entry.symbol}>
                                <a href={`/stocks/search?symbol=${encodeURIComponent(entry.symbol)}`}>
                                    <strong>{entry.symbol}</strong>
                                    <span>{entry.name}</span>
                                </a>
                                {entry.in_watchlist ? (
                                    <span className="screener-pool__tag screener-pool__tag--watchlist">{t('screener.watchlistTag')}</span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

export default function ScreenerIndex({
    rules = [],
    watchlists = [],
    poolCount = 0,
    watchlistCount = 0,
    pool = [],
}) {
    const { t } = useI18n();
    const [selectedRules, setSelectedRules] = useState([]);
    const [excludedRules, setExcludedRules] = useState([]);
    const [picked, setPicked] = useState([]);
    const [targetList, setTargetList] = useState(watchlists[0] ? String(watchlists[0].id) : '');
    const [scanning, setScanning] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [issuesOpen, setIssuesOpen] = useState(false);

    // rule key → label，供結果表把 matched keys 顯示成中文訊號 badge。
    const ruleLabels = useMemo(
        () => Object.fromEntries(rules.map((rule) => [rule.key, rule.label])),
        [rules],
    );

    // 三態循環：未選 → 必要（AND）→ 排除（NOT）→ 未選。
    // 條件全用 AND 時勾越多命中越少，實測兩條技術規則就直接歸零；
    // 實務上更常需要的是「一組必要條件 + 一組否決條件」。
    const cycleRule = (key) => {
        if (selectedRules.includes(key)) {
            setSelectedRules((c) => c.filter((k) => k !== key));
            setExcludedRules((c) => [...c, key]);

            return;
        }

        if (excludedRules.includes(key)) {
            setExcludedRules((c) => c.filter((k) => k !== key));

            return;
        }

        setSelectedRules((c) => [...c, key]);
    };

    const scan = async () => {
        if (scanning || selectedRules.length === 0) {
            return;
        }

        setScanning(true);
        setError(null);

        try {
            const response = await axios.post('/screener/scan', {
                rules: selectedRules,
                exclude: excludedRules,
            });
            setResult(response.data);
            setPicked([]);
            setIssuesOpen(false);
        } catch {
            setError(t('screener.scanFailed'));
        } finally {
            setScanning(false);
        }
    };

    const issueCount = result ? result.failures.length + result.skipped.length : 0;

    return (
        <AppShell title={t('screener.title')}>
            <section className="stock-panel screener">
                <header className="screener__header">
                    <div>
                        <p className="section-kicker">
                            <ScanSearch aria-hidden="true" size={16} />
                            {t('screener.kicker')}
                        </p>
                        <h2>{t('screener.heading')}</h2>
                        <p className="screener__subtitle">
                            <PoolSummary poolCount={poolCount} watchlistCount={watchlistCount} />
                            {t('screener.subtitleInstruction')}
                        </p>
                        <PoolList pool={pool} />
                    </div>
                </header>

                <div className="screener-rules" role="group" aria-label={t('screener.rulesGroupLabel')}>
                    {rules.map((rule) => {
                        const required = selectedRules.includes(rule.key);
                        const excluded = excludedRules.includes(rule.key);
                        const state = required ? '--active' : excluded ? '--excluded' : '';

                        return (
                            <button
                                aria-pressed={required || excluded}
                                className={`screener-chip ${state ? `screener-chip${state}` : ''}`}
                                key={rule.key}
                                onClick={() => cycleRule(rule.key)}
                                title={t('screener.ruleToggleHint')}
                                type="button"
                            >
                                {excluded ? t('screener.excludePrefix') : ''}{rule.label}
                            </button>
                        );
                    })}
                </div>

                <RuleNotes rules={rules} />

                <div className="screener__actions">
                    <button
                        className="button-primary"
                        disabled={scanning || selectedRules.length === 0}
                        onClick={scan}
                        type="button"
                    >
                        {scanning ? (
                            <>
                                <Loader2 aria-hidden="true" className="screener-spin" size={18} />
                                <span>{t('screener.scanning')}</span>
                            </>
                        ) : (
                            <>
                                <ScanSearch aria-hidden="true" size={18} />
                                <span>{t('screener.startScan')}</span>
                            </>
                        )}
                    </button>
                    {scanning ? (
                        <span className="screener-hint">{t('screener.firstScanHint')}</span>
                    ) : null}
                </div>

                {error ? (
                    <div className="screener-error">
                        <p className="field-error">{error}</p>
                        <button className="button-secondary" onClick={scan} type="button">
                            {t('common.retry')}
                        </button>
                    </div>
                ) : null}

                {result ? (
                    <div className="screener-result">
                        <p className="screener-result__meta">
                            {t('screener.resultMeta', { scanned: result.scanned, matched: result.results.length })}
                        </p>

                        {issueCount > 0 ? (
                            <div className="screener-issues">
                                <button
                                    aria-expanded={issuesOpen}
                                    className="panel-link"
                                    onClick={() => setIssuesOpen((open) => !open)}
                                    type="button"
                                >
                                    {t('screener.issuesSummary', { failed: result.failures.length, skipped: result.skipped.length })}
                                    {issuesOpen ? t('screener.collapse') : t('screener.expand')}
                                </button>
                                {issuesOpen ? (
                                    <div className="screener-issues__body">
                                        {result.failures.length > 0 ? (
                                            <p>
                                                {t('screener.failuresLabel')}
                                                {result.failures
                                                    .map((failure) => t('screener.failureItem', { symbol: failure.symbol, reason: failure.reason }))
                                                    .join('、')}
                                            </p>
                                        ) : null}
                                        {result.skipped.length > 0 ? (
                                            <p>{t('screener.skippedLabel')}{result.skipped.join('、')}</p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {result.results.length === 0 ? (
                            <p className="dashboard-empty">{t('screener.noMatches')}</p>
                        ) : (
                            <>
                            {/* 批次加入：走 /screener/watchlist，該端點會驗證代號確實在
                                這次掃描的命中清單中——不能讓前端傳任意 symbol 進來，
                                否則它會變成繞過個股搜尋直接建 instrument 的入口。 */}
                            {picked.length > 0 && watchlists.length > 0 && result.run_id ? (
                                <div className="screener-bulk">
                                    <span>{t('screener.selectedCount', { count: picked.length })}</span>
                                    <select onChange={(e) => setTargetList(e.target.value)} value={targetList}>
                                        {watchlists.map((w) => (
                                            <option key={w.id} value={String(w.id)}>{w.name}</option>
                                        ))}
                                    </select>
                                    <button
                                        className="button-primary"
                                        onClick={() => router.post('/screener/watchlist', {
                                            run_id: result.run_id,
                                            watchlist_id: targetList,
                                            symbols: picked,
                                        }, {
                                            preserveScroll: true,
                                            preserveState: true,
                                            onSuccess: () => setPicked([]),
                                        })}
                                        type="button"
                                    >
                                        {t('screener.addToWatchlist')}
                                    </button>
                                    <button className="screener-bulk__clear" onClick={() => setPicked([])} type="button">
                                        {t('screener.clearSelection')}
                                    </button>
                                </div>
                            ) : null}

                            <div className="screener-result-table-wrap">
                                <table className="screener-result-table">
                                    <thead>
                                        <tr>
                                            <th>
                                                <input
                                                    aria-label={t('screener.selectAll')}
                                                    checked={picked.length > 0 && picked.length === result.results.length}
                                                    onChange={(e) => setPicked(e.target.checked ? result.results.map((r) => r.symbol) : [])}
                                                    type="checkbox"
                                                />
                                            </th>
                                            <th>{t('screener.colSymbol')}</th>
                                            <th>{t('screener.colName')}</th>
                                            <th>{t('screener.colClose')}</th>
                                            <th>{t('screener.colChangePercent')}</th>
                                            <th>{t('screener.colStrength')}</th>
                                            <th>{t('screener.colSignals')}</th>
                                            <th>{t('screener.colDataDate')}</th>
                                            <th>{t('screener.colAddWatchlist')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {result.results.map((row) => (
                                            <tr key={row.symbol}>
                                                <td>
                                                    <input
                                                        aria-label={t('screener.selectRow', { symbol: row.symbol })}
                                                        checked={picked.includes(row.symbol)}
                                                        onChange={(e) => setPicked((c) =>
                                                            e.target.checked ? [...c, row.symbol] : c.filter((x) => x !== row.symbol))}
                                                        type="checkbox"
                                                    />
                                                </td>
                                                <td>
                                                    <Link
                                                        className="panel-link"
                                                        href={`/stocks/search?symbol=${encodeURIComponent(row.symbol)}`}
                                                    >
                                                        {row.symbol}
                                                    </Link>
                                                </td>
                                                <td>{row.name}</td>
                                                <td>{formatPrice(row.close)}</td>
                                                <td>
                                                    <ChangePercent value={row.change_percent} />
                                                </td>
                                                <td className="screener-strength" title={t('screener.strengthTitle', { bias: row.ma20_bias ?? '—', volume: row.volume_x ?? '—', rsi: row.rsi ?? '—' })}>
                                                    {row.strength?.toFixed?.(1) ?? '—'}
                                                </td>
                                                <td>
                                                    <div className="screener-signals">
                                                        {row.matched.map((key) => (
                                                            <span className="badge badge--admin" key={key}>
                                                                {ruleLabels[key] ?? key}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </td>
                                                <td>{row.data_as_of}</td>
                                                <td>
                                                    <AddToWatchlist
                                                        name={row.name}
                                                        symbol={row.symbol}
                                                        watchlists={watchlists}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            </>
                        )}
                    </div>
                ) : null}

                <p className="dashboard-disclaimer">
                    {t('screener.disclaimer')}
                </p>
            </section>
        </AppShell>
    );
}
