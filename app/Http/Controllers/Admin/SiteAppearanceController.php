<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteAppearanceRequest;
use App\Support\Appearance;
use App\Support\SiteContentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Editor de cores e fontes do site (tema claro/escuro).
 */
class SiteAppearanceController extends Controller
{
    public function edit(Appearance $appearance): View
    {
        Gate::authorize('access-admin-panel');

        $resolved = $appearance->resolved();

        return view('admin.site.appearance', [
            'colors' => $resolved['colors'],
            'defaults' => (array) config('appearance.defaults', []),
            'colorLabels' => $appearance->colorLabels(),
            'displayFonts' => $appearance->fontOptions('display'),
            'bodyFonts' => $appearance->fontOptions('body'),
            'fonts' => $resolved['fonts'],
            'defaultFonts' => (array) config('appearance.default_fonts', []),
            'isCustomized' => $appearance->isCustomized(),
        ]);
    }

    public function update(
        UpdateSiteAppearanceRequest $request,
        SiteContentManager $manager,
    ): RedirectResponse {
        $manager->updateAppearance($request->user(), [
            'colors' => (array) $request->input('colors', []),
            'fonts' => (array) $request->input('fonts', []),
        ]);

        return redirect()
            ->route('admin.site.appearance.edit')
            ->with('success', 'Aparência atualizada. O site já usa as novas cores e fontes.');
    }

    public function reset(Request $request, SiteContentManager $manager): RedirectResponse
    {
        Gate::authorize('access-admin-panel');

        $manager->resetAppearance($request->user());

        return redirect()
            ->route('admin.site.appearance.edit')
            ->with('success', 'Aparência restaurada para o padrão do tema.');
    }
}
