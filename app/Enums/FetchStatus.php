<?php

namespace App\Enums;

/**
 * 一次擷取的整體結果。
 *
 * 存在的理由：既有的兩個 provider 把 HTTP 失敗、429／403、gate 降級一律壓成
 * 空陣列（SecEdgarFinancialsProvider.php:116-131、FinMindFundamentalsProvider.php:221-252），
 * 狀態層無從區分「這檔沒有財報」與「這次抓失敗」，於是只能對兩者做同一種處置。
 */
enum FetchStatus: string
{
    /** 全部 dataset 都成功。**只有這一態能寫入 raw cache。** */
    case Complete = 'complete';

    /** 部分 dataset 成功。已成功的表照樣落地，但狀態記 failed 以便下次重抓。 */
    case Partial = 'partial';

    /** 暫時性失敗，可重試。 */
    case Failed = 'failed';

    /** 永久不支援：非 stock 的 asset type、非 us-gaap taxonomy、無目標科目的 USD 資料。 */
    case Unsupported = 'unsupported';
}
