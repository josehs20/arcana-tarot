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
 * Upload da imagem de um produto da home.
 *
 * Mesmo padrão das fotos de atendentes: disco público, nome aleatório e
 * extensão derivada do conteúdo processado no servidor. A imagem é reduzida
 * para o perfil `Product` e recomprimida em WebP.
 */
final class ProductImageStorage
{
    public function __construct(private readonly ImageNormalizer $normalizer) {}

    /**
     * @return string Caminho relativo dentro do disco público.
     */
    public function store(UploadedFile $file): string
    {
        $image = $this->normalizer->normalize($file, ImageProfile::Product, 'image');

        $directory = trim((string) config('product.image_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$image->extension;
        } while ($this->disk()->exists($path));

        if (! $this->disk()->put($path, $image->binary)) {
            throw new RuntimeException('Não foi possível armazenar a imagem do produto.');
        }

        return $path;
    }

    /**
     * Remove uma imagem substituída ou removida pela administração.
     */
    public function delete(?string $path): void
    {
        if (filled($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * URL pública da imagem, ou null quando o arquivo não existe mais.
     */
    public function url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = $this->disk();

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
