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
                'tarot_card_id' => 'Esta carta não possui uma imagem disponível para envio.',
            ]);
        }

        if (! $this->disk()->exists($source)) {
            throw ValidationException::withMessages([
                'tarot_card_id' => 'Esta carta não possui uma imagem disponível para envio.',
            ]);
        }

        $mime = $this->disk()->mimeType($source) ?: $card->image_mime;
        $extension = is_string($mime) ? config("tarot.allowed_image_mimes.{$mime}") : null;

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'tarot_card_id' => 'A imagem desta carta não está em um formato permitido.',
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

        $allowedMimes = array_keys((array) config('tarot.allowed_image_mimes'));
        $mime = $this->disk()->mimeType($path);

        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            abort(404);
        }

        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        $extension = config("tarot.allowed_image_mimes.{$mime}", 'img');

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
