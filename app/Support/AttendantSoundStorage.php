<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Upload do MP3 personalizado de aviso de nova solicitação.
 *
 * O navegador do atendente baixa o arquivo, então ele fica no disco público.
 * O nome é aleatório e a extensão vem do MIME real detectado no servidor,
 * nunca do nome enviado pelo navegador.
 */
final class AttendantSoundStorage
{
    /**
     * @return array{path: string, duration_seconds: float, size: int}
     */
    public function store(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $extension = config("attendant.allowed_request_sound_mimes.{$mime}");

        if (! is_string($extension) || $extension === '') {
            throw ValidationException::withMessages([
                'request_sound_file' => 'Envie um arquivo MP3 válido.',
            ]);
        }

        $duration = Mp3Duration::seconds((string) $file->getRealPath());
        $maxSeconds = (float) config('attendant.request_sound_max_seconds', 5);
        $tolerance = (float) config('attendant.request_sound_tolerance_seconds', 0.1);

        if ($duration === null) {
            throw ValidationException::withMessages([
                'request_sound_file' => 'Não foi possível ler a duração do MP3. Envie um arquivo de áudio válido.',
            ]);
        }

        if ($duration > $maxSeconds + $tolerance) {
            throw ValidationException::withMessages([
                'request_sound_file' => sprintf(
                    'O MP3 deve ter no máximo %d segundos.',
                    (int) $maxSeconds,
                ),
            ]);
        }

        $directory = trim((string) config('attendant.request_sound_directory'), '/');

        do {
            $path = $directory.'/'.Str::random(40).'.'.$extension;
        } while ($this->disk()->exists($path));

        $storedPath = $this->disk()->putFileAs($directory, $file, basename($path));

        if ($storedPath === false) {
            throw new RuntimeException('Não foi possível armazenar o MP3 do aviso.');
        }

        return [
            'path' => $storedPath,
            'duration_seconds' => round($duration, 3),
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * Remove um MP3 substituído ou removido pela administração.
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
