<?php

namespace App\Http\Requests\Admin;

use App\Models\AttendantProfile;
use App\Models\User;
use App\Support\Rate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class UpdateAttendantCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $profile = $target instanceof User ? $target->attendantProfile : null;

        return $target instanceof User
            && $target->isAttendant()
            && $profile instanceof AttendantProfile
            && Gate::allows('updateCommission', $profile);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'commission_rate' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Rate::parsePercentToBps($this->normalizeRate($value)) === null) {
                        $fail('Informe um percentual entre 0,00 e 100,00, com até duas casas decimais.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commission_rate.required' => 'Informe o percentual de comissão do atendente.',
        ];
    }

    public function rateBps(): int
    {
        $rateBps = Rate::parsePercentToBps($this->normalizeRate($this->validated('commission_rate')));

        if ($rateBps === null) {
            throw new InvalidArgumentException('Percentual de comissão inválido.');
        }

        return $rateBps;
    }

    private function normalizeRate(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : null;
    }
}
