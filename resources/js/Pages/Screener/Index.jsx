import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useMemo, useState } from 'react';
import { Loader2, ScanSearch } from 'lucide-react';
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
        router.post(
            `/watchlists/${targetId}/items`,
            { symbol, name },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setAdded(true),
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
        </div>
    );
}

export default function ScreenerIndex({ rules = [], watchlists = [], universeCount = 0 }) {
    const [selectedRules, setSelectedRules] = useState([]);
    const [scanning, setScanning] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [issuesOpen, setIssuesOpen] = useState(false);

    // rule key → label，供結果表把 matched keys 顯示成中文訊號 badge。
    const ruleLabels = useMemo(
        () => Object.fromEntries(rules.map((rule) => [rule.key, rule.label])),
        [rules],
    );

    const toggleRule = (key) => {
        setSelectedRules((current) =>
            current.includes(key) ? current.filter((k) => k !== key) : [...current, key],
        );
    };

    const scan = async () => {
        if (scanning || selectedRules.length === 0) {
            return;
        }

        setScanning(true);
        setError(null);

        try {
            const response = await axios.post('/screener/scan', { rules: selectedRules });
            setResult(response.data);
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
                            股池 {universeCount} 支 + 你的自選股。勾選規則後掃描，所有條件同時成立（AND）才入選。
                        </p>
                    </div>
                </header>

                <div className="screener-rules" role="group" aria-label="選股規則">
                    {rules.map((rule) => {
                        const active = selectedRules.includes(rule.key);

                        return (
                            <button
                                aria-pressed={active}
                                className={`screener-chip ${active ? 'screener-chip--active' : ''}`}
                                key={rule.key}
                                onClick={() => toggleRule(rule.key)}
                                type="button"
                            >
                                {rule.label}
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
                            <div className="screener-result-table-wrap">
                                <table className="screener-result-table">
                                    <thead>
                                        <tr>
                                            <th>代號</th>
                                            <th>名稱</th>
                                            <th>收盤</th>
                                            <th>漲跌%</th>
                                            <th>命中訊號</th>
                                            <th>資料日期</th>
                                            <th>加自選</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {result.results.map((row) => (
                                            <tr key={row.symbol}>
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
                        )}
                    </div>
                ) : null}

                <p className="dashboard-disclaimer">
                    技術訊號僅供參考，非投資建議。首次使用請先執行 <code>php artisan screener:warm</code> 預載股池資料。
                </p>
            </section>
        </AppShell>
    );
}
