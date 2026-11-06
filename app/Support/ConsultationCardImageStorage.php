<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\TarotCard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cópias privadas das cartas enviadas no chat.
 *
 * O navegador informa apenas o ID da carta. O caminho do snapshot é gerado no
 * servidor e a imagem do catálogo é copiada para ele. Assim, editar, substituir
 * ou inativar o cadastro original não altera o histórico da consulta.
 */
final class ConsultationCardImageStorage
{
    public function snapshot(Consultation $consultation, TarotCard $card): string
    {
        $source = $card->image_path;
        $catalogPrefix = trim((string) config('tarot.image_directory'), '/').'/';

        if (
            ! filled($source)
            || str_contains($source, "\0")
            || str_contains($source, '..')
            || ! str_starts_with($source, $catalogPrefix)
        ) {
            throw ValidationException::withMessages([
                'tarot_card_ids' => 'Esta carta não possui uma imagem disponível para envio.',
            ]);
        }

        if (! $this->disk()->exists($source)) {
            throw ValidationException::withMessages([
                'tarot_card_ids' => 'Esta carta não possui uma imagem disponível para envio.',
            ]);
        }

        $mime = $this->disk()->mimeType($source) ?: $card->image_mime;
        $extension = is_string($mime) ? config("tarot.allowed_image_mimes.{$mime}") : null;

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'tarot_card_ids' => 'A imagem desta carta não está em um formato permitido.',
            ]);
        }

        $directory = trim((string) config('tarot.snapshot_directory'), '/')
            .'/'.$consultation->getKey();

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $stream = $this->disk()->readStream($source);

        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler a imagem da carta.');
        }

        if (! $this->disk()->writeStream($path, $stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->disk()->delete($path);

            throw new RuntimeException('Não foi possível preservar a imagem da carta no histórico.');
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    /**
     * Remove apenas um snapshot recém-criado cuja transação falhou.
     */
    public function discardNewFile(?string $path): void
    {
        if (filled($path)) {
            $this->disk()->delete($path);
        }
    }

    public function response(ConsultationMessage $message): StreamedResponse
    {
        $path = $this->validatedSnapshotPath($message);

        if (! $this->disk()->exists($path)) {
            abort(404);
        }

        return $this->streamedResponse($message, $path, $this->validatedMime($path));
    }

    /**
     * Miniatura WebP exibida no balão do chat.
     *
     * A conversa mostra cada carta em ~150 px e a página é recarregada inteira
     * ao navegar pelo histórico. Servir o snapshot original custava dezenas de
     * KB por carta; a miniatura fica na casa dos KB. O WebP é gerado uma única
     * vez com o GD e gravado em `thumbnails/`, dentro do diretório da própria
     * consulta, então a validação de caminho continua valendo.
     *
     * A imagem cheia continua em `response()`, aberta pelo link do balão. Sem
     * GD, com arquivo corrompido ou fora dos formatos permitidos a rota cai de
     * volta para o snapshot original: a carta nunca deixa de aparecer.
     */
    public function thumbnailResponse(ConsultationMessage $message): StreamedResponse
    {
        $source = $this->validatedSnapshotPath($message);

        if (! $this->disk()->exists($source)) {
            abort(404);
        }

        // O MIME é validado antes de o arquivo chegar ao GD.
        $mime = $this->validatedMime($source);
        $thumbnail = $this->ensureThumbnail($message, $source);

        if ($thumbnail === null) {
            return $this->streamedResponse($message, $source, $mime);
        }

        return $this->streamedResponse($message, $thumbnail, 'image/webp');
    }

    /**
     * Caminho do WebP reduzido, gerando-o quando ainda não existe.
     *
     * @return string|null `null` quando o servidor não consegue reduzir a imagem.
     */
    private function ensureThumbnail(ConsultationMessage $message, string $sourcePath): ?string
    {
        $thumbnailPath = $this->thumbnailPath($message, $sourcePath);

        if ($this->disk()->exists($thumbnailPath)) {
            return $thumbnailPath;
        }

        $binary = $this->disk()->get($sourcePath);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $thumbnail = ImageThumbnail::encode(
            $binary,
            (int) config('tarot.snapshot_thumbnail.width', 180),
            (int) config('tarot.snapshot_thumbnail.quality', 72),
        );

        if ($thumbnail === null) {
            return null;
        }

        return $this->disk()->put($thumbnailPath, $thumbnail) ? $thumbnailPath : null;
    }

    /**
     * `consultation-cards/{consulta}/thumbnails/{arquivo}.webp`.
     *
     * Fica dentro do diretório da consulta para que a validação de caminho
     * (`consultation-cards/{id}/`) cubra também o arquivo reduzido.
     */
    private function thumbnailPath(ConsultationMessage $message, string $sourcePath): string
    {
        return trim((string) config('tarot.snapshot_directory'), '/')
            .'/'.$message->consultation_id
            .'/thumbnails/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.webp';
    }

    private function streamedResponse(ConsultationMessage $message, string $path, string $mime): StreamedResponse
    {
        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        $extension = $mime === 'image/webp' ? 'webp' : config("tarot.allowed_image_mimes.{$mime}", 'img');

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $this->disk()->size($path),
            'Content-Disposition' => sprintf('inline; filename="carta-%d.%s"', $message->getKey(), $extension),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * O MIME gravado é conferido a cada requisição: um arquivo fora da lista
     * permitida nunca é servido nem decodificado.
     */
    private function validatedMime(string $path): string
    {
        $allowedMimes = array_keys((array) config('tarot.allowed_image_mimes'));
        $mime = $this->disk()->mimeType($path);

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            abort(404);
        }

        return $mime;
    }

    /**
     * Defesa em profundidade: mesmo que um caminho inválido chegue ao banco
     * por erro operacional, a rota só serve arquivos dentro do diretório do
     * snapshot da própria consulta. Bloqueia traversal e acesso a caminhos
     * arbitrários do disco privado.
     */
    private function validatedSnapshotPath(ConsultationMessage $message): string
    {
        $path = (string) $message->image_path;
        $prefix = trim((string) config('tarot.snapshot_directory'), '/')
            .'/'.$message->consultation_id.'/';

        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '..')
            || ! str_starts_with($path, $prefix)
        ) {
            abort(404);
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('tarot.image_disk'));
    }
}
