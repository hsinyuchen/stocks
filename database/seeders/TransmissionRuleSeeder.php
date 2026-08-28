<?php

namespace Database\Seeders;

use App\Models\TransmissionRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 題材傳導規則的首次 bootstrap。
 *
 * 以 key 做 firstOrCreate，**永不 update**：正式站的規則是管理員策展的成果，
 * updateOrCreate 會讓任何一次 db:seed 把它洗掉。代價是資料檔日後的修正不會
 * 自動進到正式站——那類修正要寫具名 data migration。
 *
 * 規則已存在就整條跳過（含板塊）。唯一的例外是「規則在、板塊為零」：那代表
 * 上一次 seed 中途失敗，靜默跳過會讓該規則永遠壞著，因此直接中止。
 */
class TransmissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<array<string, mixed>> $data */
        $data = require database_path('seeders/data/transmission_rules.php');

        DB::transaction(function () use ($data): void {
            foreach ($data as $index => $definition) {
                $this->seedRule($definition, $index);
            }
        });
    }

    /** @param array<string, mixed> $definition */
    private function seedRule(array $definition, int $index): void
    {
        $key = (string) ($definition['key'] ?? '');

        if ($key === '') {
            throw new RuntimeException("種子資料第 {$index} 筆缺少 key。");
        }

        $existing = TransmissionRule::where('key', $key)->first();

        if ($existing !== null) {
            if ($existing->sectors()->count() === 0) {
                throw new RuntimeException("規則 {$key} 已存在但沒有任何板塊，可能是上次 seed 中途失敗；請先修復資料再重跑。");
            }

            return;
        }

        $cues = $definition['direction_cues'] ?? null;

        $rule = TransmissionRule::create([
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'label_en' => $definition['label_en'] ?? null,
            'keywords' => array_values((array) ($definition['when']['keywords'] ?? [])),
            'domains' => array_values((array) ($definition['when']['domains'] ?? [])),
            'chain' => array_values((array) ($definition['chain'] ?? [])),
            'chain_en' => $definition['chain_en'] ?? null,
            'direction_cues' => $this->normalizeCues($cues),
            'curator_note' => $definition['curator_note'] ?? null,
            'origin' => 'seed',
            'is_active' => true,
            'sort_order' => $index,
        ]);

        foreach (array_values((array) ($definition['sectors'] ?? [])) as $order => $sector) {
            $rule->sectors()->create([
                'name' => (string) ($sector['name'] ?? ''),
                'name_en' => $sector['name_en'] ?? null,
                'direction' => (string) ($sector['direction'] ?? 'neutral'),
                'direction_source' => 'human',
                'symbols' => array_values((array) ($sector['symbols'] ?? [])),
                'curator_note' => $sector['curator_note'] ?? null,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * 兩邊皆空的 cues 必須存成 NULL。
     *
     * TransmissionMapper 對 NULL／缺鍵回 forward（維持宣告方向），
     * 但對 {"forward":[],"reverse":[]} 兩邊都不命中，結果是 unknown，
     * 會把整組板塊降為中性——存錯值等於讓那個題材永遠沒有方向。
     *
     * @return array{forward: list<string>, reverse: list<string>}|null
     */
    private function normalizeCues(mixed $cues): ?array
    {
        $cues = is_array($cues) ? $cues : [];
        $forward = array_values(array_filter((array) ($cues['forward'] ?? [])));
        $reverse = array_values(array_filter((array) ($cues['reverse'] ?? [])));

        if ($forward === [] && $reverse === []) {
            return null;
        }

        return ['forward' => $forward, 'reverse' => $reverse];
    }
}
