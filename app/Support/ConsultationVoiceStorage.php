<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\ConsultationMessage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gravações de voz enviadas no chat do atendimento por áudio.
 *
 * O navegador manda apenas o binário gravado pelo MediaRecorder: extensão,
 * caminho e mime final são decididos no servidor a partir da lista em
 * `config('consultation.voice.formats')`. Nada é servido diretamente pelo
 * disco privado — o áudio só sai pela rota autorizada, que valida o caminho
 * contra o diretório da própria consulta a cada requisição.
 */
final class ConsultationVoiceStorage
{
    /**
     * Resolve a extensão canônica para o mime declarado pelo navegador.
     *
     * O MediaRecorder não é consistente entre navegadores e pode enviar
     * `video/webm` para uma gravação só de áudio ou omitir o mime por completo.
     */
    public function extensionFor(?string $mime, ?string $originalExtension = null): ?string
    {
        $normalizedMime = strtolower(trim((string) $mime));
        $formats = $this->formats();

        foreach ($formats as $extension => $format) {
            if (in_array($normalizedMime, (array) ($format['accepts'] ?? []), true)) {
                return (string) $extension;
            }
        }

        $normalizedExtension = strtolower(trim((string) $originalExtension));

        return array_key_exists($normalizedExtension, $formats) ? $normalizedExtension : null;
    }

    /**
     * Grava o binário em `consultation-voice/{consulta}/{aleatório}.{ext}`.
     */
    public function store(Consultation $consultation, UploadedFile $file, string $extension): string
    {
        $extension = strtolower($extension);
        $formats = $this->formats();

        if (! array_key_exists($extension, $formats)) {
            throw ValidationException::withMessages([
                'audio' => 'O formato da gravação não é suportado.',
            ]);
        }

        $directory = $this->directory().'/'.$consultation->getKey();

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível ler a gravação enviada.');
        }

        try {
            $written = $this->disk()->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            $this->disk()->delete($path);

            throw new RuntimeException('Não foi possível salvar a gravação no histórico.');
        }

        return $path;
    }

    /**
     * Remove apenas um arquivo recém-criado cuja transação falhou.
     */
    public function discardNewFile(?string $path): void
    {
        if (filled($path)) {
            $this->disk()->delete($path);
        }
    }

    public function response(ConsultationMessage $message): StreamedResponse
    {
        $path = $this->validatedPath($message);

        if (! $this->disk()->exists($path)) {
            abort(404);
        }

        $mime = $this->validatedMime($path);
        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            abort(404);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $this->disk()->size($path),
            'Content-Disposition' => sprintf('inline; filename="audio-%d.%s"', $message->getKey(), $extension),
            'Accept-Ranges' => 'none',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * O mime gravado é conferido a cada requisição: um arquivo fora da lista
     * permitida nunca é servido ao navegador.
     *
     * O `finfo` identifica uma gravação só de áudio em contêiner WebM como
     * `video/webm` — o MediaRecorder usa o mesmo cabeçalho de vídeo. Por isso a
     * checagem cobre todos os mimes declarados para a extensão e a resposta usa
     * sempre o mime canônico do formato, que é o que o player espera.
     */
    private function validatedMime(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $format = $this->formats()[$extension] ?? null;

        if (! is_array($format)) {
            abort(404);
        }

        $canonical = (string) ($format['mime'] ?? '');
        $accepted = array_unique(array_filter(array_merge([$canonical], (array) ($format['accepts'] ?? []))));
        $detected = $this->disk()->mimeType($path);

        if (! is_string($detected) || ! in_array($detected, $accepted, true)) {
            abort(404);
        }

        return $canonical !== '' ? $canonical : $detected;
    }

    /**
     * Defesa em profundidade: mesmo que um caminho inválido chegue ao banco por
     * erro operacional, a rota só serve arquivos dentro do diretório da própria
     * consulta.
     */
    private function validatedPath(ConsultationMessage $message): string
    {
        $path = (string) $message->audio_path;
        $prefix = $this->directory().'/'.$message->consultation_id.'/';

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

    /**
     * @return array<string, array{mime: string, accepts: list<string>}>
     */
    private function formats(): array
    {
        return (array) config('consultation.voice.formats', []);
    }

    private function directory(): string
    {
        return trim((string) config('consultation.voice.directory'), '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('consultation.voice.disk'));
    }
}
