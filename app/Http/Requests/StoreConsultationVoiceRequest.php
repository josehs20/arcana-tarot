<?php

namespace App\Http\Requests;

use App\Support\ConsultationVoiceStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Envio de uma gravação de voz no chat do atendimento por áudio.
 *
 * O navegador envia o binário do MediaRecorder mais a duração; o caminho e a
 * extensão são resolvidos no servidor pelo {@see ConsultationVoiceStorage}.
 */
class StoreConsultationVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('sendVoice', $this->route('consultation'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $config = (array) config('consultation.voice');
        $maxSeconds = (int) ($config['max_seconds'] ?? 120);

        return [
            'audio' => [
                'required',
                'file',
                'max:'.(int) ($config['max_kb'] ?? 4096),
            ],
            'duration' => ['nullable', 'integer', 'min:0', 'max:'.$maxSeconds],
            'mime_type' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxSeconds = (int) config('consultation.voice.max_seconds', 120);
        $maxKb = (int) config('consultation.voice.max_kb', 4096);

        return [
            'audio.required' => 'Grave um áudio antes de enviar.',
            'audio.file' => 'A gravação enviada não pôde ser lida.',
            'audio.max' => 'A gravação excede o limite de '.(int) round($maxKb / 1024).' MB.',
            'duration.integer' => 'A duração da gravação é inválida.',
            'duration.min' => 'A duração da gravação é inválida.',
            'duration.max' => 'A gravação excede o limite de '.$maxSeconds.' segundos.',
            'mime_type.string' => 'O formato da gravação é inválido.',
            'mime_type.max' => 'O formato da gravação é inválido.',
        ];
    }

    /**
     * O formato é conferido depois do upload: o `mimes` do Laravel depende da
     * extensão enviada e o MediaRecorder não informa uma extensão confiável.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $file = $this->file('audio');

            if (! $file instanceof UploadedFile) {
                return;
            }

            if ($this->extension() === null) {
                $validator->errors()->add('audio', 'O formato da gravação não é suportado.');
            }
        });
    }

    /**
     * Extensão canônica do arquivo gravado no disco (`webm`, `ogg`, `m4a`...).
     */
    public function extension(): ?string
    {
        $file = $this->file('audio');

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $storage = app(ConsultationVoiceStorage::class);

        return $storage->extensionFor(
            mime: $this->declaredMimeType($file),
            originalExtension: $file->getClientOriginalExtension(),
        );
    }

    public function audioFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('audio');

        return $file;
    }

    /**
     * Duração informada pelo navegador, limitada ao máximo configurado.
     */
    public function durationSeconds(): ?int
    {
        $duration = $this->validated('duration');

        if (! is_numeric($duration)) {
            return null;
        }

        return max(0, (int) $duration);
    }

    /**
     * O mime real do arquivo manda; o campo `mime_type` cobre navegadores que
     * geram um contêiner genérico (ex.: `application/octet-stream`).
     */
    private function declaredMimeType(UploadedFile $file): ?string
    {
        $detected = $file->getMimeType();

        if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
            return $detected;
        }

        $declared = $this->input('mime_type');

        return is_string($declared) && $declared !== '' ? $declared : $detected;
    }
}
