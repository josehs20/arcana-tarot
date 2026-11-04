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
     * Apenas online/offline são aceitos. "Ocupado" nunca é enviado pelo
     * formulário: é derivado de consulta ativa no servidor.
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
            'availability.enum' => 'Disponibilidade inválida. Use online ou offline.',
        ];
    }
}
