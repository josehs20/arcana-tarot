<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UserAccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $users = User::query()
            ->with(['wallet', 'attendantProfile'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when(
                isset($filters['role']),
                fn ($query) => $query->where('role', $filters['role']),
            )
            ->when(
                ($filters['status'] ?? null) === 'active',
                fn ($query) => $query->where('is_active', true),
            )
            ->when(
                ($filters['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false),
            )
            ->orderByRaw("case role when 'admin' then 0 when 'attendant' then 1 else 2 end")
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => UserRole::cases(),
        ]);
    }

    public function updateStatus(
        Request $request,
        User $user,
        UserAccessManager $manager,
    ): RedirectResponse {
        Gate::authorize('manageStatus', $user);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        $manager->setActive($user, $active, $request->user());

        return back()->with(
            'success',
            $active
                ? 'Conta ativada com sucesso.'
                : 'Conta desativada com sucesso.',
        );
    }
}
