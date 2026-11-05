<?php

namespace App\Http\Requests\Client;

use App\Models\Consultation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreConsultationTestimonialRequest extends FormRequest
{
    /**
     * A autorização real (participante, consulta encerrada e sem depoimento)
     * vive na ConsultationPolicy para ser reutilizada pela interface.
     */
    public function authorize(): bool
    {
        $consultation = $this->route('consultation');

        return $consultation instanceof Consultation
            && Gate::allows('writeTestimonial', $consultation);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Escolha uma nota de 1 a 5 estrelas.',
            'rating.integer' => 'Escolha uma nota de 1 a 5 estrelas.',
            'rating.between' => 'A nota deve estar entre 1 e 5 estrelas.',
            'message.required' => 'Escreva o seu depoimento.',
            'message.min' => 'Descreva sua experiência com pelo menos 10 caracteres.',
            'message.max' => 'O depoimento deve ter no máximo 500 caracteres.',
        ];
    }
}
