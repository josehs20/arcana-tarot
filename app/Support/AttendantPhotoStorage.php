<?php

namespace App\Support;

use App\Support\Images\ImageNormalizer;
use App\Support\Images\ImageProfile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Upload da foto exibida no diretório público de atendentes.
 *
 * O arquivo fica no disco público (o navegador do cliente baixa a imagem), o
 * nome é aleatório e a extensão vem do conteúdo processado no servidor, nunca
 * do nome enviado pelo navegador. A imagem é redimensionada para o perfil de
 * foto de atendente e recomprimida em WebP antes de ser gravada.
 */
final class AttendantPhotoStorage
{
    public function __construct(private ImageNormalizer $normalizer) {}

    /**
     * @return string Caminho relativo dentro do disco público.
     */
    public function store(UploadedFile $file): string
    {
        $image = $this->normalizer->normalize($file, ImageProfile::AttendantPhoto, 'photo');

        $directory = trim((string) config('attendant.photo_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$image->extension;
        } while ($this->disk()->exists($path));

        if (! $this->disk()->put($path, $image->binary)) {
            throw new RuntimeException('Não foi possível armazenar a foto do atendente.');
        }

        return $path;
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
