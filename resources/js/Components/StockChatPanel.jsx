import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { MessageCircle, Send, Trash2 } from 'lucide-react';
import Markdown from './Markdown';
import { FailureNote, QueueStalledHint, formatDateTime } from './AnalysisFeedback';

/** 空狀態的示範問題。點了只填進輸入框，不自動送出——送出要花錢，得由使用者決定。 */
const SUGGESTIONS = [
    '這檔最近的技術面怎麼樣？',
    '籌碼面最近有什麼變化？',
    '目前的估值算貴還是便宜？',
];

/** 提問長度上限，與後端 StockChatController 的驗證規則一致。 */
const MAX_QUESTION = 500;

/**
 * job 自己炸掉時只來得及改狀態，來不及寫失敗原因。沒有這個 fallback，那一輪會
 * 顯示成一張沒有任何說明的空卡片。
 */
const UNKNOWN_FAILURE = {
    message: '這一題沒有完成。',
    hint: '請稍後再問一次；若持續發生，請至「系統設定」確認模型設定。',
};

/**
 * 個股 AI 投資顧問問答。
 *
 * 與個股分析共用同一個 3 秒輪詢（由 Search.jsx 統一帶 analyses + chatTurns），
 * 這裡只負責呈現與送出。
 */
export default function StockChatPanel({ instrument, turns = [], llmProviders = [], stalled = false }) {
    const providers = llmProviders ?? [];
    const defaultProvider = providers.find((provider) => provider.is_default) ?? providers[0] ?? null;

    // errorBag 讓問答與「產生分析」表單的 model / llm_provider_setting_id 錯誤不會
    // 互相汙染——兩個表單共用欄位名稱，不分流的話訊息會同時出現在兩邊。
    const form = useForm('stockChat', {
        question: '',
        llm_provider_setting_id: defaultProvider ? defaultProvider.id : '',
        model: defaultProvider ? defaultProvider.model : '',
    });

    const listRef = useRef(null);
    const textareaRef = useRef(null);
    const hasPendingTurn = turns.some((turn) => turn.status === 'pending');
    const lastStatus = turns.length > 0 ? turns[turns.length - 1].status : null;

    /*
     * 依賴 lastStatus 而不只是 turns.length：「思考中 → 有答案」那一刻長度沒變，
     * 但正是最需要捲到底的時候。
     */
    useEffect(() => {
        const el = listRef.current;

        if (!el) {
            return;
        }

        // 只在使用者已經接近底部時才自動捲動，否則會打斷正在往回讀舊訊息的人。
        const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 160;

        if (nearBottom || lastStatus === 'pending') {
            el.scrollTop = el.scrollHeight;
        }
    }, [turns.length, lastStatus]);

    if (!instrument) {
        return null;
    }

    const question = form.data.question;
    const canSend = !form.processing && !hasPendingTurn && question.trim().length >= 2;

    const submit = (event) => {
        event.preventDefault();

        if (!canSend) {
            return;
        }

        form.post(`/stocks/${instrument.id}/chat`, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('question', '');

                if (textareaRef.current) {
                    textareaRef.current.style.height = 'auto';
                }
            },
        });
    };

    const onQuestionChange = (event) => {
        form.setData('question', event.target.value);

        const el = event.target;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
    };

    return (
        <section className="stock-panel stock-chat">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">AI 投資顧問</p>
                    <h2>{instrument.symbol} 問答</h2>
                </div>
                <MessageCircle aria-hidden="true" size={22} />
            </div>

            <p className="stock-chat__scope">
                只回答與這一檔相關的問題，含影響它的產業鏈與總體事件。回覆僅供研究參考，非投資建議。
            </p>

            {stalled ? <QueueStalledHint /> : null}

            <div aria-live="polite" className="stock-chat__scroll" ref={listRef}>
                {turns.length === 0 ? (
                    <div className="stock-chat__empty">
                        <strong>還沒有對話</strong>
                        <span>試著問問看：</span>
                        <div className="stock-chat__suggestions">
                            {SUGGESTIONS.map((text) => (
                                <button
                                    className="stock-chat__suggestion"
                                    key={text}
                                    onClick={() => form.setData('question', text)}
                                    type="button"
                                >
                                    {text}
                                </button>
                            ))}
                        </div>
                    </div>
                ) : (
                    turns.map((turn) => <ChatTurn key={turn.id} turn={turn} />)
                )}
            </div>

            {providers.length === 0 ? (
                <p className="stock-chat__no-provider">
                    尚未設定 AI 模型，無法使用問答。請先到 <Link href="/settings">系統設定</Link> 新增一組。
                </p>
            ) : (
                <form className="stock-chat__composer" onSubmit={submit}>
                    <label className="form-field">
                        <span>向 AI 投資顧問提問</span>
                        <textarea
                            disabled={hasPendingTurn || form.processing}
                            maxLength={MAX_QUESTION}
                            onChange={onQuestionChange}
                            placeholder="例如：記憶體報價下滑會怎麼影響它？"
                            ref={textareaRef}
                            rows={3}
                            value={question}
                        />
                        {form.errors.question ? <p className="field-error">{form.errors.question}</p> : null}
                    </label>

                    {providers.length > 1 ? (
                        <details className="stock-chat__model">
                            <summary>使用其他模型</summary>
                            <label className="form-field">
                                <span>模型設定</span>
                                <select
                                    onChange={(event) => {
                                        const id = Number(event.target.value);
                                        const picked = providers.find((provider) => provider.id === id);
                                        form.setData('llm_provider_setting_id', event.target.value);

                                        if (picked) {
                                            form.setData('model', picked.model);
                                        }
                                    }}
                                    value={form.data.llm_provider_setting_id}
                                >
                                    {providers.map((provider) => (
                                        <option key={provider.id} value={provider.id}>
                                            {provider.display_name}（{provider.model}）
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </details>
                    ) : null}

                    <div className="stock-chat__actions">
                        <span className="stock-chat__count">{question.length}/{MAX_QUESTION}</span>
                        <button className="button-secondary" disabled={!canSend} type="submit">
                            <Send aria-hidden="true" size={18} />
                            <span>送出提問</span>
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}

function ChatTurn({ turn }) {
    return (
        <article className="chat-turn">
            {/* 使用者輸入一律純文字，絕不過 Markdown。 */}
            <p className="chat-turn__question">{turn.question}</p>
            <ChatAnswer turn={turn} />
        </article>
    );
}

function ChatAnswer({ turn }) {
    if (turn.status === 'pending') {
        return (
            <div className="chat-turn__answer chat-turn__pending">
                <span aria-hidden="true" className="chat-turn__dots"><i /><i /><i /></span>
                <span>正在讀取行情與新聞並產生回答，通常需要數十秒。</span>
                <CancelTurnButton turnId={turn.id} />
            </div>
        );
    }

    if (turn.status === 'failed') {
        return (
            <div className="chat-turn__answer chat-turn__answer--failed">
                <FailureNote failure={turn.metadata?.failure ?? UNKNOWN_FAILURE} />
                <ChatTurnMeta turn={turn} />
            </div>
        );
    }

    const refused = Boolean(turn.metadata?.refused);

    return (
        <div className={`chat-turn__answer${refused ? ' chat-turn__answer--refused' : ''}`}>
            <Markdown>{turn.answer ?? ''}</Markdown>
            <ChatTurnMeta turn={turn} />
        </div>
    );
}

function ChatTurnMeta({ turn }) {
    return (
        <small className="chat-turn__meta">
            <span>{formatDateTime(turn.created_at)}</span>
            <span>{turn.provider_type} · {turn.model}</span>
            <CancelTurnButton turnId={turn.id} />
        </small>
    );
}

/**
 * 就地二次確認，不用 window.confirm——那是阻塞式對話框，且與整體介面斷裂。
 * pending 的也能刪：job 找不到紀錄就直接結束，等同取消這次提問。
 */
function CancelTurnButton({ turnId }) {
    const [armed, setArmed] = useState(false);
    const form = useForm();

    if (!armed) {
        return (
            <button
                aria-label="刪除這一則問答"
                className="analysis-item__delete"
                onClick={() => setArmed(true)}
                title="刪除這一則問答"
                type="button"
            >
                <Trash2 aria-hidden="true" size={14} />
            </button>
        );
    }

    return (
        <span className="analysis-item__confirm">
            <button
                className="analysis-item__confirm-yes"
                disabled={form.processing}
                onClick={() => form.delete(`/stocks/chat/${turnId}`, { preserveScroll: true })}
                type="button"
            >
                確認刪除
            </button>
            <button onClick={() => setArmed(false)} type="button">取消</button>
        </span>
    );
}
