<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config 的結構契約。
 *
 * 這些鍵被三個純計算類別讀，缺鍵會讓判定靜默退化成「全部中性」——
 * 那比報錯更糟，因為畫面上看起來一切正常。
 */
class HealthConfigTest extends TestCase
{
    #[Test]
    public function every_threshold_is_numeric(): void
    {
        $keys = [
            'valuation.cheap_percentile', 'valuation.expensive_percentile',
            'roe.strong', 'roe.weak',
            'growth.strong', 'growth.weak',
            'quality.ocf_to_net_income_strong', 'quality.ocf_to_net_income_weak',
            'quality.dso_change_days_worse', 'quality.dso_change_days_better',
            'chip.neutral_band_volume_share',
        ];

        foreach ($keys as $key) {
            $this->assertIsNumeric(config("health.{$key}"), "health.{$key} 必須存在且為數值");
        }
    }

    /**
     * 便宜門檻必須低於昂貴門檻。只靠 config 註解約束大小關係，
     * 有人調參時很容易把兩者調反，而調反之後判定會整個翻面卻沒有任何錯誤。
     */
    #[Test]
    public function the_thresholds_keep_their_ordering(): void
    {
        $this->assertLessThan(
            (float) config('health.valuation.expensive_percentile'),
            (float) config('health.valuation.cheap_percentile'),
            '便宜的分位必須低於昂貴的分位',
        );

        // assertLessThan($expected, $actual) 斷言的是 $actual < $expected。
        // 每一對都是「弱門檻必須低於強門檻」，寫反了會讓判定整個翻面。
        $pairs = [
            ['health.roe.weak', 'health.roe.strong'],
            ['health.growth.weak', 'health.growth.strong'],
            ['health.quality.ocf_to_net_income_weak', 'health.quality.ocf_to_net_income_strong'],
            // 應收天數變多＝收款變慢＝較差，所以 better 必須低於 worse。
            ['health.quality.dso_change_days_better', 'health.quality.dso_change_days_worse'],
        ];

        foreach ($pairs as [$lower, $higher]) {
            $this->assertLessThan(
                (float) config($higher),
                (float) config($lower),
                "{$lower} 必須低於 {$higher}",
            );
        }
    }

    /**
     * 中性帶必須大於 0。訂成 0 會讓「外資淨買 1 股」重新被判成買超
     * ——那正是本階段要修掉的既有缺陷。
     */
    #[Test]
    public function the_chip_neutral_band_is_not_zero(): void
    {
        $this->assertGreaterThan(0.0, (float) config('health.chip.neutral_band_volume_share'));
    }

    /**
     * 公式版本要存在且非空：判讀會隨分析保存，日後要能分辨
     * 「這份判讀是哪一版公式算的」。
     */
    #[Test]
    public function the_formula_version_is_present(): void
    {
        $this->assertNotSame('', trim((string) config('health.formula_version')));
    }

    /**
     * 品質那塊的產業適用性沿用 order_inventory 的既有名單，**不在 health 這邊
     * 另立一套**——同一件事兩份判準遲早漂移。
     *
     * 這裡驗的是「health 沒有自己的產業名單」，不是「有一個指向別人的字串」：
     * 原本那條斷言的對象是一個沒有任何生產程式碼讀的 config 鍵，等於驗一個字串
     * 常數等於它自己（詳見 config/health.php 的說明）。真正的接線由
     * `HealthSnapshotBuilderTest::the_snapshot_carries_the_industry_bucket_from_the_existing_policy`
     * 走真實鏈路驗證。
     */
    #[Test]
    public function health_does_not_define_its_own_industry_list(): void
    {
        $quality = (array) config('health.quality');

        foreach ($quality as $key => $value) {
            $this->assertIsNumeric(
                $value,
                "health.quality.{$key} 不是門檻。產業適用性一律問 order_inventory 的既有名單，不得在此另立。",
            );
        }
    }
}
