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

    public function response(TarotCard $card): StreamedResponse
    {
        $path = $this->validatedCatalogPath($card);

        if (! $this->disk()->exists($path)) {
            abort(404);
        }

        $allowedMimes = array_keys((array) config('tarot.allowed_image_mimes'));
        $mime = $card->image_mime ?: $this->disk()->mimeType($path);

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            abort(404);
        }

        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        $extension = config("tarot.allowed_image_mimes.{$mime}", 'img');

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) ($card->image_size ?: $this->disk()->size($path)),
            'Content-Disposition' => sprintf('inline; filename="carta-%d.%s"', $card->getKey(), $extension),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
