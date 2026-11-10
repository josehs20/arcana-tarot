<?php

namespace App\Http\Controllers\Attendant;

use App\Enums\AttendantAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendant\UpdateAvailabilityRequest;
use App\Support\AttendantAvailabilityManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class AvailabilityController extends Controller
{
    public function update(
        UpdateAvailabilityRequest $request,
        AttendantAvailabilityManager $manager,
    ): RedirectResponse {
        $user = $request->user();
        $profile = $user->attendantProfile;

        abort_if($profile === null, 404);

        Gate::authorize('updateAvailability', $profile);

        $availability = AttendantAvailability::from($request->validated('availability'));

        $manager->set($user, $availability);

        return back()->with('success', match ($availability) {
            AttendantAvailability::Online => 'Você está online e pode receber solicitações.',
            AttendantAvailability::Busy => 'Você está ocupado e não receberá novas solicitações até ficar online novamente.',
            AttendantAvailability::Offline => 'Você está offline e não receberá novas solicitações.',
        });
    }
}
