<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteIdentityRequest;
use App\Http\Requests\Admin\UpdateSiteSectionRequest;
use App\Models\SiteSection;
use App\Support\Appearance;
use App\Support\SiteContent;
use App\Support\SiteContentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteContentController extends Controller
{
    public function index(SiteContent $site, Appearance $appearance): View
    {
        Gate::authorize('access-admin-panel');

        $sections = collect($site->sections())->sortBy('sort_order')->values();

        return view('admin.site.index', [
            'identity' => [
                'name' => $site->name(),
                'description' => $site->description(),
                'logo_url' => $site->logoUrl(),
                'favicon_url' => $site->faviconUrl(),
                'has_logo' => $site->hasCustomLogo(),
                'has_favicon' => $site->hasCustomFavicon(),
            ],
            'sections' => $sections,
            'sectionLabels' => (array) config('site.section_labels', []),
            'faqCount' => count($site->faqs()),
            'navigationCount' => count($site->navigation('primary')) + count($site->navigation('footer')),
            'appearance' => [
                'is_customized' => $appearance->isCustomized(),
            ],
        ]);
    }

    public function updateIdentity(
        UpdateSiteIdentityRequest $request,
        SiteContentManager $manager,
    ): RedirectResponse {
        $manager->updateIdentity(
            $request->user(),
            $request->validated(),
            $request->file('logo'),
            $request->file('favicon'),
        );

        return redirect()
            ->route('admin.site.index')
            ->with('success', 'Identidade do site atualizada.');
    }

    public function editSection(string $key, SiteContent $site): View
    {
        Gate::authorize('access-admin-panel');

        abort_unless(array_key_exists($key, (array) config('site.sections', [])), 404);

        $model = SiteSection::query()->where('key', $key)->first();
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config("site.sections.{$key}", []);

        return view('admin.site.section', [
            'key' => $key,
            'label' => (string) (config("site.section_labels.{$key}") ?? $key),
            'section' => $site->section($key),
            'sectionAnchor' => config("site.section_anchors.{$key}"),
            'isCustomized' => $model instanceof SiteSection,
            'imagePath' => $model?->image_path,
            'ctaTargets' => [
                'primary' => $model?->primary_cta_target ?? ($defaults['primary_cta_target'] ?? ''),
                'secondary' => $model?->secondary_cta_target ?? ($defaults['secondary_cta_target'] ?? ''),
            ],
            'allowedRoutes' => (array) config('site.allowed_routes', []),
            'allowedAnchors' => (array) config('site.allowed_anchors', []),
            'hint' => config("site.section_hints.{$key}"),
        ]);
    }

    public function updateSection(
        UpdateSiteSectionRequest $request,
        string $key,
        SiteContentManager $manager,
    ): RedirectResponse {
        abort_unless(array_key_exists($key, (array) config('site.sections', [])), 404);

        $manager->updateSection(
            $key,
            $request->validated(),
            $request->user(),
            $request->file('image'),
        );

        return redirect()
            ->route('admin.site.sections.edit', ['key' => $key])
            ->with('success', 'Seção atualizada. O site público já exibe o novo conteúdo.');
    }
}
