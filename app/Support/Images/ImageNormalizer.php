<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Normalização autoritativa das imagens enviadas pelo painel.
 *
 * O navegador pode adiantar o trabalho, mas a decisão final é sempre do
 * servidor: o conteúdo é identificado por `getimagesize()` (não pelo nome nem
 * pelo MIME declarado), a orientação do EXIF é aplicada, a imagem é reduzida
 * até caber no perfil de saída e recodificada em WebP/PNG. Recodificar também
 * descarta EXIF e GPS do original.
 *
 * Duas guardas evitam derrubar o processo: o teto de pixels (`max_pixels`) é
 * conferido antes da decodificação e o orçamento de memória é comparado com o
 * que a imagem exige. Sem GD/Imagick instalado, o arquivo original é mantido e
 * um aviso é registrado — o upload continua funcionando, apenas sem o ganho de
 * tamanho.
 */
final class ImageNormalizer
{
    /**
     * MIME real (detectado no conteúdo) => MIME e extensão gravados.
     *
     * @var array<int, array{mime: string, extension: string}>
     */
    private const SUPPORTED_TYPES = [
        IMAGETYPE_JPEG => ['mime' => 'image/jpeg', 'extension' => 'jpg'],
        IMAGETYPE_PNG => ['mime' => 'image/png', 'extension' => 'png'],
        IMAGETYPE_WEBP => ['mime' => 'image/webp', 'extension' => 'webp'],
    ];

    /**
     * MIME produzido pelo encoder => extensão do arquivo.
     *
     * @var array<string, string>
     */
    private const OUTPUT_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param  string  $field  Campo do formulário que receberá o erro de validação.
     */
    public function normalize(UploadedFile $file, ImageProfile $profile, string $field = 'image'): ProcessedImage
    {
        $source = $file->getRealPath();

        if (! is_string($source) || $source === '' || ! is_file($source) || ! is_readable($source)) {
            throw $this->invalidContent($field);
        }

        $detected = $this->detect($source, $field);

        $this->ensurePixelBudget($detected['width'], $detected['height'], $field);
        $this->raiseMemoryLimit();
        $this->ensureMemoryBudget($detected['width'], $detected['height'], $field);

        if (! $this->supportsProcessing()) {
            $binary = file_get_contents($source);

            if (! is_string($binary) || $binary === '') {
                throw $this->invalidContent($field);
            }

            Log::warning('Normalização de imagem ignorada: nenhuma extensão de processamento disponível.', [
                'field' => $field,
                'profile' => $profile->value,
                'mime' => $detected['mime'],
                'bytes' => strlen($binary),
            ]);

            return new ProcessedImage($binary, $detected['mime'], $detected['extension'], $detected['width'], $detected['height']);
        }

        try {
            $image = $this->manager()->read($source);

            // Reduz apenas o que passa do teto; nunca amplia.
            $image->scaleDown($profile->maxWidth(), $profile->maxHeight());

            $encoded = $profile->format() === 'png'
                ? $image->toPng()
                : $image->toWebp(quality: $profile->quality());

            $binary = $encoded->toString();
            $mime = $encoded->mediaType();
        } catch (Throwable $exception) {
            Log::warning('Falha ao normalizar imagem enviada pelo painel.', [
                'field' => $field,
                'profile' => $profile->value,
                'mime' => $detected['mime'],
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                $field => 'Não foi possível processar a imagem enviada. Tente novamente com outro arquivo.',
            ]);
        }

        $extension = self::OUTPUT_EXTENSIONS[$mime] ?? null;

        if ($binary === '' || $extension === null) {
            throw ValidationException::withMessages([
                $field => 'Não foi possível processar a imagem enviada. Tente novamente com outro arquivo.',
            ]);
        }

        return new ProcessedImage($binary, $mime, $extension, $image->width(), $image->height());
    }

    /**
     * O tipo é lido do cabeçalho do arquivo. SVG, GIF animado, PDF e
     * executáveis ficam de fora mesmo que o navegador declare um MIME de
     * imagem.
     *
     * @return array{mime: string, extension: string, width: int, height: int}
     */
    private function detect(string $source, string $field): array
    {
        $info = @getimagesize($source);

        if (! is_array($info) || ! isset($info[0], $info[1], $info[2])) {
            throw $this->invalidContent($field);
        }

        $type = self::SUPPORTED_TYPES[(int) $info[2]] ?? null;

        if ($type === null || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw $this->invalidContent($field);
        }

        return [
            'mime' => $type['mime'],
            'extension' => $type['extension'],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    private function ensurePixelBudget(int $width, int $height, string $field): void
    {
        $maxPixels = max(1, (int) config('images.max_pixels', 40_000_000));

        if ($width * $height <= $maxPixels) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'A imagem tem resolução alta demais ('
                .$this->megapixels($width * $height)
                .' megapixels). Reduza para no máximo '
                .number_format($maxPixels / 1_000_000, 0, ',', '.')
                .' megapixels e envie novamente.',
        ]);
    }

    /**
     * A imagem é decodificada três vezes no pior caso (origem, cópia reduzida
     * e buffer do encoder), com 4 bytes por pixel em cada etapa.
     */
    private function ensureMemoryBudget(int $width, int $height, string $field): void
    {
        $limit = $this->currentMemoryLimit();

        if ($limit === -1) {
            return;
        }

        $required = $width * $height * 4 * 3;

        if ($required <= max(0, $limit - memory_get_usage(true))) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Não há memória suficiente para processar esta imagem. Reduza a resolução e envie novamente.',
        ]);
    }

    /**
     * Sobe o `memory_limit` apenas quando o valor de `config/images.php` é
     * maior que o atual. Nunca reduz o limite do processo.
     */
    private function raiseMemoryLimit(): void
    {
        $configured = (string) config('images.memory_limit', '');

        if ($configured === '') {
            return;
        }

        $target = $this->bytesFromIni($configured);

        if ($target === -1) {
            return;
        }

        $current = $this->currentMemoryLimit();

        if ($current === -1 || $target <= $current) {
            return;
        }

        @ini_set('memory_limit', $configured);
    }

    private function currentMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        return is_string($limit) && $limit !== '' ? $this->bytesFromIni($limit) : -1;
    }

    /**
     * Converte valores do `php.ini` (`512M`, `1G`, `-1`) em bytes.
     */
    private function bytesFromIni(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function supportsProcessing(): bool
    {
        if (strtolower((string) config('images.driver', 'gd')) === 'imagick') {
            return extension_loaded('imagick');
        }

        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    private function manager(): ImageManager
    {
        return app(ImageManager::class);
    }

    private function megapixels(int $pixels): string
    {
        return number_format($pixels / 1_000_000, 1, ',', '.');
    }

    private function invalidContent(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => 'Envie uma imagem JPEG, PNG ou WebP válida.',
        ]);
    }
}
