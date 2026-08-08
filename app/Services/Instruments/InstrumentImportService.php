<?php

namespace App\Services\Instruments;

use App\Models\Instrument;
use App\Support\MarketResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * 由 CSV / XLSX 匯入標的清單。
 *
 * xlsx 直接用 ZipArchive + SimpleXML 解析，不引入 phpoffice/phpspreadsheet：
 * 匯入需求只是「代號、名稱」兩欄的簡單清單，為此加一個重量級生產依賴不划算。
 * 代價是不支援公式、日期序號與多工作表——只讀第一張工作表的純文字。
 */
class InstrumentImportService
{
    /** 允許的欄位標題（小寫比對），第一欄為代號、第二欄為名稱。 */
    private const SYMBOL_HEADERS = ['symbol', '代號', '股票代號', 'ticker', 'code'];

    private const NAME_HEADERS = ['name', '名稱', '股票名稱', '公司名稱', 'company'];

    /**
     * 解析上傳檔案為 [symbol, name] 列表。
     *
     * @return array{rows: list<array{symbol: string, name: string}>, errors: list<string>}
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $raw = match ($extension) {
            'csv', 'txt' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            default => throw new RuntimeException("不支援的檔案格式：{$extension}（僅支援 csv 與 xlsx）"),
        };

        return $this->normalize($raw);
    }

    /**
     * 寫入標的清單。
     *
     * mode = append：只新增，既有代號直接跳過（不覆寫名稱）。
     * mode = replace：先移除「沒有被任何使用者資料參照」的標的，再匯入。
     *
     * replace 不會刪除被自選清單、持倉、警報、已存分析或 AI 問答參照的標的：
     * instruments 被多個表以 cascade 參照，其中 watchlist_items、holdings、
     * alerts、stock_analyses、stock_chat_turns 屬使用者資料，天真的「全部取代」
     * 會連同使用者的自選股一起刪掉。行情、籌碼、基本面等純快取則可安全隨之
     * 清除，之後會重抓。
     *
     * @param  list<array{symbol: string, name: string}>  $rows
     * @return array{created: int, skipped: int, removed: int, protected: int}
     */
    public function import(array $rows, string $mode): array
    {
        return DB::transaction(function () use ($rows, $mode): array {
            $removed = 0;
            $protected = 0;

            if ($mode === 'replace') {
                [$removed, $protected] = $this->removeUnreferenced(
                    array_column($rows, 'symbol'),
                );
            }

            $existing = Instrument::query()->pluck('id', 'symbol')->all();

            $created = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if (isset($existing[$row['symbol']])) {
                    $skipped++;

                    continue;
                }

                Instrument::query()->create([
                    'symbol' => $row['symbol'],
                    'name' => $row['name'] !== '' ? $row['name'] : $row['symbol'],
                    'market' => MarketResolver::region($row['symbol']),
                    'asset_type' => MarketResolver::assetType($row['symbol']),
                    'currency' => MarketResolver::currency($row['symbol']),
                    'exchange' => null,
                ]);

                $existing[$row['symbol']] = true;
                $created++;
            }

            return [
                'created' => $created,
                'skipped' => $skipped,
                'removed' => $removed,
                'protected' => $protected,
            ];
        });
    }

    /**
     * 移除未被使用者資料參照、且不在新清單中的標的。
     *
     * @param  list<string>  $keepSymbols
     * @return array{0: int, 1: int} [removed, protected]
     */
    private function removeUnreferenced(array $keepSymbols): array
    {
        // 與 Instrument::isReferencedByUserData() 同一組類別，新增時要同步。
        $referenced = Instrument::query()
            ->where(fn ($q) => $q
                ->whereHas('watchlistItems')
                ->orWhereHas('stockAnalyses')
                ->orWhereHas('stockChatTurns')
                ->orWhereHas('holdings')
                ->orWhereHas('alerts'))
            ->pluck('id')
            ->all();

        $removed = Instrument::query()
            ->whereNotIn('id', $referenced)
            ->whereNotIn('symbol', $keepSymbols)
            ->delete();

        return [$removed, count($referenced)];
    }

    /**
     * 正規化並去重。
     *
     * 代號一律轉大寫並套用與 controller 相同的白名單，避免匯入檔把任意字串
     * 寫進 instruments——那些代號之後會被拿去打上游 API。
     *
     * @param  list<list<string>>  $raw
     * @return array{rows: list<array{symbol: string, name: string}>, errors: list<string>}
     */
    private function normalize(array $raw): array
    {
        $rows = [];
        $errors = [];
        $seen = [];
        $offset = $this->headerOffset($raw);

        foreach (array_slice($raw, $offset) as $index => $cells) {
            $line = $index + $offset + 1;
            $symbol = strtoupper(trim((string) ($cells[0] ?? '')));
            $name = trim((string) ($cells[1] ?? ''));

            if ($symbol === '') {
                continue;
            }

            if (preg_match('/^[A-Z0-9.\-^]{1,32}$/', $symbol) !== 1) {
                $errors[] = "第 {$line} 列代號格式無效：{$symbol}";

                continue;
            }

            // 檔案內部重複也算重複，直接跳過。
            if (isset($seen[$symbol])) {
                continue;
            }

            $seen[$symbol] = true;
            $rows[] = ['symbol' => $symbol, 'name' => $name];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** 第一列若是標題就跳過。 */
    private function headerOffset(array $raw): int
    {
        $first = array_map(
            static fn ($v): string => mb_strtolower(trim((string) $v)),
            $raw[0] ?? [],
        );

        foreach ($first as $cell) {
            if (in_array($cell, self::SYMBOL_HEADERS, true) || in_array($cell, self::NAME_HEADERS, true)) {
                return 1;
            }
        }

        return 0;
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('無法讀取上傳的檔案。');
        }

        // 去掉 UTF-8 BOM：Excel 匯出的 CSV 幾乎都帶 BOM，不處理會讓第一欄
        // 的標題比對失敗，整份檔案被當成沒有標題列。
        $first = fgets($handle);

        if ($first !== false) {
            rewind($handle);

            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
            }
        }

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($v): string => (string) $v, $cells);
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('無法開啟 xlsx 檔（可能已損壞）。');
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheet === false) {
            throw new RuntimeException('xlsx 內找不到工作表。');
        }

        $shared = $this->sharedStrings($sharedXml);
        $xml = new SimpleXMLElement($sheet);
        $rows = [];

        foreach ($xml->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $type = (string) $cell['t'];
                $value = (string) $cell->v;

                // t="s" 代表值是 sharedStrings 的索引；t="inlineStr" 直接內嵌。
                $cells[] = match ($type) {
                    's' => $shared[(int) $value] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    default => $value,
                };
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(string|false $xml): array
    {
        if ($xml === false) {
            return [];
        }

        $parsed = new SimpleXMLElement($xml);
        $out = [];

        foreach ($parsed->si as $item) {
            // 帶格式的儲存格會把文字拆成多個 <r><t>，需串接還原。
            $out[] = isset($item->t) ? (string) $item->t : implode('', array_map(
                static fn ($run): string => (string) $run->t,
                iterator_to_array($item->r ?? [], false),
            ));
        }

        return $out;
    }
}
