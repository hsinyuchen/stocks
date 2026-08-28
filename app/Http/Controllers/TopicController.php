<?php

namespace App\Http\Controllers;

use App\Services\Topics\TopicCandidateResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 題材驅動候選。
 *
 * **不預設載入任何題材**：八個題材之間沒有「哪個比較重要」的依據，
 * 替使用者選一個等於系統擅自下了判斷。未指定或無效一律回到選擇畫面
 * （`board` 為 null），不回 404——使用者手改網址或書籤過期時，
 * 給他一個可以往下走的畫面比一頁錯誤有用。
 *
 * **payload 形狀一律由 TopicBoard::toArray() 決定**，這裡不重組。
 * 階段 4 的 I4：形狀各自寫在兩個 controller 裡，後來要加必要說明時只改到一邊。
 */
class TopicController extends Controller
{
    public function index(Request $request, TopicCandidateResolver $resolver): Response
    {
        $locale = $request->user()?->profile?->locale ?? 'zh';
        $selected = $request->query('topic');
        $board = is_string($selected) && $selected !== ''
            ? $resolver->resolve($selected, null, $locale)
            : null;

        return Inertia::render('Topics/Index', [
            'topics' => $resolver->availableTopics($locale),
            'board' => $board?->toArray(),
            // board 為 null 時 selected 也要是 null，否則前端會把一個
            // 不存在的題材標成「已選取」。
            'selected' => $board?->key,
        ]);
    }
}
