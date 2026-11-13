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
 * Uploads do CMS no disco público.
 *
 * O nome é aleatório e a extensão vem do conteúdo processado no servidor,
 * nunca do nome enviado pelo navegador. A imagem é reduzida para o perfil da
 * tela (logo, favicon ou seção) e recomprimida antes de ser gravada.
 */
final class SiteMediaStorage
{
    public function __construct(private ImageNormalizer $normalizer) {}

    /**
     * @param  string  $field  Campo do formulário que receberá o erro de validação.
     */
    public function store(UploadedFile $file, ImageProfile $profile, string $field = 'image'): string
    {
        $image = $this->normalizer->normalize($file, $profile, $field);

        $directory = trim((string) config('site.image_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$image->extension;
        } while ($this->disk()->exists($path));

        if (! $this->disk()->put($path, $image->binary)) {
            throw new RuntimeException('Não foi possível armazenar a imagem.');
        }

        return $path;
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
