import AppShell from '../Layouts/AppShell';

const marketCards = [
    { label: 'TWSE Momentum', value: '+1.8%', trend: 'Breadth improving' },
    { label: 'NASDAQ Futures', value: '-0.3%', trend: 'AI leaders mixed' },
    { label: 'Watchlist Alerts', value: '7', trend: '2 earnings events' },
];

const signals = [
    ['2330', 'TSMC', 'Bullish', 'Volume breakout with steady news sentiment'],
    ['NVDA', 'NVIDIA', 'Neutral', 'Momentum paused near resistance'],
    ['AAPL', 'Apple', 'Watch', 'Awaiting price confirmation'],
];

export default function Dashboard() {
    return (
        <AppShell title="Dashboard">
            <div className="dashboard-grid">
                <section className="hero-panel">
                    <div>
                        <p className="section-kicker">Today</p>
                        <h2>跨市場訊號總覽</h2>
                        <p>
                            追蹤台股與美股觀察清單、新聞脈動與 AI 分析紀錄，作為後續資料服務接入前的 PWA 工作台。
                        </p>
                    </div>
                    <div className="hero-panel__metric">
                        <span>Signal Score</span>
                        <strong>82</strong>
                    </div>
                </section>

                <section className="metric-strip" aria-label="Market highlights">
                    {marketCards.map((card) => (
                        <article className="metric-card" key={card.label}>
                            <span>{card.label}</span>
                            <strong>{card.value}</strong>
                            <small>{card.trend}</small>
                        </article>
                    ))}
                </section>

                <section className="table-panel">
                    <div className="panel-heading">
                        <div>
                            <p className="section-kicker">Signals</p>
                            <h2>觀察清單快照</h2>
                        </div>
                    </div>
                    <div className="signal-list">
                        {signals.map(([ticker, name, status, note]) => (
                            <article className="signal-row" key={ticker}>
                                <div>
                                    <strong>{ticker}</strong>
                                    <span>{name}</span>
                                </div>
                                <span className={`status-pill status-pill--${status.toLowerCase()}`}>{status}</span>
                                <p>{note}</p>
                            </article>
                        ))}
                    </div>
                </section>
            </div>
        </AppShell>
    );
}
