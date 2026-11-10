<?php

namespace App\Support;

/**
 * Redução das imagens raster em miniatura WebP.
 *
 * É a mesma rotina usada pelo catálogo (`TarotImageStorage`) e pelos snapshots
 * do chat (`ConsultationCardImageStorage`): decodifica em memória, reduz para a
 * largura pedida e recomprime em WebP. Cada chamador passa a largura e a
 * qualidade que combinam com o tamanho em que a imagem é desenhada, porque uma
 * grade de células de 90 px e um balão de 104 px não precisam do mesmo arquivo.
 * Nada é gravado aqui — quem chama decide onde o resultado é persistido.
 *
 * A miniatura é sempre um ganho de banda, nunca um requisito: servidor sem GD,
 * imagem corrompida ou com dimensões absurdas devolvem `null` e a rota cai de
 * volta para o arquivo original.
 */
final class ImageThumbnail
{
    /**
     * Limite de pixels da origem (~40 megapixels). Acima disso a imagem não é
     * decodificada, para não estourar a memória de um processo compartilhado.
     */
    private const MAX_SOURCE_PIXELS = 40_000_000;

    /**
     * Largura mínima aceita. Abaixo disso a miniatura deixaria de ser legível
     * nos tamanhos em que é usada.
     */
    private const MIN_TARGET_WIDTH = 64;

    /**
     * Devolve o conteúdo do WebP reduzido ou `null` quando não é possível
     * reduzir a imagem com segurança.
     *
     * @param  int  $targetWidth  Largura final em pixels (a proporção é preservada).
     * @param  int  $quality  Qualidade do WebP, de 1 a 100.
     */
    public static function encode(string $binary, int $targetWidth, int $quality = 72): ?string
    {
        if (
            ! function_exists('imagecreatefromstring')
            || ! function_exists('imagescale')
            || ! function_exists('imagewebp')
        ) {
            return null;
        }

        $size = @getimagesizefromstring($binary);

        if (! is_array($size) || ($size[0] * $size[1]) > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        $targetWidth = max(self::MIN_TARGET_WIDTH, $targetWidth);
        $quality = max(1, min(100, $quality));
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
        $encoded = imagewebp($resized, null, $quality);
        $data = (string) ob_get_clean();

        if ($resized !== $source) {
            imagedestroy($resized);
        }

        imagedestroy($source);

        return $encoded === true && $data !== '' ? $data : null;
    }
}
