<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一列 ＝ 一個 instrument × 一個期間。三張表的科目同列——一份 10-Q 本來就同時
 * 揭露三張表，拆三張資料表只會讓每次讀取都要 join 三次。
 *
 * fiscal_quarter 不可 nullable：唯一索引把每個 NULL 視為互異，年度列會完全不受
 * 約束地堆積重複。annual 填 0，stub 填槽位序號 1..n（同一年度可能同時有前身期間
 * 與過渡期，全填 0 會直接撞唯一鍵）。
 *
 * 金額用 decimal 不用 double：台積電年營收約 2.9 兆新台幣，double 會失精度。
 *
 * 不做幣別換算，存原始值＋currency。外國發行公司中途變更申報幣別時，currency
 * 不同的列不可畫在同一條序列——這件事只有存了 currency 才有機會發現。
 *
 * provenance 與 derived 旗標按「表」而非按列或按欄位：一個推導 Q4 的列可能同時
 * 含推導的損益、差分的現金流、直接的資產負債時點值，單一 row-level 布林會把來源
 * 講錯。按表切三份剛好對應 UI 的三個分頁。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();

            $table->enum('period_type', ['quarter', 'annual', 'stub']);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('fiscal_quarter')->default(0);
            $table->string('period_label', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->boolean('fiscal_year_complete')->default(false);
            $table->string('currency', 8);
            $table->enum('source', ['sec', 'finmind']);

            $table->enum('income_derivation', ['direct', 'derived', 'mixed'])->default('direct');
            $table->enum('cashflow_derivation', ['direct', 'derived', 'mixed'])->default('direct');
            $table->boolean('income_restatement_mixed')->default(false);
            $table->boolean('cashflow_restatement_mixed')->default(false);

            $table->string('income_source_accn', 32)->nullable();
            $table->string('balance_source_accn', 32)->nullable();
            $table->string('cashflow_source_accn', 32)->nullable();

            // 新鮮度按表分開：單一 fetched_at 無法表達「損益抓成功、現金流失敗」。
            $table->timestamp('income_fetched_at')->nullable();
            $table->timestamp('balance_fetched_at')->nullable();
            $table->timestamp('cashflow_fetched_at')->nullable();

            $amount = static fn (string $name) => $table->decimal($name, 20, 2)->nullable();

            // 損益 11 ＋ EPS 2
            foreach ([
                'revenue', 'cost_of_revenue', 'gross_profit', 'research_development',
                'selling_general_admin', 'operating_expenses', 'operating_income',
                'non_operating_income', 'pretax_income', 'income_tax', 'net_income',
            ] as $field) {
                $amount($field);
            }
            $table->decimal('eps_basic', 12, 4)->nullable();
            $table->decimal('eps_diluted', 12, 4)->nullable();

            // 資產負債 13
            foreach ([
                'cash_and_equivalents', 'accounts_receivable', 'inventories',
                'current_assets', 'property_plant_equipment', 'intangible_assets',
                'total_assets', 'accounts_payable', 'current_liabilities',
                'long_term_debt', 'total_liabilities', 'equity', 'retained_earnings',
            ] as $field) {
                $amount($field);
            }

            // 現金流 7。capex 統一存負值代表流出（兩個來源各自正規化）。
            foreach ([
                'operating_cash_flow', 'investing_cash_flow', 'financing_cash_flow',
                'capex', 'depreciation_amortization', 'share_based_compensation',
                'net_change_in_cash',
            ] as $field) {
                $amount($field);
            }

            $table->timestamps();

            // 唯一鍵欄位順序即 reader 的主要查詢路徑（某標的、某種期間、依槽位序
            // 排序），MySQL 唯一索引本身就能服務相同前綴的查詢與排序，不需要另建
            // 一支欄位完全相同的一般索引。
            $table->unique(
                ['instrument_id', 'period_type', 'fiscal_year', 'fiscal_quarter'],
                'financial_statements_slot_unique'
            );
        });

        // 唯一鍵擋得住重複槽位，擋不住「annual 卻填了 fiscal_quarter = 2」這種
        // 語意錯亂的列——那會讓年度列憑空多出一筆，而且不違反任何索引。
        // sqlite 不支援 ALTER TABLE ADD CONSTRAINT，測試環境靠模型層的
        // slotIsValid() 把關。
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE financial_statements
                ADD CONSTRAINT financial_statements_slot_check CHECK (
                    (period_type = 'quarter' AND fiscal_quarter BETWEEN 1 AND 4)
                    OR (period_type = 'annual' AND fiscal_quarter = 0)
                    OR (period_type = 'stub' AND fiscal_quarter >= 1)
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statements');
    }
};
