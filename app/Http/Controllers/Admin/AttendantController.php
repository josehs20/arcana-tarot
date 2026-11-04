<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendantRequestSound;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAttendantRequestSoundRequest;
use App\Http\Requests\Admin\UploadAttendantRequestSoundRequest;
use App\Models\AttendantProfile;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AttendantSoundStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

/**
 * Cadastro administrativo dos atendentes e preferências de atendimento.
 *
 * Nesta tela a administração ajusta o toque executado no navegador do
 * atendente quando um cliente entra na fila. O som é apenas um aviso visual e
 * sonoro: nenhuma regra de aceite ou cobrança depende dele.
 */
class AttendantController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        $attendants = User::query()
            ->where('role', UserRole::Attendant->value)
            ->with('attendantProfile')
            ->whereHas('attendantProfile')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhereHas('attendantProfile', fn ($profile) => $profile
                            ->where('display_name', 'like', $term));
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.attendants.index', [
            'attendants' => $attendants,
            'search' => $search,
            'sounds' => AttendantRequestSound::presets(),
            'customSound' => AttendantRequestSound::Custom,
        ]);
    }

    public function updateSound(
        UpdateAttendantRequestSoundRequest $request,
        User $user,
    ): RedirectResponse {
        $profile = $user->attendantProfile;

        abort_unless($user->isAttendant() && $profile instanceof AttendantProfile, 404);

        $after = (string) $request->validated('request_sound');

        $changed = DB::transaction(function () use ($request, $profile, $after): bool {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $before = $locked->requestSound()->value;

            if ($before === $after) {
                return false;
            }

            $locked->request_sound = $after;
            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'attendant.request_sound_updated',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ]);

            return true;
        });

        return back()->with(
            'success',
            $changed
                ? 'Som de chamada atualizado. O novo toque vale para as próximas solicitações recebidas no painel do atendente após atualizar a página.'
                : 'O som de chamada selecionado já estava configurado.',
        );
    }

    /**
     * Recebe o MP3 personalizado de até 5 segundos e o seleciona como toque.
     */
    public function uploadSound(
        UploadAttendantRequestSoundRequest $request,
        User $user,
        AttendantSoundStorage $sounds,
    ): RedirectResponse {
        $profile = $user->attendantProfile;
        $file = $request->file('request_sound_file');

        abort_unless(
            $user->isAttendant()
                && $profile instanceof AttendantProfile
                && $file instanceof UploadedFile,
            404,
        );

        $stored = $sounds->store($file);
        $replacedPath = null;

        try {
            DB::transaction(function () use ($request, $profile, $stored, &$replacedPath): void {
                $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
                $before = $locked->requestSound()->value;
                $replacedPath = $locked->request_sound_path;

                $locked->forceFill([
                    'request_sound' => AttendantRequestSound::Custom->value,
                    'request_sound_path' => $stored['path'],
                ])->save();

                AuditLog::query()->create([
                    'actor_id' => $request->user()->getKey(),
                    'action' => 'attendant.request_sound_file_uploaded',
                    'subject_type' => $locked->getMorphClass(),
                    'subject_id' => $locked->getKey(),
                    'metadata' => [
                        'before' => $before,
                        'after' => AttendantRequestSound::Custom->value,
                        'previous_file' => $replacedPath,
                        'file' => $stored['path'],
                        'duration_seconds' => $stored['duration_seconds'],
                        'size' => $stored['size'],
                    ],
                ]);
            });
        } catch (Throwable $exception) {
            // O registro não foi confirmado: o arquivo novo não pode ficar órfão.
            $sounds->delete($stored['path']);

            throw $exception;
        }

        // A troca só chega ao disco depois do commit; se o banco falhar, o MP3
        // antigo continua sendo o arquivo válido do perfil.
        $sounds->delete($replacedPath);

        return back()->with(
            'success',
            'MP3 personalizado enviado e selecionado. O novo toque vale para as próximas solicitações após o atendente atualizar a página.',
        );
    }

    /**
     * Remove o MP3 personalizado. Se ele era o som ativo, volta para a sirene.
     */
    public function destroySound(
        Request $request,
        User $user,
        AttendantSoundStorage $sounds,
    ): RedirectResponse {
        $profile = $user->attendantProfile;

        abort_unless($user->isAttendant() && $profile instanceof AttendantProfile, 404);

        Gate::authorize('updateRequestSound', $profile);

        $removedPath = null;

        $changed = DB::transaction(function () use ($request, $profile, &$removedPath): bool {
            $locked = AttendantProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $path = $locked->request_sound_path;

            if (blank($path)) {
                return false;
            }

            $before = $locked->requestSound()->value;
            $after = $before === AttendantRequestSound::Custom->value
                ? AttendantRequestSound::default()->value
                : $before;

            $locked->forceFill([
                'request_sound' => $after,
                'request_sound_path' => null,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $request->user()->getKey(),
                'action' => 'attendant.request_sound_file_removed',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'metadata' => [
                    'before' => $before,
                    'after' => $after,
                    'file' => $path,
                ],
            ]);

            $removedPath = $path;

            return true;
        });

        $sounds->delete($removedPath);

        return back()->with(
            'success',
            $changed
                ? 'MP3 personalizado removido. Se ele era o som ativo, a sirene voltou a ser usada.'
                : 'Nenhum MP3 personalizado estava configurado.',
        );
    }
}
