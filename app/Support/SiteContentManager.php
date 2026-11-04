<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Models\SiteNavigationLink;
use App\Models\SiteSection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Casos de uso da área "Conteúdo do site".
 *
 * Uploads são gravados fora da transação para que a falha do banco não deixe
 * arquivo órfão; o arquivo substituído só é removido depois do commit.
 * Toda alteração relevante gera audit_log com metadados textuais.
 */
final class SiteContentManager
{
    public function __construct(private SiteMediaStorage $media) {}

    /**
     * @param  array{site_name: string, site_description: string, remove_logo?: bool, remove_favicon?: bool}  $data
     */
    public function updateIdentity(User $actor, array $data, ?UploadedFile $logo = null, ?UploadedFile $favicon = null): void
    {
        $newLogo = $logo !== null ? $this->media->store($logo) : null;
        $newFavicon = $favicon !== null ? $this->media->store($favicon) : null;

        $oldLogo = null;
        $oldFavicon = null;
        $finalLogo = null;
        $finalFavicon = null;

        try {
            DB::transaction(function () use (
                $actor,
                $data,
                $newLogo,
                $newFavicon,
                &$oldLogo,
                &$oldFavicon,
                &$finalLogo,
                &$finalFavicon,
            ): void {
                $oldLogo = Setting::query()->where('key', 'site_logo_path')->value('value');
                $oldFavicon = Setting::query()->where('key', 'site_favicon_path')->value('value');

                $finalLogo = $newLogo ?? $oldLogo;
                if ($newLogo === null && (bool) ($data['remove_logo'] ?? false)) {
                    $finalLogo = null;
                }

                $finalFavicon = $newFavicon ?? $oldFavicon;
                if ($newFavicon === null && (bool) ($data['remove_favicon'] ?? false)) {
                    $finalFavicon = null;
                }

                $previousName = (string) (Setting::query()->where('key', 'site_name')->value('value')
                    ?? config('site.profile_keys.site_name'));
                $previousDescription = (string) (Setting::query()->where('key', 'site_description')->value('value')
                    ?? config('site.profile_keys.site_description'));

                $this->putSetting('site_name', trim($data['site_name']));
                $this->putSetting('site_description', trim($data['site_description']));
                $this->putSetting('site_logo_path', $finalLogo);
                $this->putSetting('site_favicon_path', $finalFavicon);

                $mediaChanged = $oldLogo !== $finalLogo || $oldFavicon !== $finalFavicon;
                $textChanged = $previousName !== trim($data['site_name'])
                    || $previousDescription !== trim($data['site_description']);

                if (! $mediaChanged && ! $textChanged) {
                    return;
                }

                $this->audit($actor, 'site.identity_updated', null, [
                    'site_name' => trim($data['site_name']),
                    'description_changed' => $previousDescription !== trim($data['site_description']),
                    'logo' => $this->mediaChange($oldLogo, $finalLogo),
                    'favicon' => $this->mediaChange($oldFavicon, $finalFavicon),
                ]);
            });
        } catch (Throwable $exception) {
            $this->media->delete($newLogo);
            $this->media->delete($newFavicon);

            throw $exception;
        }

        if ($oldLogo !== $finalLogo) {
            $this->media->delete($oldLogo);
        }

        if ($oldFavicon !== $finalFavicon) {
            $this->media->delete($oldFavicon);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(string $key, array $data, User $actor, ?UploadedFile $image = null): SiteSection
    {
        $newImage = $image !== null ? $this->media->store($image) : null;

        $oldImage = null;
        $finalImage = null;

        try {
            $section = DB::transaction(function () use (
                $key,
                $data,
                $actor,
                $newImage,
                &$oldImage,
                &$finalImage,
            ): SiteSection {
                $section = SiteSection::query()->lockForUpdate()->firstOrNew(['key' => $key]);
                $oldImage = $section->image_path;

                $section->fill([
                    'eyebrow' => $this->nullableString($data['eyebrow'] ?? null),
                    'title' => trim((string) $data['title']),
                    'subtitle' => $this->nullableString($data['subtitle'] ?? null),
                    'body' => $this->nullableString($data['body'] ?? null),
                    'image_alt' => $this->nullableString($data['image_alt'] ?? null),
                    'primary_cta_label' => $this->nullableString($data['primary_cta_label'] ?? null),
                    'primary_cta_target' => $this->nullableString($data['primary_cta_target'] ?? null),
                    'secondary_cta_label' => $this->nullableString($data['secondary_cta_label'] ?? null),
                    'secondary_cta_target' => $this->nullableString($data['secondary_cta_target'] ?? null),
                    'is_published' => (bool) ($data['is_published'] ?? false),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                ]);

                if ($newImage !== null) {
                    $section->image_path = $newImage;
                }

                if ($newImage === null && (bool) ($data['remove_image'] ?? false)) {
                    $section->image_path = null;
                }

                $finalImage = $section->image_path;
                $changed = array_keys($section->getDirty());
                $section->save();

                if ($changed !== []) {
                    $this->audit($actor, 'site.section_updated', $section, [
                        'section' => $key,
                        'title' => $section->title,
                        'is_published' => $section->is_published,
                        'changed' => $changed,
                        'image_replaced' => $newImage !== null,
                    ]);
                }

                return $section;
            });
        } catch (Throwable $exception) {
            $this->media->delete($newImage);

            throw $exception;
        }

        if ($oldImage !== null && $oldImage !== $finalImage) {
            $this->media->delete($oldImage);
        }

        return $section;
    }

    /**
     * @param  array{label: string, group: string, target_type: string, target: string, opens_in_new_tab?: bool, is_active?: bool, sort_order?: int}  $data
     */
    public function createLink(array $data, User $actor): SiteNavigationLink
    {
        return DB::transaction(function () use ($data, $actor): SiteNavigationLink {
            $link = SiteNavigationLink::query()->create([
                'label' => trim($data['label']),
                'group' => $data['group'],
                'target_type' => $data['target_type'],
                'target' => trim($data['target']),
                'opens_in_new_tab' => (bool) ($data['opens_in_new_tab'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $this->audit($actor, 'site.navigation_created', $link, [
                'label' => $link->label,
                'group' => $link->group->value,
                'target' => $link->target,
                'is_active' => $link->is_active,
            ]);

            return $link;
        });
    }

    /**
     * @param  array{label: string, group: string, target_type: string, target: string, opens_in_new_tab?: bool, is_active?: bool, sort_order?: int}  $data
     */
    public function updateLink(SiteNavigationLink $link, array $data, User $actor): SiteNavigationLink
    {
        return DB::transaction(function () use ($link, $data, $actor): SiteNavigationLink {
            $locked = SiteNavigationLink::query()->lockForUpdate()->findOrFail($link->getKey());

            $locked->fill([
                'label' => trim($data['label']),
                'group' => $data['group'],
                'target_type' => $data['target_type'],
                'target' => trim($data['target']),
                'opens_in_new_tab' => (bool) ($data['opens_in_new_tab'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                $this->audit($actor, 'site.navigation_updated', $locked, [
                    'label' => $locked->label,
                    'group' => $locked->group->value,
                    'target' => $locked->target,
                    'is_active' => $locked->is_active,
                    'changed' => $changed,
                ]);
            }

            return $locked;
        });
    }

    public function deleteLink(SiteNavigationLink $link, User $actor): void
    {
        DB::transaction(function () use ($link, $actor): void {
            $locked = SiteNavigationLink::query()->lockForUpdate()->findOrFail($link->getKey());

            $this->audit($actor, 'site.navigation_deleted', $locked, [
                'label' => $locked->label,
                'group' => $locked->group->value,
                'target' => $locked->target,
            ]);

            $locked->delete();
        });
    }

    /**
     * @param  array{question: string, answer: string, is_active?: bool, sort_order?: int}  $data
     */
    public function createFaq(array $data, User $actor): FaqItem
    {
        return DB::transaction(function () use ($data, $actor): FaqItem {
            $faq = FaqItem::query()->create([
                'question' => trim($data['question']),
                'answer' => trim($data['answer']),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $this->audit($actor, 'site.faq_created', $faq, [
                'question' => $faq->question,
                'is_active' => $faq->is_active,
            ]);

            return $faq;
        });
    }

    /**
     * @param  array{question: string, answer: string, is_active?: bool, sort_order?: int}  $data
     */
    public function updateFaq(FaqItem $faq, array $data, User $actor): FaqItem
    {
        return DB::transaction(function () use ($faq, $data, $actor): FaqItem {
            $locked = FaqItem::query()->lockForUpdate()->findOrFail($faq->getKey());

            $locked->fill([
                'question' => trim($data['question']),
                'answer' => trim($data['answer']),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                $this->audit($actor, 'site.faq_updated', $locked, [
                    'question' => $locked->question,
                    'is_active' => $locked->is_active,
                    'changed' => $changed,
                ]);
            }

            return $locked;
        });
    }

    public function deleteFaq(FaqItem $faq, User $actor): void
    {
        DB::transaction(function () use ($faq, $actor): void {
            $locked = FaqItem::query()->lockForUpdate()->findOrFail($faq->getKey());

            $this->audit($actor, 'site.faq_deleted', $locked, [
                'question' => $locked->question,
            ]);

            $locked->delete();
        });
    }

    private function putSetting(string $key, ?string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => 'string'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function mediaChange(?string $old, ?string $new): string
    {
        if ($old === $new) {
            return 'kept';
        }

        return $new === null ? 'removed' : 'replaced';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, string $action, ?Model $subject, array $metadata = []): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
