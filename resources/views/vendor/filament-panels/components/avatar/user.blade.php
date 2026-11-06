@props([
    'user' => filament()->auth()->user(),
])

@php
    $customAvatar = $user instanceof \Filament\Models\Contracts\HasAvatar
        ? $user->getFilamentAvatarUrl()
        : $user->getAttributeValue('avatar_url');
    $avatarLabel = __('filament-panels::layout.avatar.alt', ['name' => filament()->getUserName($user)]);
    $avatarAttributes = \Filament\Support\prepare_inherited_attributes($attributes)->class(['fi-user-avatar']);
@endphp

@if ($customAvatar)
    <x-filament::avatar
        :src="$customAvatar"
        :alt="$avatarLabel"
        :attributes="$avatarAttributes"
    />
@else
    @php
        // First and last initial only ("PC"), like the dashboard avatars: every-word initials
        // joined with spaces ("P U T C") wrapped onto two lines in the 32px circle.
        $nameParts = str(filament()->getNameForDefaultAvatar($user))
            ->squish()
            ->explode(' ')
            ->filter(fn (string $segment): bool => filled($segment))
            ->values();
        $initials = mb_strtoupper(
            mb_substr((string) $nameParts->first(), 0, 1)
            .($nameParts->count() > 1 ? mb_substr((string) $nameParts->last(), 0, 1) : '')
        );
        $size = $avatarAttributes->get('size', 'md');
        $circular = $avatarAttributes->get('circular', true);
    @endphp

    <span
        role="img"
        aria-label="{{ $avatarLabel }}"
        {{
            $avatarAttributes
                ->except(['size', 'circular'])
                ->class([
                    'fi-avatar object-cover object-center inline-flex flex-none items-center justify-center whitespace-nowrap leading-none bg-gray-950 text-white text-xs font-medium',
                    'rounded-md' => ! $circular,
                    'fi-circular rounded-full' => $circular,
                    match ($size) {
                        'sm' => 'h-6 w-6',
                        'md' => 'h-8 w-8',
                        'lg' => 'h-10 w-10',
                        default => $size,
                    },
                ])
        }}
    >{{ $initials }}</span>
@endif
