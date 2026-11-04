<?php

namespace Tests\Unit;

use App\Support\Mp3Duration;
use PHPUnit\Framework\TestCase;

class Mp3DurationTest extends TestCase
{
    public function test_sums_mpeg_frames_to_estimate_duration(): void
    {
        $path = $this->write($this->mp3Frames(100));

        try {
            $this->assertEqualsWithDelta(2.6122, Mp3Duration::seconds($path), 0.0001);
        } finally {
            @unlink($path);
        }
    }

    public function test_skips_an_id3v2_tag_before_the_audio_frames(): void
    {
        $tag = "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\0", 10);
        $path = $this->write($tag.$this->mp3Frames(100));

        try {
            $this->assertEqualsWithDelta(2.6122, Mp3Duration::seconds($path), 0.0001);
        } finally {
            @unlink($path);
        }
    }

    public function test_returns_null_when_the_file_has_no_mpeg_frames(): void
    {
        $path = $this->write('not an mp3');

        try {
            $this->assertNull(Mp3Duration::seconds($path));
        } finally {
            @unlink($path);
        }
    }

    private function mp3Frames(int $frames): string
    {
        $frame = "\xFF\xFB\x90\x00".str_repeat("\0", 413);

        return str_repeat($frame, $frames);
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arcana-mp3-');

        if ($path === false) {
            $this->fail('Não foi possível criar o arquivo temporário do teste.');
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
