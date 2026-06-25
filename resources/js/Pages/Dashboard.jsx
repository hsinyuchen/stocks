import AppShell from '../Layouts/AppShell';

const marketCards = [
    { label: '台股動能', value: '+1.8%', trend: '市場廣度改善中' },
    { label: 'NASDAQ 期貨', value: '-0.3%', trend: 'AI 權值股表現分歧' },
    { label: '自選提醒', value: '7', trend: '含 2 筆財報事件' },
];

const signalLabels = {
    bullish: '偏多',
    neutral: '中性',
    watch: '觀察',
};

const signals = [
    ['2330', '台積電', 'bullish', '量能突破，新聞情緒維持穩定。'],
    ['NVDA', 'NVIDIA', 'neutral', '動能在壓力區附近暫停，等待方向確認。'],
    ['AAPL', 'Apple', 'watch', '等待價格重新站穩關鍵區間。'],
];

export default function Dashboard() {
    return (
        <AppShell title="市場儀表板">
            <div className="dashboard-grid">
                <section className="hero-panel">
                    <div>
                        <p className="section-kicker">今日重點</p>
                        <h2>整合市場、新聞與 AI 參考分析</h2>
                        <p>
                            這裡彙整台灣與美國市場概況、自選清單提醒、個股技術訊號與 LLM 參考分析，方便快速掌握需要追蹤的投資議題。
                        </p>
                    </div>
                    <div className="hero-panel__metric">
                        <span>訊號分數</span>
                        <strong>82</strong>
                    </div>
                </section>

                <section className="metric-strip" aria-label="市場重點摘要">
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
                            <p className="section-kicker">交易訊號</p>
                            <h2>自選清單焦點</h2>
                        </div>
                    </div>
                    <div className="signal-list">
                        {signals.map(([ticker, name, status, note]) => (
                            <article className="signal-row" key={ticker}>
                                <div>
                                    <strong>{ticker}</strong>
                                    <span>{name}</span>
                                </div>
                                <span className={`status-pill status-pill--${status}`}>{signalLabels[status]}</span>
                                <p>{note}</p>
                            </article>
                        ))}
                    </div>
                </section>
            </div>
        </AppShell>
    );
}