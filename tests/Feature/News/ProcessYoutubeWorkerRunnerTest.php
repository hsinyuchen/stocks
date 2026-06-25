<?php

namespace Tests\Feature\News;

use App\Contracts\YoutubeWorkerRunner;
use App\Services\News\ProcessYoutubeWorkerRunner;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class ProcessYoutubeWorkerRunnerTest extends TestCase
{
    public function test_it_implements_the_worker_runner_contract(): void
    {
        $runner = new ProcessYoutubeWorkerRunner('python', base_path('scripts/youtube_worker.py'));

        $this->assertInstanceOf(YoutubeWorkerRunner::class, $runner);
    }

    /**
     * Exercises the real Process + stdin JSON + JSON-decode plumbing without
     * touching YouTube: empty channels make the worker emit [] (the lazy
     * youtube-transcript-api import is never reached), so system python with no
     * venv dependencies is enough.
     */
    public function test_run_with_empty_channels_returns_empty_array(): void
    {
        if ((new ExecutableFinder)->find('python') === null) {
            $this->markTestSkipped('python is not available on PATH.');
        }

        $runner = new ProcessYoutubeWorkerRunner('python', base_path('scripts/youtube_worker.py'));

        $result = $runner->run([], ['per_channel_limit' => 1]);

        $this->assertSame([], $result);
    }

    public function test_run_returns_empty_array_when_the_worker_fails(): void
    {
        $runner = new ProcessYoutubeWorkerRunner('python', base_path('scripts/does_not_exist_worker.py'));

        $this->assertSame([], $runner->run([], ['per_channel_limit' => 1]));
    }
}
