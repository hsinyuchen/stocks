<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FetchResult;
use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\DatasetStatus;
use App\Enums\DerivationKind;
use App\Enums\FetchStatus;
use App\Enums\PeriodType;
use Tests\TestCase;

/**
 * 測試環境的 CACHE_STORE=array（見 phpunit.xml）對應 Illuminate\Cache\ArrayStore，
 * 其 'serialize' => false（見 config/cache.php）代表 put/get 直接存取同一個 PHP
 * 物件參照，從不呼叫 serialize()/unserialize()。CachedFinancialStatementSourceTest
 * 與 FinancialStatementSourceEndToEndTest 裡「快取往返後數值一致」的斷言因此只驗證
 * 到「同一顆物件讀回自己」，驗不到序列化這件事。
 *
 * 生產環境預設 CACHE_STORE=database，Illuminate\Cache\DatabaseStore 用 PHP 原生
 * serialize()/unserialize()。這裡繞過 Cache facade，直接對 FetchResult 做原生序列
 * 化往返，釘住「往返後型別與數值都不退化」——尤其是 null 不能變成 0.0：財報畫面上
 * 「該科目無資料」跟「該科目為 0」是兩件不同的事。
 */
class FetchResultSerializationRoundtripTest extends TestCase
{
    public function test_fetch_result_survives_native_serialization_roundtrip(): void
    {
        $period = new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: 2025,
            fiscalQuarter: 4,
            periodLabel: '2025Q4',
            periodStart: '2025-10-01',
            periodEnd: '2025-12-31',
            fiscalYearComplete: true,
            currency: 'USD',
            values: [
                'revenue' => 1868000.0,   // 非零 float，往返後不能退化成 int/string
                'net_income' => 0.0,      // 零值 float，最容易被誤判成 null 的案例
                'eps' => null,            // 無資料，往返後必須仍是 null
            ],
            incomeDerivation: DerivationKind::Mixed,
            cashflowDerivation: DerivationKind::Derived,
            incomeRestatementMixed: true,
            cashflowRestatementMixed: false,
            incomeSourceAccn: '0000320193-26-000010',
            balanceSourceAccn: null,
            cashflowSourceAccn: '0000320193-26-000010',
        );

        $original = new FetchResult(
            status: FetchStatus::Complete,
            periods: new PeriodFactSet([$period], 'us'),
            datasetStatuses: [
                'income' => DatasetStatus::Ok,
                'cashflow' => DatasetStatus::Empty,
                'balance' => DatasetStatus::Failed,
            ],
            errorCategory: null,
        );

        /** @var FetchResult $restored */
        $restored = unserialize(serialize($original));

        $this->assertSame(FetchStatus::Complete, $restored->status);
        $this->assertNull($restored->errorCategory);

        $this->assertSame('us', $restored->periods->market);
        $this->assertCount(1, $restored->periods->periods);
        $restoredPeriod = $restored->periods->periods[0];

        $this->assertSame(PeriodType::Quarter, $restoredPeriod->periodType);
        $this->assertSame(2025, $restoredPeriod->fiscalYear);
        $this->assertSame(4, $restoredPeriod->fiscalQuarter);
        $this->assertSame('2025Q4', $restoredPeriod->periodLabel);
        $this->assertTrue($restoredPeriod->fiscalYearComplete);
        $this->assertSame('USD', $restoredPeriod->currency);
        $this->assertSame(DerivationKind::Mixed, $restoredPeriod->incomeDerivation);
        $this->assertSame(DerivationKind::Derived, $restoredPeriod->cashflowDerivation);
        $this->assertTrue($restoredPeriod->incomeRestatementMixed);
        $this->assertFalse($restoredPeriod->cashflowRestatementMixed);
        $this->assertSame('0000320193-26-000010', $restoredPeriod->incomeSourceAccn);
        $this->assertNull($restoredPeriod->balanceSourceAccn);

        // 核心斷言：null／0.0／非零 float 三者往返後型別與數值都不能互相退化。
        $this->assertArrayHasKey('eps', $restoredPeriod->values);
        $this->assertNull($restoredPeriod->values['eps']);

        $this->assertArrayHasKey('net_income', $restoredPeriod->values);
        $this->assertSame(0.0, $restoredPeriod->values['net_income']);
        $this->assertIsFloat($restoredPeriod->values['net_income']);

        $this->assertArrayHasKey('revenue', $restoredPeriod->values);
        $this->assertSame(1868000.0, $restoredPeriod->values['revenue']);
        $this->assertIsFloat($restoredPeriod->values['revenue']);

        $this->assertSame(DatasetStatus::Ok, $restored->datasetStatuses['income']);
        $this->assertSame(DatasetStatus::Empty, $restored->datasetStatuses['cashflow']);
        $this->assertSame(DatasetStatus::Failed, $restored->datasetStatuses['balance']);
    }
}
