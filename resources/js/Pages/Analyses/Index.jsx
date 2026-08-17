import { Link, router } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';
import { useI18n } from '../../i18n';

// 值 → i18n key 映射；render 端以 t(key) 取當前語言字串。
const typeFilters = [
    { value: 'all', labelKey: 'analysesPage.filterAll' },
    { value: 'stock', labelKey: 'analysesPage.filterStock' },
    { value: 'news', labelKey: 'analysesPage.filterNews' },
    { value: 'daily', labelKey: 'analysesPage.filterDaily' },
];

const kindLabelKeys = {
    stock: 'analysesPage.kindStock',
    news: 'analysesPage.kindNews',
    daily: 'analysesPage.kindDaily',
};

const stanceLabelKeys = {
    bullish: 'analysesPage.stanceBullish',
    bearish: 'analysesPage.stanceBearish',
    neutral: 'analysesPage.stanceNeutral',
    watch: 'analysesPage.stanceWatch',
    insufficient_data: 'analysesPage.stanceInsufficientData',
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
    const { t } = useI18n();

    if (!value) {
        return null;
    }

    const stance = value;

    return (
        <span className={`status-pill status-pill--${stance}`}>
            {stanceLabelKeys[stance] ? t(stanceLabelKeys[stance]) : stance}
        </span>
    );
}

function AnalysisRow({ item }) {
    const { t } = useI18n();
    // pending / failed 的 stance 與 summary 都還不是模型判斷，不能照常呈現，
    // 否則排隊中的紀錄看起來像一則「中性」結論。
    const stance = item.status === 'completed'
        ? (item.kind === 'stock' ? item.stance : item.sentiment)
        : null;

    return (
        <article className="analysis-history-row">
            <div className="analysis-history-row__head">
                <span className="dashboard-analysis-item__type">
                    {kindLabelKeys[item.kind] ? t(kindLabelKeys[item.kind]) : item.kind}
                </span>
                {item.status === 'pending' ? (
                    <span className="status-pill status-pill--pending">{t('analysesPage.statusPending')}</span>
                ) : null}
                {item.status === 'failed' ? (
                    <span className="status-pill status-pill--failed">{t('analysesPage.statusFailed')}</span>
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
                {item.impact ? <span className="news-impact">{t('analysesPage.impact', { impact: item.impact })}</span> : null}
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
    const { t } = useI18n();
    const activeType = filters.type ?? 'all';

    const changeType = (type) => {
        router.get('/analyses', type === 'all' ? {} : { type }, { preserveScroll: true });
    };

    return (
        <AppShell title={t('analysesPage.title')}>
            <section className="table-panel analysis-history">
                <div className="panel-heading">
                    <div>
                        <p className="section-kicker">
                            <Bot aria-hidden="true" size={16} /> {t('analysesPage.kicker')}
                        </p>
                        <h2>{t('analysesPage.heading')}</h2>
                    </div>
                </div>

                <div className="analysis-history__filters" role="tablist" aria-label={t('analysesPage.filterGroupLabel')}>
                    {typeFilters.map((filter) => (
                        <button
                            aria-selected={activeType === filter.value}
                            className={`filter-chip${activeType === filter.value ? ' is-active' : ''}`}
                            key={filter.value}
                            onClick={() => changeType(filter.value)}
                            role="tab"
                            type="button"
                        >
                            {t(filter.labelKey)}
                        </button>
                    ))}
                </div>

                {items.length === 0 ? (
                    <p className="dashboard-empty">{t('analysesPage.empty')}</p>
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
