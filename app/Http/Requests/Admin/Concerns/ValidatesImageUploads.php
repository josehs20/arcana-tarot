<?php

namespace App\Http\Requests\Admin\Concerns;

/**
 * Regras compartilhadas pelos formulários do painel que recebem imagens.
 *
 * O teto é único (`config/images.max_upload_kb`) para que a mensagem exibida,
 * a validação do Laravel e os limites do PHP/Nginx não divirjam. A validação
 * aqui é apenas a primeira barreira: o conteúdo real, as dimensões e a
 * recompressão são conferidos por `App\Support\Images\ImageNormalizer` antes de
 * qualquer arquivo ser gravado.
 */
trait ValidatesImageUploads
{
    /**
     * @return array<int, string>
     */
    protected function imageUploadRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.max(1, (int) config('images.max_upload_kb', 25600)),
        ];
    }

    /**
     * Teto em megabytes usado nas mensagens de validação e no texto de ajuda.
     */
    protected function imageUploadMaxMb(): int
    {
        return max(1, intdiv(max(1, (int) config('images.max_upload_kb', 25600)), 1024));
    }
}
