<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class YoutubeWorkerContractTest extends TestCase
{
    public function test_worker_normalizes_fake_video_payload(): void
    {
        $process = new Process(['python', 'scripts/youtube_worker.py', '--fake']);
        $process->setWorkingDirectory(dirname(__DIR__, 2));
        $process->mustRun();

        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('youtube', $payload[0]['source']);
        $this->assertArrayHasKey('title', $payload[0]);
        $this->assertArrayHasKey('summary', $payload[0]);
        $this->assertArrayHasKey('published_at', $payload[0]);
        $this->assertArrayHasKey('url', $payload[0]);
        $this->assertSame('video', $payload[0]['kind']);
    }
}
