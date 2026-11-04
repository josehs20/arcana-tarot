<?php

namespace App\Http\Requests\Admin;

use App\Enums\AttendantRequestSound;
use App\Models\AttendantProfile;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Preferência de toque do atendente para novas solicitações.
 *
 * Apenas a administração altera o som; o atendente não tem tela própria de
 * configuração nesta versão.
 */
class UpdateAttendantRequestSoundRequest extends FormRequest
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
            'request_sound' => ['required', Rule::enum(AttendantRequestSound::class)],
        ];
    }

    /**
     * O som personalizado só pode ser selecionado quando já existe um MP3
     * enviado. O upload em si é feito por outra rota, com validação própria.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('request_sound') !== AttendantRequestSound::Custom->value) {
                return;
            }

            $target = $this->route('user');
            $profile = $target instanceof User ? $target->attendantProfile : null;

            if (! $profile?->requestSoundUrl()) {
                $validator->errors()->add(
                    'request_sound',
                    'Envie um arquivo MP3 antes de selecionar o som personalizado.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_sound.required' => 'Selecione o som de chamada do atendente.',
            'request_sound.enum' => 'O som de chamada selecionado é inválido.',
        ];
    }
}
