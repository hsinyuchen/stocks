<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SectorDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TopicRuleRequest;
use App\Models\NewsItem;
use App\Models\TransmissionRule;
use App\Services\News\ArrayTransmissionRuleProvider;
use App\Services\News\TransmissionMapper;
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
    /** 試跑掃描最近幾則新聞。與畫面上的文案（adminTopics.preview）保持一致。 */
    private const PREVIEW_ITEMS = 200;

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

    public function edit(TransmissionRule $rule): Response
    {
        $rule->load('sectors');

        $payload = [
            'rule' => [
                'id' => $rule->id,
                'key' => $rule->key,
                'label' => $rule->label,
                'label_en' => $rule->label_en,
                'keywords' => $rule->keywords,
                'domains' => $rule->domains,
                'chain' => $rule->chain,
                'chain_en' => $rule->chain_en,
                'direction_cues' => $rule->direction_cues,
                'curator_note' => $rule->curator_note,
                'is_active' => $rule->is_active,
                'origin' => $rule->origin,
                'updated_at' => $rule->updated_at?->toIso8601String(),
                'sectors' => $rule->sectors->map(fn ($sector): array => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'name_en' => $sector->name_en,
                    'direction' => $sector->direction,
                    'direction_source' => $sector->direction_source,
                    'symbols' => $sector->symbols,
                    'curator_note' => $sector->curator_note,
                ])->all(),
            ],
            'domains' => array_keys((array) config('news.domains', [])),
            'directions' => SectorDirection::values(),
        ];

        return Inertia::render('Admin/TopicForm', $payload);
    }

    public function update(TopicRuleRequest $request, TransmissionRule $rule, SymbolCoverageChecker $coverage): RedirectResponse
    {
        // 樂觀鎖：兩位管理員同時開著編輯頁時，後存的那份會整條覆蓋前一份。
        $seen = (string) $request->input('updated_at', '');
        if ($seen !== '' && $rule->updated_at?->toIso8601String() !== $seen) {
            return back()->withErrors(['updated_at' => '這條規則已被其他人修改，請重新載入後再存一次。'])->withInput();
        }

        $sectors = $request->normalizedSectors();

        DB::transaction(function () use ($request, $rule, $sectors): void {
            // key 不接受更新：改 key 等同換一條規則。
            $rule->update($request->normalized());

            $keptIds = [];

            foreach ($sectors as $sector) {
                $id = $sector['id'];
                unset($sector['id']);

                $existing = $id === null ? null : $rule->sectors()->whereKey($id)->first();

                if ($existing === null) {
                    $keptIds[] = $rule->sectors()->create(array_merge($sector, ['direction_source' => 'human']))->id;

                    continue;
                }

                // 逐列更新、不碰 direction_source：那欄記錄「方向是誰填的」，
                // delete-recreate 會把子專案 3 的機器建議洗成人工填寫。
                $existing->update($sector);
                $keptIds[] = $existing->id;
            }

            $rule->sectors()->whereNotIn('id', $keptIds)->delete();
        });

        return $this->redirectWithCoverageWarning($rule->fresh(), $sectors, $coverage, '題材已更新。');
    }

    /**
     * 用尚未存檔的表單內容對最近的新聞試跑比對。
     *
     * 不寫 DB、不碰正式 provider：把表單內容包成一份 ArrayTransmissionRuleProvider
     * 交給臨時的 mapper。少了這個回饋，管理員只能存檔之後等下一輪 ingest
     * 才知道規則到底配不配得到東西。
     */
    public function preview(TopicRuleRequest $request): RedirectResponse
    {
        $normalized = $request->normalized();
        $rule = [
            'key' => 'preview',
            'label' => $normalized['label'],
            'when' => ['keywords' => $normalized['keywords'], 'domains' => $normalized['domains']],
            'chain' => $normalized['chain'],
            'sectors' => array_map(fn (array $sector): array => [
                'name' => $sector['name'],
                'direction' => $sector['direction'],
                'symbols' => $sector['symbols'],
            ], $request->normalizedSectors()),
        ];

        if ($normalized['direction_cues'] !== null) {
            $rule['direction_cues'] = $normalized['direction_cues'];
        }

        $mapper = new TransmissionMapper(new ArrayTransmissionRuleProvider([$rule]));

        $matched = 0;
        $samples = [];

        $items = NewsItem::query()->orderByDesc('published_at')->limit(self::PREVIEW_ITEMS)->get();

        foreach ($items as $item) {
            $hits = $mapper->map((string) $item->title, (string) $item->summary, (array) ($item->domains ?? []));

            if ($hits === []) {
                continue;
            }

            $matched++;

            if (count($samples) < 5) {
                $samples[] = (string) $item->title;
            }
        }

        return back()->with('previewResult', [
            'scanned' => $items->count(),
            'matched' => $matched,
            'samples' => $samples,
            // 試跑刻意不吃 is_active：管理員要問的是「關鍵字抓不抓得到東西」，
            // 這與規則存檔後生不生效無關；直接把停用規則回報 0 命中，反而讓
            // 停用中的草稿沒辦法調關鍵字。改用旗標讓前端另外提示「存檔後不會
            // 實際參與比對」，正式路徑 DbTransmissionRuleProvider::load() 才會
            // 過濾 is_active。
            'rule_disabled' => ! $normalized['is_active'],
        ]);
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
