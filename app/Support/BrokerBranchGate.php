<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

/**
 * 券商分點「此 token 暫不可用」的獨立守門（per-token）。
 *
 * 券商分點是 FinMind Sponsor 付費 dataset。免費 token 打它必被擋——但這**不能**沿用
 * 全站 FinMindGate，否則一次受限就會冷卻該 token 的所有 FinMind 抓取（行情、三大法人、
 * 基本面等免費功能），把免費使用者本來能用的東西一起拖垮。
 *
 * 因此券商分點自成一個守門：偵測 Sponsor 受限時只標記「此 token 券商分點暫停嘗試」，
 * 冷卻期內券商分點面板降級，其餘 FinMind 功能完全不受影響。
 *
 * key 綁「當前 resolver 決定的 token」的短 hash（避免把憑證寫進 cache key）。
 */
class BrokerBranchGate
{
    private const KEY_PREFIX = 'broker_branch:unavailable';

    /** 此 token 券商分點是否已知不可用（Sponsor 受限冷卻中）。 */
    public static function isUnavailable(): bool
    {
        return Cache::has(self::key());
    }

    /** 標記此 token 券商分點暫不可用（Sponsor 受限）。 */
    public static function markUnavailable(): void
    {
        Cache::put(
            self::key(),
            true,
            now()->addMinutes(max(1, (int) config('brokerbranch.unavailable_cooldown_minutes', 60))),
        );
    }

    private static function key(): string
    {
        $token = App::make(FinMindTokenResolver::class)->resolve();

        $suffix = ($token === null || $token === '') ? 'global' : substr(sha1($token), 0, 16);

        return self::KEY_PREFIX.':'.$suffix;
    }
}
