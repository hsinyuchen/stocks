<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TransmissionRule;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 題材傳導規則維護。
 *
 * 規則是全站共用資料（新聞頁的傳導區塊、儀表板、題材候選都吃它），
 * 一人修改會影響所有使用者，因此限 admin。
 */
class TopicRuleController extends Controller
{
    public function index(): Response
    {
        $rules = TransmissionRule::query()
            ->withCount('sectors')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (TransmissionRule $rule): array => [
                'id' => $rule->id,
                'key' => $rule->key,
                'label' => $rule->label,
                'keyword_count' => count((array) $rule->keywords),
                'sector_count' => $rule->sectors_count,
                'is_active' => $rule->is_active,
                'origin' => $rule->origin,
            ])
            ->all();

        // 陣列先落區域變數再交給 Inertia::render：在 render() 的陣列字面值裡多做
        // 一次方法呼叫，實測會讓 PHP 8.4 Windows 版在測試中途 zend_mm_heap corrupted。
        return Inertia::render('Admin/Topics', ['rules' => $rules]);
    }

    public function destroy(TransmissionRule $rule): RedirectResponse
    {
        // 內建規則刪不掉是刻意的：下次 db:seed 會把它長回來，管理員會以為刪掉了。
        if ($rule->isSeeded()) {
            return back()->withErrors(['rule' => '內建題材無法刪除，請改為停用。']);
        }

        $rule->delete();

        return back()->with('success', '題材已刪除。');
    }
}
