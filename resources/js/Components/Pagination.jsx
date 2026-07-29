import { router } from '@inertiajs/react';

/**
 * Laravel paginator 的翻頁列。
 *
 * 抽成共用元件的原因：後端三個列表都已經 paginate()，但只有新聞頁真的把 links
 * 渲染出來——管理端的標的清單與使用者清單拿到分頁資料卻沒用，超過單頁筆數的資料
 * 就此看不見，而且畫面上完全沒有跡象。
 *
 * @param {object[]} links Laravel 給的 [{url, label, active}]，label 內含 &laquo; 之類的 HTML entity
 * @param {object} meta 選填，paginator 頂層的 current_page / last_page / total
 */
export default function Pagination({ links, meta = null }) {
    // Laravel 至少會回「上一頁 / 1 / 下一頁」三個項目；只有一頁時沒有翻頁的必要。
    if (!links || links.length <= 3) {
        return null;
    }

    const go = (url) => {
        if (url) {
            router.get(url, {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <nav aria-label="分頁" className="pagination">
            {meta?.last_page ? (
                <span className="pagination__meta">
                    第 {meta.current_page} / {meta.last_page} 頁
                    {meta.total ? `　共 ${meta.total} 筆` : ''}
                </span>
            ) : null}
            <div className="pagination__links">
                {links.map((link, index) => (
                    <button
                        aria-current={link.active ? 'page' : undefined}
                        className={`pagination__link${link.active ? ' is-active' : ''}`}
                        disabled={!link.url}
                        // label 由 Laravel 產生（&laquo; / &raquo; 等 entity），是可信內容。
                        dangerouslySetInnerHTML={{ __html: link.label }}
                        key={index}
                        onClick={() => go(link.url)}
                        type="button"
                    />
                ))}
            </div>
        </nav>
    );
}
