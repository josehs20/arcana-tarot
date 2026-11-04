<?php

namespace App\Http\Controllers;

use App\Enums\ThemePreference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemePreferenceController extends Controller
{
    /**
     * Persiste a preferência de tema do usuário autenticado.
     */
    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::enum(ThemePreference::class)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->theme_preference = ThemePreference::from((string) $validated['theme']);
        $user->save();

        if ($request->expectsJson()) {
            return response()->json(['theme' => $user->theme_preference->value]);
        }

        return back();
    }
}
