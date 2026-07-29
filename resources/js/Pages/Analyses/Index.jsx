import { Link, router } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';

const typeFilters = [
    { value: 'all', label: '全部' },
    { value: 'stock', label: '個股' },
    { value: 'news', label: '新聞' },
    { value: 'daily', label: '每日摘要' },
];

const kindLabels = {
    stock: '個股',
    news: '新聞',
    daily: '每日摘要',
};

const stanceLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
    watch: '觀察',
    insufficient_data: '資料不足',
};

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString('zh-TW', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function StanceChip({ value }) {
    if (!value) {
        return null;
    }

    const stance = value;

    return (
        <span className={`status-pill status-pill--${stance}`}>
            {stanceLabels[stance] ?? stance}
        </span>
    );
}

function AnalysisRow({ item }) {
    // pending / failed 的 stance 與 summary 都還不是模型判斷，不能照常呈現，
    // 否則排隊中的紀錄看起來像一則「中性」結論。
    const stance = item.status === 'completed'
        ? (item.kind === 'stock' ? item.stance : item.sentiment)
        : null;

    return (
        <article className="analysis-history-row">
            <div className="analysis-history-row__head">
                <span className="dashboard-analysis-item__type">
                    {kindLabels[item.kind] ?? item.kind}
                </span>
                {item.status === 'pending' ? (
                    <span className="status-pill status-pill--pending">分析中</span>
                ) : null}
                {item.status === 'failed' ? (
                    <span className="status-pill status-pill--failed">AI 未完成</span>
                ) : null}
                <strong className="analysis-history-row__label">
                    {item.link ? (
                        item.kind === 'stock' ? (
                            <Link href={item.link}>{item.label ?? '-'}</Link>
                        ) : (
                            <a href={item.link} rel="noopener noreferrer" target="_blank">
                                {item.label ?? '-'}
                            </a>
                        )
                    ) : (
                        item.label ?? '-'
                    )}
                </strong>
                <StanceChip value={stance} />
                {item.impact ? <span className="news-impact">影響 {item.impact}/5</span> : null}
            </div>
            {item.status === 'completed' && item.summary
                ? <Markdown className="analysis-history-row__summary">{item.summary}</Markdown>
                : null}
            <small className="analysis-history-row__meta">
                {item.provider_type ? `${item.provider_type} · ` : ''}
                {item.model}
                {' · '}
                {formatDateTime(item.created_at)}
            </small>
        </article>
    );
}

export default function AnalysesIndex({ items = [], filters = { type: 'all' } }) {
    const activeType = filters.type ?? 'all';

    const changeType = (type) => {
        router.get('/analyses', type === 'all' ? {} : { type }, { preserveScroll: true });
    };

    return (
        <AppShell title="AI 分析紀錄">
            <section className="table-panel analysis-history">
                <div className="panel-heading">
                    <div>
                        <p className="section-kicker">
                            <Bot aria-hidden="true" size={16} /> AI 分析紀錄
                        </p>
                        <h2>我的 AI 參考分析歷史</h2>
                    </div>
                </div>

                <div className="analysis-history__filters" role="tablist" aria-label="分析類型">
                    {typeFilters.map((filter) => (
                        <button
                            aria-selected={activeType === filter.value}
                            className={`filter-chip${activeType === filter.value ? ' is-active' : ''}`}
                            key={filter.value}
                            onClick={() => changeType(filter.value)}
                            role="tab"
                            type="button"
                        >
                            {filter.label}
                        </button>
                    ))}
                </div>

                {items.length === 0 ? (
                    <p className="dashboard-empty">尚未有 AI 分析紀錄。前往個股或新聞頁面，用你的模型產生分析。</p>
                ) : (
                    <div className="analysis-history__list">
                        {items.map((item) => (
                            <AnalysisRow item={item} key={`${item.kind}-${item.id}`} />
                        ))}
                    </div>
                )}
            </section>
        </AppShell>
    );
}
