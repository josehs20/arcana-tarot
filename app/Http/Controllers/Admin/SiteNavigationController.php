<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SiteLinkType;
use App\Enums\SiteNavigationGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SiteNavigationLinkRequest;
use App\Models\SiteNavigationLink;
use App\Support\SiteContentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteNavigationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('access-admin-panel');

        $links = SiteNavigationLink::query()
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.site.navigation.index', [
            'linksByGroup' => collect(SiteNavigationGroup::cases())
                ->mapWithKeys(fn (SiteNavigationGroup $group): array => [
                    $group->value => $links->where('group', $group)->values(),
                ]),
            'groups' => SiteNavigationGroup::cases(),
            'defaults' => (array) config('site.navigation', []),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.site.navigation.form', $this->formData(new SiteNavigationLink([
            'group' => SiteNavigationGroup::Primary,
            'target_type' => SiteLinkType::Route,
            'is_active' => true,
            'sort_order' => 0,
        ])));
    }

    public function store(SiteNavigationLinkRequest $request, SiteContentManager $manager): RedirectResponse
    {
        $manager->createLink($request->validated(), $request->user());

        return redirect()
            ->route('admin.site.navigation.index')
            ->with('success', 'Link criado. A navegação pública já foi atualizada.');
    }

    public function edit(SiteNavigationLink $link): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.site.navigation.form', $this->formData($link));
    }

    public function update(
        SiteNavigationLinkRequest $request,
        SiteNavigationLink $link,
        SiteContentManager $manager,
    ): RedirectResponse {
        $manager->updateLink($link, $request->validated(), $request->user());

        return redirect()
            ->route('admin.site.navigation.index')
            ->with('success', 'Link atualizado.');
    }

    public function destroy(
        Request $request,
        SiteNavigationLink $link,
        SiteContentManager $manager,
    ): RedirectResponse {
        Gate::authorize('access-admin-panel');

        $manager->deleteLink($link, $request->user());

        return redirect()
            ->route('admin.site.navigation.index')
            ->with('success', 'Link removido da navegação.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(SiteNavigationLink $link): array
    {
        return [
            'link' => $link,
            'groups' => SiteNavigationGroup::cases(),
            'allowedRoutes' => (array) config('site.allowed_routes', []),
            'allowedAnchors' => (array) config('site.allowed_anchors', []),
        ];
    }
}
