<?php

namespace App\Support;

use App\Enums\SiteLinkType;
use App\Enums\SiteNavigationGroup;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Models\SiteNavigationLink;
use App\Models\SiteSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fonte única do conteúdo institucional exibido no site.
 *
 * O banco só guarda o que foi sobrescrito pelo CMS; quando não há registro
 * (ou quando a tabela ainda não existe em um banco recém-criado), os
 * valores de `config/site.php` são usados. Isso mantém a home funcional
 * mesmo sem seed e evita divergência entre Blade e painel.
 */
final class SiteContent
{
    /**
     * Links resolvidos por grupo, memoizados por instância.
     *
     * @var array<string, array<int, array<string, mixed>>>|null
     */
    private ?array $navigationCache = null;

    /**
     * @var array<string, SiteSection>|null
     */
    private ?array $sectionCache = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $faqCache = null;

    /**
     * @var array<string, string|null>|null
     */
    private ?array $settingCache = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sections(): array
    {
        $sections = [];

        foreach (array_keys((array) config('site.sections', [])) as $key) {
            $sections[(string) $key] = $this->section((string) $key);
        }

        // A ordem definida no CMS vale também para a home: as chaves são
        // preservadas para que o Blade continue acessando $sections['hero'].
        uasort(
            $sections,
            fn (array $a, array $b): int => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0),
        );

