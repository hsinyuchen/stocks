<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 把舊 SQLite 資料庫的資料搬到目前的預設連線。
 *
 * 一次性的搬遷工具，不是常態維運指令。之所以寫成 artisan command 而不是手工
 * 用 `sqlite3 .dump` 轉 SQL，是因為兩邊的方言差異（AUTOINCREMENT、識別字引號、
 * 布林表示法、JSON 欄位型別）用文字取代處理時不會報錯，只會靜默漏資料。
 *
 * 設計上刻意不關閉外鍵檢查：依相依順序逐表搬移，來源若有孤兒列就讓它當場失敗，
 * 而不是塞進去變成日後查不出來的資料不一致。
 */
class TransferSqliteToMysqlCommand extends Command
{
    /** 執行期才註冊的來源連線名稱，避免與 config 既有的 sqlite 連線混淆。 */
    private const LEGACY = 'legacy_sqlite';

    /**
     * 不搬移的資料表。
     *
     * migrations 已由 migrate 寫入，重複複製會讓後續 migrate 誤判狀態；
     * 其餘都是可重建的暫態資料，搬過去只是把舊 session 與過期快取帶進新環境。
     */
    private const SKIP_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    protected $signature = 'db:transfer-sqlite
        {--sqlite= : 來源 SQLite 檔路徑，預設 database/database.sqlite}
        {--dry-run : 只做預檢與筆數比對，不寫入任何資料}
        {--truncate : 寫入前清空目標資料表（目標已有資料時才需要）}
        {--chunk=500 : 每批寫入的筆數}';

    protected $description = '把舊 SQLite 資料庫的資料搬到目前的預設連線（MySQL）';

    public function handle(): int
    {
        $target = DB::connection();

        if ($target->getDriverName() === 'sqlite') {
            $this->components->error('目前的預設連線仍是 SQLite。請先把 .env 的 DB_CONNECTION 改成 mysql。');

            return self::FAILURE;
        }

        $path = $this->option('sqlite') ?: database_path('database.sqlite');

        if (! is_file($path)) {
            $this->components->error("找不到來源 SQLite 檔：{$path}");

            return self::FAILURE;
        }

        $this->registerLegacyConnection($path);

        $this->components->info(sprintf(
            '來源 %s → 目標 %s（%s）',
            $path,
            $target->getDatabaseName(),
            $target->getDriverName(),
        ));

        $tables = $this->tablesToCopy();

        if ($tables === []) {
            $this->components->warn('兩邊沒有可對應的資料表，沒有東西要搬。');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan($tables);

        $this->renderPlan($plan);

        if ($this->hasBlockingIssue($plan)) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->info('dry-run：未寫入任何資料。確認上表無誤後，拿掉 --dry-run 再跑一次。');

            return self::SUCCESS;
        }

        return $this->copy($plan);
    }

    /**
     * 註冊指向舊檔的連線。
     *
     * 不能沿用 config/database.php 的 sqlite 連線——它讀的是同一個 DB_DATABASE，
     * 而那個值已經被改成 MySQL 的資料庫名稱了。
     */
    private function registerLegacyConnection(string $path): void
    {
        config(['database.connections.'.self::LEGACY => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            // 唯讀取用，不需要外鍵；關掉可避免舊檔既有的不一致擋住讀取。
            'foreign_key_constraints' => false,
        ]]);

