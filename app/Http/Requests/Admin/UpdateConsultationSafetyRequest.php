<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Limites de segurança do atendimento.
 *
 * A faixa aceita é idêntica à normalização feita por `ConsultationSafety`:
 * inatividade de 1 a 120 minutos e tolerância de reconexão de 0 a 600 segundos.
 * O servidor é sempre a fonte da verdade; o formulário só altera a política.
 */
class UpdateConsultationSafetyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('access-admin-panel')
            && $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'inactivity_timeout_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'reconnection_tolerance_seconds' => ['required', 'integer', 'min:0', 'max:600'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inactivity_timeout_minutes.required' => 'Informe o tempo de inatividade em minutos.',
            'inactivity_timeout_minutes.integer' => 'O tempo de inatividade deve ser um número inteiro de minutos.',
            'inactivity_timeout_minutes.min' => 'O tempo de inatividade deve ser de pelo menos 1 minuto.',
            'inactivity_timeout_minutes.max' => 'O tempo de inatividade não pode exceder 120 minutos.',
            'reconnection_tolerance_seconds.required' => 'Informe a tolerância de reconexão em segundos.',
            'reconnection_tolerance_seconds.integer' => 'A tolerância de reconexão deve ser um número inteiro de segundos.',
            'reconnection_tolerance_seconds.min' => 'A tolerância de reconexão não pode ser negativa.',
            'reconnection_tolerance_seconds.max' => 'A tolerância de reconexão não pode exceder 600 segundos.',
        ];
    }
}
