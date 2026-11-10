<?php

namespace App\Http\Requests\Attendant;

use App\Enums\AttendantAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active
            && (bool) $this->user()?->isAttendant();
    }

    /**
     * Os três estados declarados são aceitos. "Ocupado" também nasce do aceite
     * de uma consulta, mas o atendente pode pausar a fila manualmente.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'availability' => ['required', Rule::enum(AttendantAvailability::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'availability.required' => 'Informe a disponibilidade desejada.',
            'availability.enum' => 'Disponibilidade inválida. Use online, ocupado ou offline.',
        ];
    }
}