        return $sections;
    }

    /**
     * Conteúdo resolvido de uma seção, pronto para o Blade.
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config("site.sections.{$key}", []);
        $model = $this->sectionModels()[$key] ?? null;

        $imagePath = $model?->image_path;

        return [
            'key' => $key,
            'eyebrow' => $model?->eyebrow ?? ($defaults['eyebrow'] ?? null),
            'title' => $model?->title ?? ($defaults['title'] ?? ''),
            'subtitle' => $model?->subtitle ?? ($defaults['subtitle'] ?? null),
            'body' => $model?->body ?? ($defaults['body'] ?? null),
            'image_url' => $this->mediaUrl($imagePath),
            'image_alt' => $model?->image_alt ?? ($defaults['image_alt'] ?? null),
            'primary_cta_label' => $model?->primary_cta_label ?? ($defaults['primary_cta_label'] ?? null),
            'primary_cta_url' => $this->resolveTarget(
                $model?->primary_cta_target ?? ($defaults['primary_cta_target'] ?? null),
            ),
            'secondary_cta_label' => $model?->secondary_cta_label ?? ($defaults['secondary_cta_label'] ?? null),
            'secondary_cta_url' => $this->resolveTarget(
                $model?->secondary_cta_target ?? ($defaults['secondary_cta_target'] ?? null),
            ),
            'is_published' => $model?->is_published ?? true,
            'sort_order' => $model?->sort_order ?? (int) ($defaults['sort_order'] ?? 0),
            'is_customized' => $model instanceof SiteSection,
            'updated_at' => $model?->updated_at,
        ];
    }

    /**
     * Links ativos de um grupo. Se o grupo nunca foi editado, usa o padrão.
     *
     * @return array<int, array<string, mixed>>
     */
    public function navigation(string $group = 'primary'): array
    {
        return $this->navigationCache()[$group] ?? [];
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public function faqs(): array
    {
        if ($this->faqCache !== null) {
            return $this->faqCache;
        }

        $items = FaqItem::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            /** @var array<int, array{question: string, answer: string}> $defaults */
            $defaults = (array) config('site.faqs', []);

            return $this->faqCache = $defaults;
        }

        return $this->faqCache = $items
            ->where('is_active', true)
            ->map(fn (FaqItem $item): array => [
                'question' => $item->question,
                'answer' => $item->answer,
            ])
            ->values()
            ->all();
    }

    public function name(): string
    {
        $name = trim((string) ($this->settings()['site_name'] ?? ''));

        return $name !== '' ? $name : (string) config('site.profile_keys.site_name', 'Arcana Tarot');
    }

    /**
     * Nome do site lido direto do banco, sem o cache do singleton.
     *
     * Use em processos de vida longa (workers de fila, comandos) e em serviços
     * fora do ciclo de request, onde o nome pode ter sido alterado no painel
     * depois que a instância compartilhada memoizou as configurações.
     */
    public static function currentName(): string
    {
        return (new self)->name();
    }

    public function description(): string
    {
        $description = trim((string) ($this->settings()['site_description'] ?? ''));

        return $description !== ''
            ? $description
            : (string) config('site.profile_keys.site_description', '');
    }

    public function logoUrl(): ?string
    {
        return $this->mediaUrl($this->settings()['site_logo_path'] ?? null);
    }

    public function faviconUrl(): string
    {
        return $this->mediaUrl($this->settings()['site_favicon_path'] ?? null)
            ?? asset('favicon.svg');
    }

    public function hasCustomLogo(): bool
    {
        return $this->mediaUrl($this->settings()['site_logo_path'] ?? null) !== null;
    }

    public function hasCustomFavicon(): bool
    {
        return $this->mediaUrl($this->settings()['site_favicon_path'] ?? null) !== null;
    }

    /**
     * Traduz um destino do CMS em URL segura.
     *
     * Aceita rota da allowlist, âncora conhecida da home ou URL https.
     * Qualquer outro valor retorna null (o link não é renderizado).
     */
    public function resolveTarget(?string $target, ?string $type = null): ?string
    {
        $target = trim((string) $target);

        if ($target === '') {
            return null;
        }

        $type ??= $this->detectType($target);

        return match ($type) {
            SiteLinkType::Route->value => $this->routeUrl($target),
            SiteLinkType::Anchor->value => $this->anchorUrl($target),
            SiteLinkType::Url->value => $this->externalUrl($target),
            default => null,
        };
    }

    public function detectType(string $target): string
    {
        $target = trim($target);

        if (str_starts_with($target, '#')) {
            return SiteLinkType::Anchor->value;
        }

        if (preg_match('#^https?://#i', $target) === 1) {
            return SiteLinkType::Url->value;
        }

        return SiteLinkType::Route->value;
    }

    /**
     * URL de mídia no disco público, apenas se o arquivo existir.
     */
    public function mediaUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /**
     * @return array<string, SiteSection>
     */
    private function sectionModels(): array
    {
        if ($this->sectionCache !== null) {
            return $this->sectionCache;
        }

        return $this->sectionCache = SiteSection::query()
            ->get()
            ->keyBy('key')
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function settings(): array
    {
        if ($this->settingCache !== null) {
            return $this->settingCache;
        }

        /** @var array<string, string|null> $defaults */
        $defaults = (array) config('site.profile_keys', []);

        /** @var array<string, string|null> $stored */
        $stored = Setting::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->all();

        return $this->settingCache = array_merge($defaults, $stored);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function navigationCache(): array
    {
        if ($this->navigationCache !== null) {
            return $this->navigationCache;
        }

        /** @var Collection<int, SiteNavigationLink> $rows */
        $rows = SiteNavigationLink::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $resolved = [];

        foreach (SiteNavigationGroup::cases() as $group) {
            $groupRows = $rows->filter(fn (SiteNavigationLink $link): bool => $link->group === $group);

            if ($groupRows->isEmpty()) {
                $resolved[$group->value] = $this->defaultNavigation($group);

                continue;
            }

            $resolved[$group->value] = $groupRows
                ->where('is_active', true)
                ->map(fn (SiteNavigationLink $link): ?array => $this->resolveLink(
                    label: $link->label,
                    type: $link->target_type->value,
                    target: $link->target,
                    newTab: $link->opens_in_new_tab,
                    order: $link->sort_order,
                ))
                ->filter()
                ->values()
                ->all();
        }

        return $this->navigationCache = $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultNavigation(SiteNavigationGroup $group): array
    {
        /** @var array<int, array<string, string>> $links */
        $links = (array) config("site.navigation.{$group->value}", []);
        $resolved = [];

        foreach ($links as $index => $link) {
            $item = $this->resolveLink(
                label: (string) ($link['label'] ?? ''),
                type: (string) ($link['target_type'] ?? SiteLinkType::Route->value),
                target: (string) ($link['target'] ?? ''),
                newTab: false,
                order: $index,
            );

            if ($item !== null) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * @return array{label: string, url: string, route_name: string|null, external: bool, new_tab: bool, sort_order: int}|null
     */
    private function resolveLink(string $label, string $type, string $target, bool $newTab, int $order): ?array
    {
        $url = $this->resolveTarget($target, $type);

        if ($url === null) {
            return null;
        }

        $external = $type === SiteLinkType::Url->value;

        return [
            'label' => $label,
            'url' => $url,
            'route_name' => $type === SiteLinkType::Route->value ? $target : null,
            'external' => $external,
            'new_tab' => $newTab || $external,
            'sort_order' => $order,
        ];
    }

    private function routeUrl(string $name): ?string
    {
        /** @var array<int, string> $allowed */
        $allowed = (array) config('site.allowed_routes', []);

        if (in_array($name, $allowed, true) && Route::has($name)) {
            try {
                return route($name);
            } catch (Throwable) {
                return null;
            }
        }

        // O administrador costuma copiar o caminho que vê no navegador
        // (`/contato`) em vez do nome da rota (`contact`). Aceitamos o caminho
        // desde que ele pertença a uma rota da allowlist.
        return $this->allowedPathUrl($name, $allowed);
    }

    /**
     * Converte um caminho interno (`/contato`) na URL da rota correspondente.
     *
     * @param  array<int, string>  $allowed
     */
    private function allowedPathUrl(string $path, array $allowed): ?string
    {
        $normalized = '/'.trim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');

        foreach ($allowed as $name) {
            if (! Route::has($name)) {
                continue;
            }

            try {
                $url = route($name);
            } catch (Throwable) {
                continue;
            }

            $routePath = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

            if ($routePath === $normalized) {
                return $url;
            }
        }

        return null;
    }

    private function anchorUrl(string $target): ?string
    {
        $slug = ltrim(trim($target), '#');

        /** @var array<int, string> $allowed */
        $allowed = (array) config('site.allowed_anchors', []);

        if ($slug === '' || ! in_array($slug, $allowed, true)) {
            return null;
        }

        return route('home').'#'.$slug;
    }

    private function externalUrl(string $target): ?string
    {
        if (! str_starts_with(strtolower($target), 'https://')) {
            return null;
        }

        return filter_var($target, FILTER_VALIDATE_URL) === false ? null : $target;
    }
}