        DB::purge(self::LEGACY);
    }

    /**
     * 兩邊都存在、且不在略過名單內的資料表，依外鍵相依順序排列。
     *
     * @return list<string>
     */
    private function tablesToCopy(): array
    {
        $source = collect(DB::connection(self::LEGACY)->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        ))->pluck('name')->all();

        $targetTables = collect(DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        ))->pluck('name')->all();

        $tables = array_values(array_diff(
            array_intersect($source, $targetTables),
            self::SKIP_TABLES,
        ));

        return $this->sortByDependency($tables);
    }

    /**
     * 依外鍵相依關係做拓撲排序，被參照的表排前面。
     *
     * 順序對了就不必關閉 FOREIGN_KEY_CHECKS，來源資料若有孤兒列會當場報錯。
     * 有環時（本專案目前沒有）退回原順序，交由資料庫報錯，不自作聰明繞過。
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sortByDependency(array $tables): array
    {
        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = [];
        }

        $rows = DB::select(
            'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
        );

        foreach ($rows as $row) {
            if (! isset($deps[$row->child], $deps[$row->parent]) || $row->child === $row->parent) {
                continue;
            }

            $deps[$row->child][$row->parent] = true;
        }

        $sorted = [];
        $remaining = $deps;

        while ($remaining !== []) {
            $ready = [];

            foreach ($remaining as $table => $parents) {
                if (array_diff_key($parents, array_flip($sorted)) === []) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                // 有環：不猜測順序，保留原樣讓資料庫決定是否報錯。
                return $tables;
            }

            foreach ($ready as $table) {
                $sorted[] = $table;
                unset($remaining[$table]);
            }
        }

        return $sorted;
    }

    /**
     * 逐表比對欄位與資料，產生搬移計畫與預檢結果。
     *
     * @param  list<string>  $tables
     * @return list<array{table: string, count: int, existing: int, columns: list<string>, dropped: list<string>, missing: list<string>, issues: list<string>}>
     */
    private function buildPlan(array $tables): array
    {
        $plan = [];

        foreach ($tables as $table) {
            $sourceColumns = collect(DB::connection(self::LEGACY)->select("PRAGMA table_info(`{$table}`)"))
                ->pluck('name')->all();

            $targetColumns = $this->targetColumns($table);

            $shared = array_values(array_intersect($sourceColumns, array_keys($targetColumns)));

            $plan[] = [
                'table' => $table,
                'count' => (int) DB::connection(self::LEGACY)->table($table)->count(),
                'existing' => (int) DB::table($table)->count(),
                'columns' => $shared,
                'dropped' => array_values(array_diff($sourceColumns, array_keys($targetColumns))),
                'missing' => array_values(array_diff(array_keys($targetColumns), $sourceColumns)),
                'issues' => $this->inspect($table, $shared, $targetColumns),
            ];
        }

        return $plan;
    }

    /**
     * 目標資料表的欄位名 → 資料型別。
     *
     * @return array<string, string>
     */
    private function targetColumns(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return collect($rows)->pluck('type', 'name')->all();
    }

    /**
     * 預檢會讓 MySQL 當場失敗的值。
     *
     * SQLite 不檢查型別也不檢查長度，這些值在舊庫裡都存得下去；MySQL 開著
     * strict mode，插入時才會爆。先掃出來，比搬到一半失敗好處理。
     *
     * @param  list<string>  $columns
     * @param  array<string, string>  $types
     * @return list<string>
     */
    private function inspect(string $table, array $columns, array $types): array
    {
        $issues = [];
        $connection = DB::connection(self::LEGACY);

        foreach ($columns as $column) {
            $type = $types[$column] ?? '';

            if ($type === 'json') {
                $invalid = (int) $connection->table($table)
                    ->whereNotNull($column)
                    ->whereRaw("json_valid(`{$column}`) = 0")
                    ->count();

                if ($invalid > 0) {
                    $issues[] = "{$column}：{$invalid} 筆不是合法 JSON，MySQL 的 json 欄位會拒收";
                }
            }

            if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                $blank = (int) $connection->table($table)->where($column, '')->count();

                if ($blank > 0) {
                    $issues[] = "{$column}：{$blank} 筆是空字串，將轉為 NULL";
                }
            }

            if (in_array($type, ['varchar', 'char'], true)) {
                $limit = $this->charLimit($table, $column);

                if ($limit !== null) {
                    $over = (int) $connection->table($table)
                        ->whereRaw("LENGTH(`{$column}`) > ?", [$limit])
                        ->count();

                    if ($over > 0) {
                        $issues[] = "{$column}：{$over} 筆超過 varchar({$limit})，strict mode 會拒收";
                    }
                }
            }
        }

        return $issues;
    }

    private function charLimit(string $table, string $column): ?int
    {
        $row = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return $row?->len === null ? null : (int) $row->len;
    }

    /** @param  list<array<string, mixed>>  $plan */
    private function renderPlan(array $plan): void
    {
        $this->newLine();

        $this->table(
            ['資料表', '來源筆數', '目標現有', '搬移欄位', '來源多餘欄位', '目標新欄位'],
            collect($plan)->map(fn (array $row): array => [
                $row['table'],
                number_format($row['count']),
                $row['existing'] > 0 ? "⚠ {$row['existing']}" : '0',
                count($row['columns']),
                $row['dropped'] === [] ? '-' : implode(', ', $row['dropped']),
                $row['missing'] === [] ? '-' : implode(', ', $row['missing']),
            ])->all(),
        );

        $this->components->info(sprintf(
            '共 %d 張表、%s 筆。略過：%s',
            count($plan),
            number_format(collect($plan)->sum('count')),
            implode('、', self::SKIP_TABLES),
        ));

        foreach ($plan as $row) {
            foreach ($row['issues'] as $issue) {
                $this->components->warn("{$row['table']}.{$issue}");
            }
        }
    }

    /** @param  list<array<string, mixed>>  $plan */
    private function hasBlockingIssue(array $plan): bool
    {
        $occupied = collect($plan)->filter(fn (array $row): bool => $row['existing'] > 0);

        if ($occupied->isNotEmpty() && ! $this->option('truncate')) {
            $this->newLine();
            $this->components->error(sprintf(
                '目標的 %s 已有資料。重複搬移會撞唯一鍵，請確認後加 --truncate 清空再搬。',
                $occupied->pluck('table')->implode('、'),
            ));

            return true;
        }

        return false;
    }

    /** @param  list<array<string, mixed>>  $plan */
    private function copy(array $plan): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($this->option('truncate')) {
            $this->truncate(array_reverse(collect($plan)->pluck('table')->all()));
        }

        $this->newLine();

        foreach ($plan as $row) {
            $table = $row['table'];

            if ($row['count'] === 0) {
                $this->components->twoColumnDetail($table, '<fg=gray>空表，略過</>');

                continue;
            }

            try {
                $written = $this->copyTable($table, $row['columns'], $chunkSize);
            } catch (Throwable $e) {
                $this->newLine();
                $this->components->error("搬移 {$table} 失敗：".$e->getMessage());
                $this->components->warn('已寫入的資料保留在目標，修正後可用 --truncate 重跑。舊 SQLite 檔未被修改。');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail($table, number_format($written).' 筆');
        }

        return $this->verify($plan);
    }

    /**
     * 清空目標資料表。
     *
     * TRUNCATE 在有外鍵參照時會被擋，所以改用 DELETE 並暫時關閉外鍵檢查；
     * 這裡是「重跑搬移」的路徑，本來就要把整批資料換掉。
     *
     * @param  list<string>  $tables  反相依順序（子表在前）
     */
    private function truncate(array $tables): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                DB::table($table)->delete();
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->components->info('已清空目標資料表。');
    }

    /**
     * 逐批複製單一資料表。
     *
     * 用 cursor 而非一次撈全部：daily_prices 這種兩萬筆的表全載入記憶體沒必要，
     * 而且 cursor 不要求資料表有主鍵。
     *
     * @param  list<string>  $columns
     */
    private function copyTable(string $table, array $columns, int $chunkSize): int
    {
        $types = $this->targetColumns($table);
        $written = 0;
        $batch = [];

        DB::transaction(function () use ($table, $columns, $types, $chunkSize, &$written, &$batch): void {
            $rows = DB::connection(self::LEGACY)->table($table)->select($columns)->cursor();

            foreach ($rows as $row) {
                $batch[] = $this->normalize((array) $row, $types);

                if (count($batch) >= $chunkSize) {
                    DB::table($table)->insert($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table($table)->insert($batch);
                $written += count($batch);
                $batch = [];
            }
        });

        return $written;
    }

    /**
     * 把 SQLite 的值調整成 MySQL strict mode 收得下的形式。
     *
     * 只處理日期欄位的空字串——SQLite 允許在 date 欄位存 ''，MySQL 會判定為
     * 不合法日期而拒收。其餘型別讓 PDO 自行處理，不做多餘轉換。
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function normalize(array $row, array $types): array
    {
        foreach ($row as $column => $value) {
            if ($value !== '') {
                continue;
            }

            if (in_array($types[$column] ?? '', ['date', 'datetime', 'timestamp'], true)) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /** @param  list<array<string, mixed>>  $plan */
    private function verify(array $plan): int
    {
        $this->newLine();

        $mismatched = [];

        foreach ($plan as $row) {
            $actual = (int) DB::table($row['table'])->count();

            if ($actual !== $row['count']) {
                $mismatched[] = "{$row['table']}：來源 {$row['count']} 筆，目標 {$actual} 筆";
            }
        }

        if ($mismatched !== []) {
            $this->components->error('搬移後筆數不符：');

            foreach ($mismatched as $line) {
                $this->line("  {$line}");
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '搬移完成，%d 張表共 %s 筆，來源與目標筆數一致。',
            count($plan),
            number_format(collect($plan)->sum('count')),
        ));

        $this->newLine();
        $this->line('  接下來：');
        $this->line('  1. 用原本的帳號登入，確認自選清單與分析紀錄都在');
        $this->line('  2. 到設定頁確認 LLM provider 的 API key 仍可用（APP_KEY 未變就會正常）');
        $this->line('  3. 舊的 database.sqlite 先留著，確認無誤後再處理');

        return self::SUCCESS;
    }
}
