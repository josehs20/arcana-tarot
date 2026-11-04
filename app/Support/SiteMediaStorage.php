<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Uploads do CMS no disco público.
 *
 * O nome é aleatório e a extensão vem do MIME real detectado pelo servidor,
 * nunca do nome enviado pelo navegador. Assim evitamos sobrescrita de
 * arquivos e execução de conteúdo inesperado.
 */
final class SiteMediaStorage
{
    public function store(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = config("site.allowed_image_mimes.{$mime}");

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'image' => 'Envie uma imagem JPEG, PNG ou WebP válida.',
            ]);
        }

        $directory = trim((string) config('site.image_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $storedPath = $this->disk()->putFileAs($directory, $file, basename($path));

        if ($storedPath === false) {
            throw new RuntimeException('Não foi possível armazenar a imagem.');
        }

        return $storedPath;
    }

    /**
     * Remove um arquivo substituído ou removido pelo CMS.
     */
    public function delete(?string $path): void
    {
        if (filled($path)) {
            $this->disk()->delete($path);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
