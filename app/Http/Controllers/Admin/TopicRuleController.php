<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SectorDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TopicRuleRequest;
use App\Models\TransmissionRule;
use App\Services\Topics\SymbolCoverageChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    public function create(): Response
    {
        $payload = [
            'rule' => null,
            'domains' => array_keys((array) config('news.domains', [])),
            'directions' => SectorDirection::values(),
        ];

        return Inertia::render('Admin/TopicForm', $payload);
    }

    public function store(TopicRuleRequest $request, SymbolCoverageChecker $coverage): RedirectResponse
    {
        $sectors = $request->normalizedSectors();

        $rule = DB::transaction(function () use ($request, $sectors): TransmissionRule {
            $rule = TransmissionRule::create(array_merge($request->normalized(), [
                'key' => (string) $request->input('key'),
                // origin 由伺服器決定，不接受表單輸入：只有 seeder 能建立 seed 規則。
                'origin' => 'manual',
                'sort_order' => (int) TransmissionRule::max('sort_order') + 1,
            ]));

            foreach ($sectors as $sector) {
                unset($sector['id']);
                $rule->sectors()->create(array_merge($sector, ['direction_source' => 'human']));
            }

            return $rule;
        });

        return $this->redirectWithCoverageWarning($rule, $sectors, $coverage, '題材已建立。');
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

    /**
     * @param  list<array<string, mixed>>  $sectors
     */
    private function redirectWithCoverageWarning(
        TransmissionRule $rule,
        array $sectors,
        SymbolCoverageChecker $coverage,
        string $status,
    ): RedirectResponse {
        $symbols = array_merge(...array_map(fn (array $s): array => $s['symbols'], $sectors)) ?: [];
        $missing = $coverage->missing($symbols);

        $redirect = redirect()->route('admin.topics.edit', $rule)->with('success', $status);

        return $missing === []
            ? $redirect
            : $redirect->with('warning', '已存檔。下列代號查無標的或近 30 日無行情，使用者點進去會是空白：'.implode('、', $missing));
    }
}
