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

        $manager->set($user, AttendantAvailability::from($request->validated('availability')));

        return back()->with(
            'success',
            $request->validated('availability') === AttendantAvailability::Online->value
                ? 'Você está online e pode receber solicitações.'
                : 'Você está offline e não receberá novas solicitações.',
        );
    }
}
