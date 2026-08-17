import { lazy, Suspense } from 'react';
import { useI18n } from '../i18n';

// Lazy so react-markdown is only fetched on views that render LLM output,
// keeping it out of the main bundle (consistent with the chart lazy-loading).
const ReactMarkdown = lazy(() => import('react-markdown'));

/**
 * Render LLM-produced Markdown text safely (no raw HTML) and formatted.
 * Falls back to plain text while the renderer loads.
 */
export default function Markdown({ children, className = '' }) {
    const { t } = useI18n();
    const text = children == null ? '' : String(children);

    /*
     * 模型輸出仍是未受信任的內容，即使不允許 raw HTML 也還有兩個出口：
     *
     * - 遠端圖片會在使用者一開啟頁面時就對外發出請求，等於把瀏覽時機與 IP 洩漏給
     *   模型指定的主機。這裡只留替代文字，不載入圖片。
     * - 連結加上 noopener/noreferrer 阻斷 window.opener 與 referrer，nofollow 則是
     *   不替模型憑空產生的網址背書。
     */
    const components = {
        a: ({ children: linkChildren, href }) => (
            <a href={href} rel="noopener noreferrer nofollow" target="_blank">{linkChildren}</a>
        ),
        img: ({ alt }) => <em className="markdown-image-blocked">{alt || t('components.imageBlocked')}</em>,
    };

    if (text.trim() === '') {
        return null;
    }

    return (
        <div className={`markdown-body ${className}`.trim()}>
            <Suspense fallback={<p>{text}</p>}>
                <ReactMarkdown components={components}>{text}</ReactMarkdown>
            </Suspense>
        </div>
    );
}
