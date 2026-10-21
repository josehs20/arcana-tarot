<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendantApplicationRequest extends FormRequest
{
    /**
     * Unidades federativas aceitas no campo opcional de estado.
     *
     * @var list<string>
     */
    private const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT',
        'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO',
        'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawPhone = $this->input('phone');
        $phone = is_scalar($rawPhone)
            ? (preg_replace('/\D+/', '', (string) $rawPhone) ?? '')
            : '';

        // Aceita o formato +55 (DDD) número e guarda apenas DDD + número.
        if (in_array(strlen($phone), [12, 13], true) && str_starts_with($phone, '55')) {
            $phone = substr($phone, 2);
        }

        $state = $this->clean($this->input('state'));

        $this->merge([
            'name' => Str::squish((string) ($this->clean($this->input('name')) ?? '')),
            'email' => Str::lower((string) ($this->clean($this->input('email')) ?? '')),
            'phone' => $phone,
            'city' => $this->clean($this->input('city')),
            'state' => $state !== null ? Str::upper($state) : null,
            'experience_summary' => $this->clean($this->input('experience_summary')),
            'message' => $this->clean($this->input('message')),
            'specialties' => $this->clean($this->input('specialties')),
            'portfolio_url' => $this->clean($this->input('portfolio_url')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => [
                'required',
                'string',
                'digits_between:10,11',
                'regex:/^[1-9]{2}(?:9\d{8}|[2-9]\d{7})$/',
            ],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2', Rule::in(self::UFS)],
            'experience_summary' => ['required', 'string', 'min:20', 'max:2000'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'specialties' => ['nullable', 'string', 'max:300'],
            'portfolio_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'consent' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $raw = trim((string) $this->input('specialties'));

            if ($raw === '') {
                return;
            }

            $items = array_values(array_filter(array_map('trim', explode(',', $raw))));

            if (count($items) > 8) {
                $validator->errors()->add('specialties', 'Informe no máximo 8 especialidades.');
            }

            foreach ($items as $item) {
                if (mb_strlen($item) > 40) {
                    $validator->errors()->add('specialties', 'Cada especialidade deve ter no máximo 40 caracteres.');
                    break;
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome completo.',
            'name.min' => 'Informe seu nome completo.',
            'name.max' => 'O nome deve ter no máximo 120 caracteres.',
            'email.required' => 'Informe um e-mail para contato.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',
            'phone.required' => 'Informe um telefone ou WhatsApp para contato.',
            'phone.digits_between' => 'Informe um telefone/WhatsApp com DDD (10 ou 11 dígitos).',
            'phone.regex' => 'Informe um telefone/WhatsApp válido com DDD.',
            'city.max' => 'A cidade deve ter no máximo 120 caracteres.',
            'state.size' => 'Informe a sigla do estado com 2 letras.',
            'state.in' => 'Informe uma unidade federativa válida.',
            'experience_summary.required' => 'Conte um pouco sobre sua experiência com Tarot.',
            'experience_summary.min' => 'Descreva sua experiência com pelo menos 20 caracteres.',
            'experience_summary.max' => 'A experiência deve ter no máximo 2000 caracteres.',
            'message.required' => 'Conte como você conduz seus atendimentos.',
            'message.min' => 'Descreva sua abordagem de atendimento com pelo menos 20 caracteres.',
            'message.max' => 'A apresentação deve ter no máximo 2000 caracteres.',
            'specialties.max' => 'As especialidades devem ter no máximo 300 caracteres no total.',
            'portfolio_url.url' => 'Informe um link válido começando com http:// ou https://.',
            'portfolio_url.max' => 'O link deve ter no máximo 255 caracteres.',
            'consent.required' => 'É necessário autorizar o uso dos dados para analisar a candidatura.',
            'consent.accepted' => 'É necessário autorizar o uso dos dados para analisar a candidatura.',
        ];
    }

    private function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
