<?php

namespace App\Http\Requests\Admin;

use App\Enums\ConsultationModality;
use App\Http\Requests\Admin\Concerns\HandlesAttendantFields;
use App\Models\User;
use App\Support\ConsultationModalityCatalog;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Cadastro de atendente feito pela administração.
 *
 * A conta nasce ativa e verificada (o operador confirma a identidade por outro
 * canal), mas o perfil nasce offline: o diretório público só mostra dados que
 * o próprio atendente mantém atualizados.
 */
class StoreAttendantRequest extends FormRequest
{
    use HandlesAttendantFields;

    /**
     * Idade mínima e limite de sanidade, iguais aos do cadastro público.
     */
    private const MINIMUM_AGE = 18;

    private const MAXIMUM_AGE = 120;

    public function authorize(): bool
    {
        return $this->user() instanceof User
            && Gate::allows('create', User::class);
    }

    /**
     * Normaliza os campos de texto antes da validação para que a persistência
     * use o mesmo formato do cadastro público.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');
        $displayName = $this->input('display_name');

        $this->merge([
            'name' => is_scalar($name) ? Str::squish((string) $name) : $name,
            'email' => is_scalar($email) ? Str::lower(trim((string) $email)) : $email,
            'display_name' => is_scalar($displayName) ? Str::squish((string) $displayName) : $displayName,
            'phone' => $this->normalizedPhoneValue(),
            'specialties' => $this->specialtiesFromInput(),
            'is_published' => $this->boolean('is_published'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => [
                'nullable',
                'string',
                'digits_between:10,11',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! PhoneNumber::isValid((string) $value)) {
                        $fail('Informe um telefone válido com DDD.');
                    }
                },
            ],
            'birth_date' => [
                'nullable',
                'date_format:Y-m-d',
                'before:'.now()->subYears(self::MINIMUM_AGE)->toDateString(),
                'after:'.now()->subYears(self::MAXIMUM_AGE)->toDateString(),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'specialties' => ['nullable', 'array', 'max:6'],
            'specialties.*' => ['string', 'max:40'],
            'photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.max(1, (int) config('attendant.photo_max_kb')),
            ],
            'commission_rate' => ['required', 'string', $this->commissionRateRule()],
            'is_published' => ['boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * Depois do formato, valida regras de negócio que dependem de mais de um
     * campo: modalidade desligada na infraestrutura e texto (modalidade base)
     * sem preço quando outra modalidade foi precificada.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $catalog = app(ConsultationModalityCatalog::class);
                $prices = $this->priceCents();

                foreach ($prices as $modality => $cents) {
                    $enum = ConsultationModality::from((string) $modality);

                    if (! $catalog->isEnabled($enum)) {
                        $validator->errors()->add(
                            'prices.'.$modality,
                            'A modalidade '.$enum->label().' está desativada na infraestrutura.',
                        );
                    }
                }

                if (! isset($prices[ConsultationModality::Text->value])) {
                    $validator->errors()->add(
                        'prices.'.ConsultationModality::Text->value,
                        'Informe o preço por minuto de '.ConsultationModality::Text->label().' para que o atendente possa receber solicitações.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome completo do atendente.',
            'name.min' => 'Informe o nome completo do atendente.',
            'email.required' => 'Informe o e-mail de acesso.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'phone.digits_between' => 'Informe um telefone com DDD (10 ou 11 dígitos).',
            'birth_date.date_format' => 'Informe a data de nascimento no formato DD/MM/AAAA.',
            'birth_date.before' => 'O atendente precisa ter 18 anos ou mais.',
            'birth_date.after' => 'Confira a data de nascimento informada.',
            'password.required' => 'Defina uma senha provisória para o primeiro acesso.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
            'display_name.required' => 'Informe o nome profissional exibido no diretório.',
            'display_name.max' => 'O nome profissional deve ter no máximo 60 caracteres.',
            'bio.max' => 'A apresentação deve ter no máximo 1000 caracteres.',
            'specialties.max' => 'Informe no máximo 6 especialidades.',
            'specialties.*.max' => 'Cada especialidade deve ter no máximo 40 caracteres.',
            'photo.image' => 'Envie um arquivo de imagem.',
            'photo.mimes' => 'Envie uma imagem JPG, PNG ou WebP.',
            'photo.max' => 'A foto deve ter no máximo '.intdiv(max(1, (int) config('attendant.photo_max_kb')), 1024).' MB.',
            'commission_rate.required' => 'Informe a comissão da plataforma.',
            'prices.*.integer' => 'Informe o preço em centavos, sem vírgula (ex.: 250).',
            'prices.*.min' => 'O preço por minuto deve ser maior que zero.',
            'prices.*.max' => 'O preço por minuto informado é muito alto.',
        ];
    }
}
