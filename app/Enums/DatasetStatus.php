<?php

namespace App\Enums;

/**
 * 單一 dataset 的結果。
 *
 * Empty 與 Failed 必須分開：pre-revenue 公司的營收 dataset 呼叫成功但沒有資料，
 * 只有 ok|failed 兩態時它會被誤判成失敗而無限重試。
 */
enum DatasetStatus: string
{
    case Ok = 'ok';
    case Empty = 'empty';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
}
