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
 * O favicon é a exceção ao WebP: navegadores antigos e alguns agregadores
 * ainda esperam PNG quadrado para o ícone da aba.
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
     * Formato final do arquivo gravado.
     */
    public function format(): string
    {
        return $this === self::SiteFavicon ? 'png' : 'webp';
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
