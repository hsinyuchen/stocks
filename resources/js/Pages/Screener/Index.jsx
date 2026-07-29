import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import { ListChecks, Loader2, ScanSearch } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

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
    const [targetId, setTargetId] = useState(watchlists[0]?.id ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [added, setAdded] = useState(false);
    const [error, setError] = useState(null);

    if (watchlists.length === 0) {
        return (
            <Link className="panel-link" href="/watchlists">
                先建立自選清單
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
                onError: (errors) => setError(Object.values(errors)[0] ?? '加入失敗'),
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <div className="screener-add">
            <select
                aria-label={`選擇要加入 ${symbol} 的自選清單`}
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
                {added ? '已加入' : '加入'}
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
    return (
        <>
            本次可掃描 {poolCount} 支（全站標的清單，不含指數）
            {watchlistCount > 0 ? <>，其中 {watchlistCount} 支在你的自選清單</> : null}
            。
        </>
    );
}

/**
 * 完整股池清單。
 *
 * 預設收合：它是「掃描範圍」的佐證資料，不是每次都要讀的內容，但使用者需要能
 * 確認自己關心的標的有沒有被涵蓋。
 */
function PoolList({ pool }) {
    const [open, setOpen] = useState(false);

    if (pool.length === 0) {
        return null;
    }

    return (
        <div className="screener-pool">
            <button className="screener-pool__toggle" onClick={() => setOpen((v) => !v)} type="button">
                <ListChecks aria-hidden="true" size={14} />
                {open ? '收合股票清單' : `檢視全部 ${pool.length} 支股票`}
            </button>

            {open ? (
                <div className="screener-pool__body">
                    <p className="screener-pool__legend">
                        <span className="screener-pool__tag screener-pool__tag--watchlist">自選</span> 在你的自選清單中
                    </p>
                    <ul className="screener-pool__list">
                        {pool.map((entry) => (
                            <li className="screener-pool__item" key={entry.symbol}>
                                <a href={`/stocks/search?symbol=${encodeURIComponent(entry.symbol)}`}>
                                    <strong>{entry.symbol}</strong>
                                    <span>{entry.name}</span>
                                </a>
                                {entry.in_watchlist ? (
                                    <span className="screener-pool__tag screener-pool__tag--watchlist">自選</span>
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
            setError('掃描失敗，請稍後重試。');
        } finally {
            setScanning(false);
        }
    };

    const issueCount = result ? result.failures.length + result.skipped.length : 0;

    return (
        <AppShell title="選股器">
            <section className="stock-panel screener">
                <header className="screener__header">
                    <div>
                        <p className="section-kicker">
                            <ScanSearch aria-hidden="true" size={16} />
                            技術選股
                        </p>
                        <h2>選股器</h2>
                        <p className="screener__subtitle">
                            <PoolSummary poolCount={poolCount} watchlistCount={watchlistCount} />
                            點擊規則切換「必要 → 排除 → 取消」：必要條件須全部成立，命中任一排除條件即淘汰。結果依訊號強度排序。
                        </p>
                        <PoolList pool={pool} />
                    </div>
                </header>

                <div className="screener-rules" role="group" aria-label="選股規則">
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
                                title="點擊切換：必要 → 排除 → 取消"
                                type="button"
                            >
                                {excluded ? '排除 ' : ''}{rule.label}
                            </button>
                        );
                    })}
                </div>

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
                                <span>掃描中…</span>
                            </>
                        ) : (
                            <>
                                <ScanSearch aria-hidden="true" size={18} />
                                <span>開始掃描</span>
                            </>
                        )}
                    </button>
                    {scanning ? (
                        <span className="screener-hint">首次掃描需拉取資料，可能較慢</span>
                    ) : null}
                </div>

                {error ? (
                    <div className="screener-error">
                        <p className="field-error">{error}</p>
                        <button className="button-secondary" onClick={scan} type="button">
                            重試
                        </button>
                    </div>
                ) : null}

                {result ? (
                    <div className="screener-result">
                        <p className="screener-result__meta">
                            掃描 {result.scanned} 支，命中 {result.results.length} 支。
                        </p>

                        {issueCount > 0 ? (
                            <div className="screener-issues">
                                <button
                                    aria-expanded={issuesOpen}
                                    className="panel-link"
                                    onClick={() => setIssuesOpen((open) => !open)}
                                    type="button"
                                >
                                    {result.failures.length} 支失敗 / {result.skipped.length} 支略過
                                    {issuesOpen ? '（收合）' : '（展開）'}
                                </button>
                                {issuesOpen ? (
                                    <div className="screener-issues__body">
                                        {result.failures.length > 0 ? (
                                            <p>
                                                失敗：
                                                {result.failures
                                                    .map((failure) => `${failure.symbol}（${failure.reason}）`)
                                                    .join('、')}
                                            </p>
                                        ) : null}
                                        {result.skipped.length > 0 ? (
                                            <p>略過（逾時間預算）：{result.skipped.join('、')}</p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}

                        {result.results.length === 0 ? (
                            <p className="dashboard-empty">沒有符合條件的股票。</p>
                        ) : (
                            <>
                            {/* 批次加入：走 /screener/watchlist，該端點會驗證代號確實在
                                這次掃描的命中清單中——不能讓前端傳任意 symbol 進來，
                                否則它會變成繞過個股搜尋直接建 instrument 的入口。 */}
                            {picked.length > 0 && watchlists.length > 0 && result.run_id ? (
                                <div className="screener-bulk">
                                    <span>已選 {picked.length} 檔</span>
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
                                        加入自選清單
                                    </button>
                                    <button className="screener-bulk__clear" onClick={() => setPicked([])} type="button">
                                        取消選取
                                    </button>
                                </div>
                            ) : null}

                            <div className="screener-result-table-wrap">
                                <table className="screener-result-table">
                                    <thead>
                                        <tr>
                                            <th>
                                                <input
                                                    aria-label="全選"
                                                    checked={picked.length > 0 && picked.length === result.results.length}
                                                    onChange={(e) => setPicked(e.target.checked ? result.results.map((r) => r.symbol) : [])}
                                                    type="checkbox"
                                                />
                                            </th>
                                            <th>代號</th>
                                            <th>名稱</th>
                                            <th>收盤</th>
                                            <th>漲跌%</th>
                                            <th>強度</th>
                                            <th>命中訊號</th>
                                            <th>資料日期</th>
                                            <th>加自選</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {result.results.map((row) => (
                                            <tr key={row.symbol}>
                                                <td>
                                                    <input
                                                        aria-label={`選取 ${row.symbol}`}
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
                                                <td className="screener-strength" title={`乖離 ${row.ma20_bias ?? '—'}%　量能 ${row.volume_x ?? '—'}x　RSI ${row.rsi ?? '—'}`}>
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
                    技術訊號僅供參考，非投資建議。
                </p>
            </section>
        </AppShell>
    );
}
