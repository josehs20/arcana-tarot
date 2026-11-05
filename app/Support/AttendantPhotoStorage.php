<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Upload da foto exibida no diretório público de atendentes.
 *
 * O arquivo fica no disco público (o navegador do cliente baixa a imagem), o
 * nome é aleatório e a extensão vem do MIME real detectado no servidor, nunca
 * do nome enviado pelo navegador.
 */
final class AttendantPhotoStorage
{
    /**
     * @return string Caminho relativo dentro do disco público.
     */
    public function store(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $extension = config("attendant.allowed_photo_mimes.{$mime}");

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'photo' => 'Envie uma imagem JPG, PNG ou WebP.',
            ]);
        }

        $directory = trim((string) config('attendant.photo_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $storedPath = $this->disk()->putFileAs($directory, $file, basename($path));

        if ($storedPath === false) {
            throw new RuntimeException('Não foi possível armazenar a foto do atendente.');
        }

        return $storedPath;
    }

    /**
     * Remove uma foto substituída ou removida pela administração.
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
