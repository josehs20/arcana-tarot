<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendantApplicationRequest;
use App\Support\AttendantApplicationSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AttendantApplicationController extends Controller
{
    public function create(): View
    {
        return view('public.work-with-us');
    }

    public function store(
        StoreAttendantApplicationRequest $request,
        AttendantApplicationSubmission $submission,
    ): RedirectResponse {
        // Honeypot: responde como sucesso para não ensinar o bot a contornar,
        // mas não persiste nada. O campo fica invisível para pessoas.
        if (filled($request->input('website'))) {
            return $this->confirmation();
        }

        $submission->submit($request->validated());

        return $this->confirmation();
    }

    private function confirmation(): RedirectResponse
    {
        return redirect()
            ->route('work-with-us.index')
            ->with('application_submitted', true);
    }
}
