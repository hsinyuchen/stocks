<?php

namespace App\Services\News;

use App\Contracts\TransmissionRuleProvider;
use App\Models\TransmissionRule;
use App\Models\TransmissionSector;

/**
 * 從資料庫取題材傳導規則，組回搬遷前 config('news.transmission') 的形狀。
 *
 * 在容器裡註冊為 scoped：同一次請求／job 內共用一份，跨請求重建。實例內的
 * 記憶化就足以讓 news:ingest 的數千次 map() 只查一次 DB，因此**不做跨 process
 * 的共享快取**——那會帶來失效時序問題（commit 與 forget 之間崩潰就留下永久髒值），
 * 而正式站的 database cache store 也不支援 tag，清多語系鍵只能硬編碼列舉。
 */
class DbTransmissionRuleProvider implements TransmissionRuleProvider
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    /** @return list<array<string, mixed>> */
    public function rules(string $locale = 'zh'): array
    {
        return $this->memo[$locale] ??= $this->load($locale);
    }

    /** @return list<array<string, mixed>> */
    private function load(string $locale): array
    {
        return TransmissionRule::query()
            ->where('is_active', true)
            ->with('sectors')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (TransmissionRule $rule): array => $this->present($rule, $locale))
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(TransmissionRule $rule, string $locale): array
    {
        $out = [
            'key' => (string) $rule->key,
            'label' => $this->text($locale, $rule->label_en, (string) $rule->label),
            'when' => [
                'keywords' => array_values((array) $rule->keywords),
                'domains' => array_values((array) $rule->domains),
            ],
        ];

        // direction_cues 只在有值時輸出：缺鍵維持宣告方向，空物件會讓整組降為中性。
        if (is_array($rule->direction_cues) && $rule->direction_cues !== []) {
            $out['direction_cues'] = [
                'forward' => array_values((array) ($rule->direction_cues['forward'] ?? [])),
                'reverse' => array_values((array) ($rule->direction_cues['reverse'] ?? [])),
            ];
        }

        $out['chain'] = $this->list($locale, $rule->chain_en, (array) $rule->chain);
        $out['sectors'] = $rule->sectors
            ->map(fn (TransmissionSector $sector): array => [
                'name' => $this->text($locale, $sector->name_en, (string) $sector->name),
                'direction' => (string) $sector->direction,
                'symbols' => array_values((array) $sector->symbols),
            ])
            ->all();

        return $out;
    }

    /** 英文欄位留空時逐欄退回中文，不是整條規則二選一。 */
    private function text(string $locale, ?string $translated, string $base): string
    {
        return $locale === 'en' && $translated !== null && trim($translated) !== '' ? $translated : $base;
    }

    /**
     * @param  array<int, mixed>|null  $translated
     * @param  array<int, mixed>  $base
     * @return list<string>
     */
    private function list(string $locale, ?array $translated, array $base): array
    {
        $chosen = $locale === 'en' && $translated !== null && $translated !== [] ? $translated : $base;

        return array_values(array_map(strval(...), $chosen));
    }
}
