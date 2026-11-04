<?php

namespace App\Http\Requests\Admin;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Tarifas por modalidade.
 *
 * O valor por minuto continua sendo `creditos × valor do credito`, regra
 * financeira ja existente. Cada modalidade possui a sua propria quantidade de
 * creditos por minuto. O campo legado `rate_credits_per_minute` e mantido em
 * sincronia com a modalidade texto para integracoes antigas.
 */
class UpdateConsultationPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Wallet::class)
            && $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rate_credits_per_minute_text' => ['required', 'integer', 'min:1', 'max:100000'],
            'rate_credits_per_minute_audio' => ['required', 'integer', 'min:1', 'max:100000'],
            'rate_credits_per_minute_video' => ['required', 'integer', 'min:1', 'max:100000'],
            'credit_unit_price_cents' => ['required', 'integer', 'min:1', 'max:1000000'],
            'minimum_balance_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate_credits_per_minute_text.required' => 'Informe a tarifa do atendimento por texto.',
            'rate_credits_per_minute_audio.required' => 'Informe a tarifa do atendimento por áudio.',
            'rate_credits_per_minute_video.required' => 'Informe a tarifa do atendimento por vídeo.',
            'rate_credits_per_minute_text.integer' => 'A tarifa deve ser um número inteiro de créditos.',
            'rate_credits_per_minute_audio.integer' => 'A tarifa deve ser um número inteiro de créditos.',
            'rate_credits_per_minute_video.integer' => 'A tarifa deve ser um número inteiro de créditos.',
            'rate_credits_per_minute_text.min' => 'A tarifa deve ser de pelo menos 1 crédito por minuto.',
            'rate_credits_per_minute_audio.min' => 'A tarifa deve ser de pelo menos 1 crédito por minuto.',
            'rate_credits_per_minute_video.min' => 'A tarifa deve ser de pelo menos 1 crédito por minuto.',
            'rate_credits_per_minute_text.max' => 'A tarifa por minuto excede o limite permitido.',
            'rate_credits_per_minute_audio.max' => 'A tarifa por minuto excede o limite permitido.',
            'rate_credits_per_minute_video.max' => 'A tarifa por minuto excede o limite permitido.',
            'credit_unit_price_cents.required' => 'Informe o valor do crédito em centavos.',
            'credit_unit_price_cents.integer' => 'O valor do crédito deve ser um número inteiro de centavos.',
            'credit_unit_price_cents.min' => 'O valor do crédito deve ser maior que zero.',
            'credit_unit_price_cents.max' => 'O valor do crédito excede o limite permitido.',
            'minimum_balance_minutes.required' => 'Informe o saldo mínimo em minutos.',
            'minimum_balance_minutes.integer' => 'O saldo mínimo deve ser um número inteiro de minutos.',
            'minimum_balance_minutes.min' => 'O saldo mínimo deve ser de pelo menos 1 minuto.',
            'minimum_balance_minutes.max' => 'O saldo mínimo não pode exceder 1.440 minutos (24 horas).',
        ];
    }
}
