<?php

namespace App\Support\Images;

/**
 * Resultado da normalização: os bytes que serão gravados e os metadados que
 * acompanham a imagem no banco.
 *
 * O MIME e a extensão sempre descrevem o conteúdo produzido pelo servidor,
 * nunca o nome ou o cabeçalho enviado pelo navegador.
 */
final readonly class ProcessedImage
{
    public function __construct(
        public string $binary,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
    ) {}

    /**
     * Tamanho real do arquivo em bytes.
     */
    public function bytes(): int
    {
        return strlen($this->binary);
    }

    /**
     * Checksum do conteúdo gravado, usado nos ETags do catálogo.
     */
    public function checksum(): string
    {
        return hash('sha256', $this->binary);
    }
}
