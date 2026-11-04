<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqItemRequest;
use App\Models\FaqItem;
use App\Support\SiteContentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteFaqController extends Controller
{
    public function index(): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.site.faqs.index', [
            'faqs' => FaqItem::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'defaultFaqs' => (array) config('site.faqs', []),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.site.faqs.form', [
            'faq' => new FaqItem(['is_active' => true, 'sort_order' => 0]),
        ]);
    }

    public function store(FaqItemRequest $request, SiteContentManager $manager): RedirectResponse
    {
        $manager->createFaq($request->validated(), $request->user());

        return redirect()
            ->route('admin.site.faqs.index')
            ->with('success', 'Pergunta criada e já publicada na home.');
    }

    public function edit(FaqItem $faq): View
    {
        Gate::authorize('access-admin-panel');

        return view('admin.site.faqs.form', [
            'faq' => $faq,
        ]);
    }

    public function update(
        FaqItemRequest $request,
        FaqItem $faq,
        SiteContentManager $manager,
    ): RedirectResponse {
        $manager->updateFaq($faq, $request->validated(), $request->user());

        return redirect()
            ->route('admin.site.faqs.index')
            ->with('success', 'Pergunta atualizada.');
    }

    public function destroy(
        Request $request,
        FaqItem $faq,
        SiteContentManager $manager,
    ): RedirectResponse {
        Gate::authorize('access-admin-panel');

        $manager->deleteFaq($faq, $request->user());

        return redirect()
            ->route('admin.site.faqs.index')
            ->with('success', 'Pergunta removida.');
    }
}
