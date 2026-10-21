<?php

namespace App\Http\Requests\Admin;

use App\Models\AttendantApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReviewAttendantApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $application instanceof AttendantApplication
            && Gate::allows('review', $application);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('internal_note');
        $note = is_scalar($raw) ? trim((string) $raw) : null;

        $this->merge(['internal_note' => $note === '' ? null : $note]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'internal_note.max' => 'A nota interna deve ter no máximo 2000 caracteres.',
        ];
    }
}
