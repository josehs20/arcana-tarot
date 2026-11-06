<?php

namespace App\Support;

use App\Models\TarotCard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Armazenamento privado e imutável das imagens do catálogo.
 *
 * O navegador nunca escolhe o caminho: o nome é aleatório e a extensão vem do
 * MIME real detectado no servidor. Substituições criam um novo caminho; o
 * arquivo antigo não é apagado porque pode estar referenciado no histórico.
 */
final class TarotImageStorage
{
    /**
     * @return array{image_path: string, image_mime: string, image_size: int, image_checksum: string}
     */
    public function store(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $extension = config("tarot.allowed_image_mimes.{$mime}");

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'image' => 'Envie uma imagem JPEG, PNG ou WebP válida.',
            ]);
        }

        $directory = trim((string) config('tarot.image_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $storedPath = $this->disk()->putFileAs($directory, $file, basename($path));

        if ($storedPath === false) {
            throw new RuntimeException('Não foi possível armazenar a imagem da carta.');
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        return [
            'image_path' => $storedPath,
            'image_mime' => $mime,
            'image_size' => (int) $file->getSize(),
            'image_checksum' => $checksum !== false ? $checksum : '',
        ];
    }

    /**
     * Remove apenas um arquivo recém-gravado cuja transação falhou. Nunca é
     * usada para apagar imagens antigas ou referenciadas pelo histórico.
     */
    public function discardNewFile(?string $path): void
    {
        if (filled($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * Imagem original do catálogo, em tamanho cheio.
     *
     * O `Cache-Control` é longo e `immutable` porque o caminho do arquivo é
     * imutável: substituir a arte de uma carta grava um arquivo novo e muda a
     * versão que aparece na URL (`TarotCard::imageUrl()`), então o navegador
     * nunca reaproveita uma imagem antiga. A resposta é privada — a rota
     * revalida a autorização a cada requisição, mas o arquivo só é cacheado no
     * navegador de quem já tem acesso.
     */
    public function response(TarotCard $card): StreamedResponse
    {
        return $this->streamedResponse($card, $this->validatedCatalogPath($card), 'original');
    }

    /**
     * Miniatura WebP usada na grade do seletor de cartas.
     *
     * Gerar a miniatura é caro e acontece uma única vez por arquivo: a primeira
     * requisição reduz a imagem com o GD e grava o WebP no disco privado; as
     * seguintes apenas leem o resultado. Sem GD, com imagem corrompida ou com
     * dimensões absurdas, o original é servido como antes — o seletor continua
     * funcionando, apenas sem o ganho de banda.
     */
    public function thumbnailResponse(TarotCard $card): StreamedResponse
    {
        $source = $this->validatedCatalogPath($card);
        $thumbnail = $this->ensureThumbnail($source);

        return $this->streamedResponse(
            $card,
            $thumbnail ?? $source,
            $thumbnail !== null ? 'thumbnail' : 'original',
        );
    }

    /**
     * Caminho do WebP reduzido, gerando-o quando ainda não existe.
     *
     * @return string|null `null` quando o servidor não consegue reduzir a imagem.
     */
    private function ensureThumbnail(string $sourcePath): ?string
    {
        $thumbnailPath = $this->thumbnailPath($sourcePath);

        if ($this->disk()->exists($thumbnailPath)) {
            return $thumbnailPath;
        }

        $binary = $this->disk()->get($sourcePath);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $thumbnail = $this->buildThumbnail($binary);

        if ($thumbnail === null) {
            return null;
        }

        return $this->disk()->put($thumbnailPath, $thumbnail) ? $thumbnailPath : null;
    }

    private function thumbnailPath(string $sourcePath): string
    {
        $directory = trim((string) config('tarot.image_directory'), '/');

        return $directory.'/thumbnails/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.webp';
    }

    /**
     * Reduz e recomprime a imagem em memória. Nada é gravado aqui.
     */
    private function buildThumbnail(string $binary): ?string
    {
        if (
            ! function_exists('imagecreatefromstring')
            || ! function_exists('imagescale')
            || ! function_exists('imagewebp')
        ) {
            return null;
        }

        $size = @getimagesizefromstring($binary);

        // Imagem corrompida, formato não raster ou arquivo gigante (acima de
        // ~40 megapixels) fica fora da redução para não estourar a memória do
        // processo compartilhado.
        if (! is_array($size) || ($size[0] * $size[1]) > 40_000_000) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        $targetWidth = max(64, (int) config('tarot.thumbnail.width', 320));
        $resized = imagesx($source) > $targetWidth
            ? imagescale($source, $targetWidth, -1, IMG_BICUBIC)
            : $source;

        if ($resized === false) {
            imagedestroy($source);

            return null;
        }

        imagepalettetotruecolor($resized);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        ob_start();
        $encoded = imagewebp($resized, null, max(1, min(100, (int) config('tarot.thumbnail.quality', 72))));
        $data = (string) ob_get_clean();

        if ($resized !== $source) {
            imagedestroy($resized);
        }

        imagedestroy($source);

        return $encoded === true && $data !== '' ? $data : null;
    }

    private function streamedResponse(TarotCard $card, string $path, string $variant): StreamedResponse
    {
        if (! $this->disk()->exists($path)) {
            abort(404);
        }

        $isThumbnail = $variant === 'thumbnail';
        $mime = $isThumbnail ? 'image/webp' : $this->validatedMime($card, $path);

        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $this->headers($card, $path, $mime, $variant));
    }

    private function validatedMime(TarotCard $card, string $path): string
    {
        $allowedMimes = array_keys((array) config('tarot.allowed_image_mimes'));
        $mime = $card->image_mime ?: $this->disk()->mimeType($path);

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            abort(404);
        }

        return $mime;
    }

    /**
     * @return array<string, string>
     */
    private function headers(TarotCard $card, string $path, string $mime, string $variant): array
    {
        $extension = $mime === 'image/webp' ? 'webp' : config("tarot.allowed_image_mimes.{$mime}", 'img');
        $size = (string) $this->disk()->size($path);

        return [
            'Content-Type' => $mime,
            'Content-Length' => $size,
            'Content-Disposition' => sprintf('inline; filename="carta-%d.%s"', $card->getKey(), $extension),
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => sprintf('"%s"', $this->etagSeed($card, $variant, $size)),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function etagSeed(TarotCard $card, string $variant, string $size): string
    {
        return substr(
            hash('sha256', implode('|', [(string) $card->image_path, (string) $card->image_checksum, $variant, $size])),
            0,
            32,
        );
    }

    /**
     * O caminho do catálogo também é validado contra o diretório permitido.
     * Isso impede leitura arbitrária do disco privado caso o banco seja
     * adulterado ou receba um caminho legado inválido.
     */
    private function validatedCatalogPath(TarotCard $card): string
    {
        $path = (string) $card->image_path;
        $prefix = trim((string) config('tarot.image_directory'), '/').'/';

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '..')
            || ! str_starts_with($path, $prefix)
        ) {
            abort(404);
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('tarot.image_disk'));
    }
}
