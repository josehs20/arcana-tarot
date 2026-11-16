<?php

namespace App\Support\Images;

/**
 * Perfis de saída das imagens enviadas pelo painel.
 *
 * Cada tela desenha a imagem em um tamanho diferente. Guardar o arquivo
 * original de 6–15 MB de um celular não traz ganho visual nenhum e ainda
 * multiplica o tráfego. O perfil define o maior tamanho útil e o formato de
 * saída: `scaleDown()` nunca amplia uma imagem menor, então o perfil é um teto,
 * não um redimensionamento cego.
 *
 * WebP só é gravado nas imagens internas do painel (cartas do catálogo e fotos
 * de atendentes). As imagens do CMS público — logo, favicon e mídia das seções
 * — ficam restritas a PNG/JPEG: o logo também aparece em clientes de e-mail e o
 * favicon em abas e agregadores que nem sempre decodificam WebP, e o operador
 * precisa reencontrar no site o mesmo tipo de arquivo que enviou.
 */
enum ImageProfile: string
{
    case TarotCard = 'tarot_card';
    case SiteSection = 'site_section';
    case SiteLogo = 'site_logo';
    case SiteFavicon = 'site_favicon';
    case AttendantPhoto = 'attendant_photo';

    /**
     * Largura máxima em pixels (a proporção original é preservada).
     */
    public function maxWidth(): int
    {
        return match ($this) {
            self::TarotCard => 1200,
            self::SiteSection => 2400,
            self::SiteLogo => 1200,
            self::SiteFavicon => 512,
            self::AttendantPhoto => 1200,
        };
    }

    /**
     * Altura máxima em pixels (a proporção original é preservada).
     */
    public function maxHeight(): int
    {
        return match ($this) {
            self::TarotCard => 1800,
            self::SiteSection => 1600,
            self::SiteLogo => 1200,
            self::SiteFavicon => 512,
            self::AttendantPhoto => 1200,
        };
    }

    /**
     * Formato final do arquivo gravado (`png`, `jpeg` ou `webp`).
     *
     * Perfis do CMS nunca produzem WebP. Quando o MIME real da origem é
     * informado, uma seção enviada em PNG continua PNG (preserva a
     * transparência) e as demais origens viram JPEG. Logo e favicon são sempre
     * PNG; sem o MIME de origem a seção usa JPEG, que é o formato das fotos.
     */
    public function outputFormat(?string $sourceMime = null): string
    {
        return match ($this) {
            self::SiteLogo, self::SiteFavicon => 'png',
            self::SiteSection => $sourceMime === 'image/png' ? 'png' : 'jpeg',
            self::TarotCard, self::AttendantPhoto => 'webp',
        };
    }

    /**
     * O perfil grava apenas PNG ou JPEG — nenhum arquivo WebP é salvo.
     */
    public function storesOnlyPngOrJpeg(): bool
    {
        return match ($this) {
            self::SiteLogo, self::SiteFavicon, self::SiteSection => true,
            self::TarotCard, self::AttendantPhoto => false,
        };
    }

    /**
     * O formato gravado acompanha a origem quando ela é PNG?
     */
    public function preservesPngSource(): bool
    {
        return $this === self::SiteSection;
    }

    /**
     * Formato gravado escrito para o texto de ajuda do campo de upload.
     */
    public function outputFormatLabel(): string
    {
        return match ($this) {
            self::SiteLogo, self::SiteFavicon => 'PNG',
            self::SiteSection => 'PNG (quando enviada em PNG) ou JPEG',
            self::TarotCard, self::AttendantPhoto => 'WebP',
        };
    }

    /**
     * Qualidade da compressão (0–100). Ignorada pelo encoder PNG.
     */
    public function quality(): int
    {
        return match ($this) {
            self::TarotCard => 85,
            self::SiteSection => 82,
            self::SiteLogo => 88,
            self::SiteFavicon => 90,
            self::AttendantPhoto => 85,
        };
    }
}
