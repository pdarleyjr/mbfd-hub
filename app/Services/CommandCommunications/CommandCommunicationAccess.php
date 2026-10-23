<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationPost;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\Command300Access;

/**
 * Who may do what in the shared 300 Command / Captain 5 conversation. One class answers every
 * channel question so the API, the Filament page and the attachment download cannot drift:
 * the 300 channel is `Command300Access`, the Captain 5 channel is `Captain5Access` view plus the
 * `captain5.communications_enabled` kill switch, and an administrator's "all" view is simply
 * the union of the channels that administrator can read. Nothing is memoized here: callers
 * evaluate once per operation (controllers and tests may outlive a single request).
 */
final class CommandCommunicationAccess
{
    /** The administrators' combined view: every channel the administrator may read. */
    public const SCOPE_ALL = 'all';

    public const PERMISSION_VIEW = 'admin.communications.view';

    public const PERMISSION_MANAGE = 'admin.communications.manage';

    public function __construct(
        private readonly Command300Access $command300,
        private readonly Captain5Access $captain5,
    ) {}

    public function captain5Enabled(): bool
    {
        return (bool) config('captain5.communications_enabled', true);
    }

    /** @return list<string> the channels this user can read, in display order */
    public function readableChannels(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }
        $channels = [];
        if ($this->command300->allows($user)) {
            $channels[] = CommandCommunicationPost::CHANNEL_COMMAND_300;
        }
        if ($this->captain5Enabled() && $this->captain5->canView($user)) {
            $channels[] = CommandCommunicationPost::CHANNEL_CAPTAIN_5;
        }

        return $channels;
    }

    public function canRead(?User $user, string $channel): bool
    {
        if ($channel === self::SCOPE_ALL) {
            return $this->isAdministrator($user) && $this->readableChannels($user) !== [];
        }

        return in_array($channel, $this->readableChannels($user), true);
    }

    /**
     * The channels a feed request covers: one named channel, or every readable channel for an
     * administrator's "all" view. Empty when the user may not read the requested scope.
     *
     * @return list<string>
     */
    public function channelsFor(?User $user, string $scope): array
    {
        if (! $this->canRead($user, $scope)) {
            return [];
        }

        return $scope === self::SCOPE_ALL ? $this->readableChannels($user) : [$scope];
    }

    /**
     * Audiences a user can see in a scope.
     *
     * @return list<string>
     */
    public function audiencesFor(?User $user, string $scope): array
    {
        return self::audiencesForChannels($this->channelsFor($user, $scope));
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    public static function audiencesForChannels(array $channels): array
    {
        $audiences = [];
        foreach ($channels as $channel) {
            $audiences = [...$audiences, ...CommandCommunicationPost::audiencesFor($channel)];
        }

        return array_values(array_unique($audiences));
    }

    /** A post is visible when its audience includes a channel the user can read. */
    public function canSee(?User $user, CommandCommunicationPost $post): bool
    {
        return array_intersect(CommandCommunicationPost::channelsFor($post->audience), $this->readableChannels($user)) !== [];
    }

    /**
     * The Admin Panel communications area (the Filament page's own rule, without the 300
     * requirement): current Admin Panel entitlement and super_admin or a direct view grant.
     */
    public function isAdministrator(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isAuthenticationAllowed()
            && ! $user->must_change_password
            && $user->hasCurrentAdminPanelEntitlement()
            && ($user->hasRole('super_admin') || $user->hasDirectWebPermission(self::PERMISSION_VIEW));
    }

    /** May remove anyone's message: super_admin or a direct `admin.communications.manage` grant. */
    public function canModerate(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isAuthenticationAllowed()
            && ! $user->must_change_password
            && $user->hasCurrentAdminPanelEntitlement()
            && ($user->hasRole('super_admin') || $user->hasDirectWebPermission(self::PERMISSION_MANAGE));
    }

    /** Own message, or any message for a moderator. */
    public function canDelete(?User $user, CommandCommunicationPost $post): bool
    {
        return $user instanceof User
            && ! $post->isDeleted()
            && ((int) $post->author_user_id === (int) $user->getKey() || $this->canModerate($user));
    }

    /**
     * Pinning in the 300 channel is open to every 300 reader (unchanged). In the Captain 5 channel
     * it needs the right to act on Captain 5 issues, or an administrator. The administrators'
     * "all" scope is administrators only.
     */
    public function canPin(?User $user, string $scope): bool
    {
        if (! $this->canRead($user, $scope)) {
            return false;
        }

        return match ($scope) {
            CommandCommunicationPost::CHANNEL_COMMAND_300 => true,
            CommandCommunicationPost::CHANNEL_CAPTAIN_5 => $this->captain5->canAct($user) || $this->isAdministrator($user),
            default => $this->isAdministrator($user),
        };
    }
}
