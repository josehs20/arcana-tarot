<?php

namespace App\Http\Requests\Admin;

use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Upload do MP3 personalizado de aviso de nova solicitação.
 *
 * O limite real de duração é conferido no armazenamento, que lê os quadros
 * MPEG do arquivo. Aqui validamos tipo, extensão e tamanho antes de gravar.
 */
class UploadAttendantRequestSoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $profile = $target instanceof User ? $target->attendantProfile : null;

        return $target instanceof User
            && $target->isAttendant()
            && $profile instanceof AttendantProfile
            && Gate::allows('updateRequestSound', $profile);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'request_sound_file' => [
                'required',
                'file',
                'extensions:mp3',
                'mimetypes:'.implode(',', array_keys((array) config('attendant.allowed_request_sound_mimes'))),
                'max:'.max(1, (int) config('attendant.request_sound_max_kb', 1024)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_sound_file.required' => 'Selecione um arquivo MP3.',
            'request_sound_file.file' => 'Não foi possível receber o arquivo. Verifique o tamanho e tente novamente.',
            'request_sound_file.extensions' => 'O arquivo precisa ter a extensão .mp3.',
            'request_sound_file.mimetypes' => 'O conteúdo do arquivo não corresponde a um MP3.',
            'request_sound_file.max' => 'O MP3 deve ter no máximo '.(int) config('attendant.request_sound_max_kb', 1024).' KB.',
            'request_sound_file.uploaded' => 'Não foi possível receber o MP3. Verifique o tamanho do arquivo e tente novamente.',
        ];
    }
}
