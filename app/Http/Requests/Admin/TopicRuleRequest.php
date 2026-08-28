<?php

namespace App\Http\Requests\Admin;

use App\Enums\SectorDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 題材規則的新增與編輯共用驗證。
 *
 * key 只在新增時收：改 key 等同換一條規則，而舊 key 會在下次 db:seed 被重建
 * 成重複規則；同一個理由讓 InstrumentController 也不接受改 symbol。
 *
 * origin 一律不從表單收，由 controller 強制寫 manual。
 */
class TopicRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 路由已掛 ['auth','admin']，這裡不重複判斷。
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->route('rule') === null;

        return [
            'key' => $isCreate
                ? ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('transmission_rules', 'key')]
                : ['nullable'],
            'label' => ['required', 'string', 'max:100'],
            'label_en' => ['nullable', 'string', 'max:100'],
            'curator_note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],

            'keywords' => ['required', 'array', 'min:1', 'max:200'],
            'keywords.*' => ['required', 'string', 'max:60'],

            'domains' => ['array'],
            'domains.*' => ['string', Rule::in(array_keys((array) config('news.domains', [])))],

            'chain' => ['required', 'array', 'min:1', 'max:10'],
            'chain.*' => ['required', 'string', 'max:200'],
            'chain_en' => ['array', 'max:10'],
            'chain_en.*' => ['string', 'max:200'],

            'direction_cues.forward' => ['array', 'max:50'],
            'direction_cues.forward.*' => ['string', 'max:60'],
            'direction_cues.reverse' => ['array', 'max:50'],
            'direction_cues.reverse.*' => ['string', 'max:60'],

            'sectors' => ['required', 'array', 'min:1', 'max:20'],
            'sectors.*.id' => ['nullable', 'integer'],
            'sectors.*.name' => ['required', 'string', 'max:60'],
            'sectors.*.name_en' => ['nullable', 'string', 'max:60'],
            'sectors.*.direction' => ['required', Rule::in(SectorDirection::values())],
            'sectors.*.curator_note' => ['nullable', 'string', 'max:2000'],
            'sectors.*.symbols' => ['array', 'max:30'],
            'sectors.*.symbols.*' => ['string', 'max:16', 'regex:/^[A-Za-z0-9.\-\^]{1,16}$/'],
        ];
    }

    /**
     * 正規化後的規則欄位（不含 sectors）。
     *
     * 關鍵字轉小寫是為了去重正確：NewsClassifier 比對時本來就會對關鍵字做
     * strtolower，所以 'Hormuz' 配得到新聞——但不正規化就會與 'hormuz' 並存成
     * 兩筆，管理頁上看起來像重複資料。
     *
     * @return array<string, mixed>
     */
    public function normalized(): array
    {
        $keywords = [];
        foreach ((array) $this->input('keywords', []) as $keyword) {
            $normalized = mb_strtolower(trim((string) $keyword));
            if ($normalized !== '' && ! in_array($normalized, $keywords, true)) {
                $keywords[] = $normalized;
            }
        }

        $forward = $this->cueList('direction_cues.forward');
        $reverse = $this->cueList('direction_cues.reverse');

        return [
            'label' => (string) $this->input('label'),
            'label_en' => $this->nullableString('label_en'),
            'keywords' => $keywords,
            'domains' => array_values(array_unique(array_map(strval(...), (array) $this->input('domains', [])))),
            'chain' => $this->textList('chain'),
            'chain_en' => $this->textList('chain_en') ?: null,
            // 兩邊皆空必須是 NULL：空物件會讓 TransmissionMapper 回 unknown，
            // 整組板塊被降為中性。
            'direction_cues' => ($forward === [] && $reverse === [])
                ? null
                : ['forward' => $forward, 'reverse' => $reverse],
            'curator_note' => $this->nullableString('curator_note'),
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function normalizedSectors(): array
    {
        $out = [];

        foreach (array_values((array) $this->input('sectors', [])) as $order => $sector) {
            $symbols = [];
            foreach ((array) ($sector['symbols'] ?? []) as $symbol) {
                $upper = mb_strtoupper(trim((string) $symbol));
                if ($upper !== '' && ! in_array($upper, $symbols, true)) {
                    $symbols[] = $upper;
                }
            }

            $out[] = [
                'id' => isset($sector['id']) ? (int) $sector['id'] : null,
                'name' => (string) ($sector['name'] ?? ''),
                'name_en' => trim((string) ($sector['name_en'] ?? '')) ?: null,
                'direction' => (string) ($sector['direction'] ?? 'neutral'),
                'symbols' => $symbols,
                'curator_note' => trim((string) ($sector['curator_note'] ?? '')) ?: null,
                'sort_order' => $order,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function textList(string $field): array
    {
        $out = [];
        foreach ((array) $this->input($field, []) as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function cueList(string $field): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($cue): string => mb_strtolower(trim((string) $cue)),
            (array) $this->input($field, []),
        ))));
    }

    private function nullableString(string $field): ?string
    {
        return trim((string) $this->input($field, '')) ?: null;
    }
}
