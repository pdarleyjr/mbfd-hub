<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCommunications\Concerns;

use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\CommandCommunications\CommandCommunicationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;

/**
 * Users and helpers for the shared 300 Command / Captain 5 conversation tests, on top of the
 * Captain 5 world (clock pinned to an A-shift duty day).
 *
 * Who can read what: a Lieutenant reads Captain 5 only (officer rank, no 300 rank), a
 * Firefighter with the direct `command.300.access` grant reads 300 only, a Division Chief or
 * Captain reads both, a plain Firefighter reads neither.
 */
trait BuildsSharedCommunicationsWorld
{
    use BuildsCaptain5World;

    private const COMMS_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const COMMS_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private int $commsSequence = 0;

    protected function buildCommunicationsWorld(): void
    {
        $this->buildCaptain5World();
        Permission::findOrCreate('command.300.access', 'web');
        Storage::fake('local');
    }

    /** 300 channel only. */
    protected function command300Member(string $name = 'Command Member'): User
    {
        $user = $this->officer('Firefighter', $name);
        $user->givePermissionTo(Permission::findOrCreate('command.300.access', 'web'));
        $this->forgetCaptain5Memo();

        return $user->fresh();
    }

    /** Captain 5 channel only. */
    protected function captain5Viewer(string $name = 'Lieutenant Viewer'): User
    {
        return $this->officer('Lieutenant', $name);
    }

    /** Both channels. */
    protected function bothChannelsMember(string $name = 'Chief Reader'): User
    {
        return $this->officer('Division Chief', $name);
    }

    /** Neither channel. */
    protected function outsider(string $name = 'Plain Firefighter'): User
    {
        return $this->officer('Firefighter', $name);
    }

    /** An administrator on the Filament page who may view (but not moderate) the communications area. */
    protected function communicationsViewer(string $name = 'Communications Viewer'): User
    {
        $user = $this->officer('Firefighter', $name);
        $user->givePermissionTo(array_map(
            static fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'),
            ['admin.access', 'admin.communications.view'],
        ));
        $this->forgetCaptain5Memo();

        return $user->fresh();
    }

    /** An administrator who may also remove anyone's message. */
    protected function communicationsModerator(string $name = 'Communications Moderator'): User
    {
        $user = $this->communicationsViewer($name);
        $user->givePermissionTo(Permission::findOrCreate('admin.communications.manage', 'web'));
        $this->forgetCaptain5Memo();

        return $user->fresh();
    }

    /** @param  list<UploadedFile>  $files */
    protected function adminPost(User $admin, string $audience, string $body = 'Admin message', array $files = [], ?string $parent = null): CommandCommunicationPost
    {
        return app(CommandCommunicationService::class)->post(
            $admin,
            CommandCommunicationPost::CONTEXT_ADMINISTRATION,
            null,
            $body,
            $parent,
            'admin-'.++$this->commsSequence.'-'.bin2hex(random_bytes(4)),
            $files,
            $audience,
        );
    }

    protected function submissionId(): string
    {
        return 'sub-'.++$this->commsSequence.'-'.bin2hex(random_bytes(4));
    }

    protected function pdfUpload(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::COMMS_PDF);
    }

    protected function pngUpload(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(self::COMMS_PNG, true));
    }

    /** Posts through a channel's HTTP API as $user and returns the new post's public id. */
    protected function postVia(User $user, string $channel, string $body, ?string $parent = null): string
    {
        $this->actAs($user);

        return (string) $this->postJson($this->commsBase($channel).'/posts', [
            'body' => $body, 'parent_id' => $parent, 'client_submission_id' => $this->submissionId(),
        ])->assertCreated()->json('data.id');
    }

    protected function commsBase(string $channel): string
    {
        return $channel === 'captain_5' ? '/api/captain5/communications' : '/api/command-communications';
    }

    /** @return list<string> top-level post ids in a user's feed, newest first */
    protected function feedIds(User $user, string $channel): array
    {
        $this->actAs($user);

        return array_column($this->getJson($this->commsBase($channel).'/posts')->assertOk()->json('data'), 'id');
    }
}
