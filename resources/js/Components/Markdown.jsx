import { lazy, Suspense } from 'react';

// Lazy so react-markdown is only fetched on views that render LLM output,
// keeping it out of the main bundle (consistent with the chart lazy-loading).
const ReactMarkdown = lazy(() => import('react-markdown'));

/**
 * Render LLM-produced Markdown text safely (no raw HTML) and formatted.
 * Falls back to plain text while the renderer loads.
 */
export default function Markdown({ children, className = '' }) {
    const text = children == null ? '' : String(children);

    if (text.trim() === '') {
        return null;
    }

    return (
        <div className={`markdown-body ${className}`.trim()}>
            <Suspense fallback={<p>{text}</p>}>
                <ReactMarkdown>{text}</ReactMarkdown>
            </Suspense>
        </div>
    );
}
