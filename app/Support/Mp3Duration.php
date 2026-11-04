<?php

namespace App\Support;

/**
 * Leitor de duração de MP3 sem extensão ou dependência externa.
 *
 * A validação de upload precisa garantir o limite de 5 segundos mesmo quando a
 * requisição não passa pelo JavaScript. Em vez de depender de ffprobe/getID3,
 * percorremos os cabeçalhos dos quadros MPEG e somamos a duração de cada um.
 * O arquivo aceito é pequeno (até 1 MB), então a leitura sequencial é barata.
 */
final class Mp3Duration
{
    private const MAX_FRAMES = 30000;

    private const MAX_FRAME_LENGTH = 8192;

    /**
     * Duração aproximada em segundos, ou `null` quando o arquivo não contém
     * quadros MPEG reconhecíveis.
     */
    public static function seconds(string $path): ?float
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $stats = fstat($handle);
            $fileSize = is_array($stats) ? (int) ($stats['size'] ?? 0) : 0;

            if ($fileSize <= 0) {
                return null;
            }

            $offset = self::audioStartOffset($handle);

            if ($offset === null || $offset >= $fileSize) {
                return null;
            }

            fseek($handle, $offset);

            $duration = 0.0;
            $frames = 0;

            while (! feof($handle) && $frames < self::MAX_FRAMES) {
                $start = ftell($handle);

                if ($start === false || $start >= $fileSize) {
                    break;
                }

                $header = fread($handle, 4);

                if (! is_string($header) || strlen($header) < 4) {
                    break;
                }

                $frame = self::parseHeader($header);

                if ($frame === null) {
                    // Cabeçalho falso (metadado, ID3v1 etc.): avança um byte e
                    // tenta ressincronizar no próximo quadro válido.
                    fseek($handle, $start + 1);

                    continue;
                }

                $duration += $frame['samples_per_frame'] / $frame['sample_rate'];
                $frames++;

                fseek($handle, $start + $frame['length']);
            }

            return $frames > 0 ? $duration : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Offset após o ID3v2 inicial (quando existir).
     */
    private static function audioStartOffset($handle): ?int
    {
        fseek($handle, 0);

        $header = fread($handle, 10);

        if (! is_string($header) || strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
            return 0;
        }

        $tagSize = self::synchsafeToInt(substr($header, 6, 4));
        $flags = ord($header[5]);
        $footer = ($flags & 0x10) === 0x10 ? 10 : 0;

        return 10 + $tagSize + $footer;
    }

    private static function synchsafeToInt(string $bytes): int
    {
        $value = 0;

        foreach (str_split($bytes) as $byte) {
            $value = ($value << 7) | (ord($byte) & 0x7F);
        }

        return $value;
    }

    /**
     * @return array{sample_rate: int, samples_per_frame: int, length: int}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $byte1 = ord($header[1]);
        $byte2 = ord($header[2]);

        if (($byte1 & 0xE0) !== 0xE0) {
            return null;
        }

        $versionBits = ($byte1 >> 3) & 0x03;
        $layerBits = ($byte1 >> 1) & 0x03;

        if ($versionBits === 1 || $layerBits === 0) {
            return null;
        }

        $bitrateIndex = ($byte2 >> 4) & 0x0F;
        $sampleRateIndex = ($byte2 >> 2) & 0x03;

        if ($bitrateIndex === 0 || $bitrateIndex === 15 || $sampleRateIndex === 3) {
            return null;
        }

        $bitrate = self::bitrateKbps($versionBits, $layerBits, $bitrateIndex);
        $sampleRate = self::sampleRate($versionBits, $sampleRateIndex);

        if ($bitrate === null || $sampleRate === null) {
            return null;
        }

        $samplesPerFrame = self::samplesPerFrame($versionBits, $layerBits);
        $padding = ($byte2 >> 1) & 0x01;

        if ($layerBits === 3) {
            $length = (int) floor((12 * $bitrate * 1000 / $sampleRate + $padding) * 4);
        } else {
            $length = (int) floor(
                ($samplesPerFrame / 8) * ($bitrate * 1000) / $sampleRate,
            ) + $padding;
        }

        if ($length < 4 || $length > self::MAX_FRAME_LENGTH) {
            return null;
        }

        return [
            'sample_rate' => $sampleRate,
            'samples_per_frame' => $samplesPerFrame,
            'length' => $length,
        ];
    }

    /**
     * @param  int  $versionBits  3 = MPEG-1, 2 = MPEG-2, 0 = MPEG-2.5
     * @param  int  $layerBits  3 = Layer I, 2 = Layer II, 1 = Layer III
     */
    private static function bitrateKbps(int $versionBits, int $layerBits, int $index): ?int
    {
        $table = match (true) {
            $versionBits === 3 && $layerBits === 3 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
            $versionBits === 3 && $layerBits === 2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
            $versionBits === 3 && $layerBits === 1 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            $layerBits === 3 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
            default => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        };

        return $table[$index - 1] ?? null;
    }

    private static function sampleRate(int $versionBits, int $index): ?int
    {
        $table = match ($versionBits) {
            3 => [44100, 48000, 32000],
            2 => [22050, 24000, 16000],
            0 => [11025, 12000, 8000],
            default => [],
        };

        return $table[$index] ?? null;
    }

    private static function samplesPerFrame(int $versionBits, int $layerBits): int
    {
        if ($layerBits === 3) {
            return 384;
        }

        if ($layerBits === 2) {
            return 1152;
        }

        return $versionBits === 3 ? 1152 : 576;
    }
}
