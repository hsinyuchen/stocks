import { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import { Waypoints } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

// 分組與排序用的常數。三個層級的順序是「關聯由硬到軟」，方向的 `none` 排最後
// ——那是「傳導表沒標」，不是第三種方向。
const TIER_ORDER = ['core', 'extended', 'periphery'];
const DIRECTION_ORDER = ['benefits', 'harmed', 'none'];

const TIER_LABELS = {
    core: 'topics.tierCore',
    extended: 'topics.tierExtended',
    periphery: 'topics.tierPeriphery',
};

const DIRECTION_LABELS = {
    benefits: 'topics.directionBenefits',
    harmed: 'topics.directionHarmed',
    none: 'topics.directionNone',
};

// 提及次數只有外圍層有意義：核心與延伸的 mention_count 恆為 0，印出來會被讀成
// 「這檔完全沒被提到」，而那不是它進榜的理由。用查表而不是比較運算，讓「前端
// 不重算判定」這條約束在原始碼層面就成立。
const TIER_SHOWS_MENTIONS = {
    core: false,
    extended: false,
    periphery: true,
};

/**
 * 依 `tier` × `direction` 分組。
 *
 * **只分組，不重算任何判定。** 門檻（`min_mentions`）與方向都是後端解好的欄位，
 * 前端再篩一次會讓同一份資料被兩把尺量出互相矛盾的分類。方向用**物件鍵**而不是
 * 相等比較，連「順手加個條件」的空間都不留。
 *
 * `direction` 為 null 併進 `none` 桶：外圍層本來就沒有方向，`rate_policy` 這種
 * sector 全宣告 neutral 的題材連核心都沒有方向——「無方向」不是外圍專屬狀態。
 */
function groupCandidates(candidates) {
    const buckets = {};

    candidates.forEach((candidate) => {
        const bucket = `${candidate.tier}|${candidate.direction ?? 'none'}`;

        buckets[bucket] = [...(buckets[bucket] ?? []), candidate];
    });

    const groups = [];

    TIER_ORDER.forEach((tier) => {
        DIRECTION_ORDER.forEach((direction) => {
            const rows = buckets[`${tier}|${direction}`] ?? [];

            if (rows.length > 0) {
                groups.push({ key: `${tier}|${direction}`, tier, direction, rows });
            }
        });
    });

    return groups;
}

/**
 * 五則必要說明。
 *
 * **無條件渲染、不摺疊、不做 tooltip、不隨是否選了題材出現。** 這一頁把人工策展的
 * 傳導假設、新聞共同提及、營收驗證三種完全不同強度的證據排在同一張清單上，使用者
 * 沒有這五句話就無從判斷每一列各代表什麼。放進摺疊或只在選了題材後才顯示，等於
 * 在使用者**決定要不要往下看**的當下把更正拿掉。
 *
 * 文案走 i18n 鍵：這是硬性揭露，英文介面漏一句就會變成中文露出。
 */
function TopicNotes() {
    const { t } = useI18n();

    return (
        <section aria-label={t('topics.requiredNotesLabel')} className="topic-notes">
            <p className="topic-notes__heading">{t('topics.requiredNotesLabel')}</p>
            <ul>
                <li>{t('topics.noteChainIsCurated')}</li>
                <li>{t('topics.noteDirectionIsAnnotation')}</li>
                <li>{t('topics.notePeripheryIsCoMention')}</li>
                <li>{t('topics.noteExtensionTaiwanOnly')}</li>
                <li>{t('topics.noteRevenueUnknown')}</li>
            </ul>
        </section>
    );
}

/**
 * 題材選擇。**不預設任何一個**——八個題材之間沒有「哪個比較重要」的依據。
 */
function TopicChooser({ topics, selected }) {
    const { t } = useI18n();

    return (
        <div aria-label={t('topics.chooseHeading')} className="topic-chooser" role="group">
            <p className="topic-chooser__heading">{t('topics.chooseHeading')}</p>
            <div className="topic-chooser__list">
                {topics.map((topic) => (
                    <Link
                        aria-current={topic.key === selected}
                        className={`topic-chip ${topic.key === selected ? 'topic-chip--active' : ''}`}
                        href={`/topics?topic=${encodeURIComponent(topic.key)}`}
                        key={topic.key}
                        preserveScroll
                    >
                        {topic.label}
                    </Link>
                ))}
            </div>
        </div>
    );
}

/**
 * 營收驗證徽章。**七態，七個分支，不得合併任何兩個。**
 *
 * `verified` 為 true／false 是訂單庫存框架的 C1 有結論；為 null 時
 * `reason` 說明**為什麼沒有結論**，而五個原因對使用者是五種不同的行動：
 *
 * - `not_yet`／`indeterminate`：等分析或掃描跑過就可能有答案。
 * - `stale`：序列已經完整落地，過舊的是財報的季末日——再跑一百次掃描它也不會
 *   往前走，要等下一次財報。講成「尚未累積」是把使用者留在一個不會結束的等待裡。
 * - `not_in_universe`：這一檔還不在 instruments 表。建立標的是 ingest 與搜尋的
 *   職責，本頁不代勞，所以不會有任何分析或掃描去碰它。
 * - `not_applicable`：該產業（金融保險、證券、銀行、航運、觀光餐旅等服務業）
 *   不具備一般進銷存循環，`assess()` 直接短路——**永遠不會有答案**。
 *
 * 本頁的頭號範例題材 hormuz_oil 同時踩到後兩種：核心是航運股，而九檔核心裡
 * 有六檔根本不在 instruments 表。
 *
 * `indeterminate` 放在最後：它同時是「可評級但 C1 算不出來」這一態，也是任何
 * 未預期值的落點——「沒有結論」是所有情形的誠實上位描述，落到別的分支才會說錯話。
 */
function RevenueBadge({ reason, verified }) {
    const { t } = useI18n();

    if (verified === true) {
        return (
            <span className="topic-badge topic-badge--verified">{t('topics.revenueVerified')}</span>
        );
    }

    if (verified === false) {
        return (
            <span className="topic-badge topic-badge--refuted">{t('topics.revenueRefuted')}</span>
        );
    }

    if (reason === 'not_applicable') {
        return (
            <span className="topic-badge topic-badge--not-applicable">{t('topics.revenueNotApplicable')}</span>
        );
    }

    if (reason === 'not_in_universe') {
        return (
            <span className="topic-badge topic-badge--not-in-universe">{t('topics.revenueNotInUniverse')}</span>
        );
    }

    if (reason === 'stale') {
        return (
            <span className="topic-badge topic-badge--stale">{t('topics.revenueStale')}</span>
        );
    }

    if (reason === 'not_yet') {
        return (
            <span className="topic-badge topic-badge--not-yet">{t('topics.revenueNotYet')}</span>
        );
    }

    return (
        <span className="topic-badge topic-badge--indeterminate">{t('topics.revenueIndeterminate')}</span>
    );
}

/**
 * 單一候選列。
 *
 * `showMentions` 由所屬層級查表決定，不在這裡比對 tier：提及次數是外圍層**唯一**
 * 的進榜依據，看不到它那一層就變成一份沒有理由的清單。
 */
function CandidateRow({ candidate, showMentions }) {
    const { t } = useI18n();

    return (
        <li className="topic-candidate">
            <div className="topic-candidate__id">
                <Link
                    className="topic-candidate__symbol"
                    href={`/stocks/search?symbol=${encodeURIComponent(candidate.symbol)}`}
                >
                    {candidate.symbol}
                </Link>
                {/* 標的不在 instruments 表時沒有名稱。傳導表有 30 檔而 instruments
                    只有 20 檔，缺的照樣列出——建立標的是 ingest 與搜尋的職責。 */}
                {candidate.name ? <span className="topic-candidate__name">{candidate.name}</span> : null}
            </div>
            <div className="topic-candidate__meta">
                {showMentions ? (
                    <span className="topic-candidate__mentions">
                        {t('topics.mentionCount', { count: candidate.mention_count })}
                    </span>
                ) : null}
                {candidate.sector_name ? (
                    <span className="topic-candidate__sector">
                        {t('topics.sectorLabel', { name: candidate.sector_name })}
                    </span>
                ) : null}
                {candidate.industry ? (
                    <span className="topic-candidate__industry">
                        {t('topics.industryLabel', { name: candidate.industry })}
                    </span>
                ) : null}
                <RevenueBadge reason={candidate.revenue_unknown_reason} verified={candidate.revenue_verified} />
            </div>
        </li>
    );
}

/**
 * 已選題材的內容：傳導鏈、門檻、分層候選。
 */
function TopicBoardView({ board, groups }) {
    const { t } = useI18n();

    return (
        <div className="topic-board">
            <h3 className="topic-board__title">{board.label}</h3>

            {/* 逐句照 config 原文列出，不改寫也不截斷：這是這個題材的因果假設，
                使用者要看得出它長什麼樣才能判斷要不要信。 */}
            <div className="topic-chain">
                <p className="topic-chain__heading">{t('topics.chainLabel')}</p>
                <ol>
                    {board.chain.map((sentence) => (
                        <li key={sentence}>{sentence}</li>
                    ))}
                </ol>
            </div>

            {/* 門檻藏起來，使用者無從判斷這份清單有多寬鬆。 */}
            <p className="topic-threshold">
                {t('topics.thresholdNote', { days: board.window_days, min: board.min_mentions })}
            </p>

            {/* 空清單走得到：傳導表列名的標的可能全是指數／ETF，而非個股一律不列
                （見 TopicCandidateResolver::nonStockSymbols），共同提及又都沒到門檻。
                文案只講這兩個真實成因——列名的標的不會因為「沒有資料」被過濾掉。 */}
            {groups.length === 0 ? (
                <p className="dashboard-empty">{t('topics.emptyCandidates', { days: board.window_days })}</p>
            ) : (
                groups.map((group) => (
                    <section className="topic-group" key={group.key}>
                        <h4 className="topic-group__heading">
                            <span className={`topic-tier topic-tier--${group.tier}`}>{t(TIER_LABELS[group.tier])}</span>
                            <span className={`topic-way topic-way--${group.direction}`}>
                                {t(DIRECTION_LABELS[group.direction])}
                            </span>
                            <span className="topic-group__count">
                                {t('topics.groupCount', { count: group.rows.length })}
                            </span>
                        </h4>
                        <ul className="topic-candidates">
                            {group.rows.map((candidate) => (
                                <CandidateRow
                                    candidate={candidate}
                                    key={candidate.symbol}
                                    showMentions={TIER_SHOWS_MENTIONS[group.tier]}
                                />
                            ))}
                        </ul>
                    </section>
                ))
            )}
        </div>
    );
}

export default function TopicsIndex({ topics, board, selected }) {
    const { t } = useI18n();
    const groups = useMemo(() => groupCandidates(board?.candidates ?? []), [board]);

    return (
        <AppShell title={t('topics.title')}>
            <section className="stock-panel topic-page">
                <header className="topic-page__header">
                    <p className="section-kicker">
                        <Waypoints aria-hidden="true" size={16} />
                        {t('topics.kicker')}
                    </p>
                    <h2>{t('topics.heading')}</h2>
                    <p className="topic-page__subtitle">{t('topics.chooseHint')}</p>
                </header>

                <TopicNotes />

                <TopicChooser selected={selected} topics={topics} />

                {board ? <TopicBoardView board={board} groups={groups} /> : null}
            </section>
        </AppShell>
    );
}
