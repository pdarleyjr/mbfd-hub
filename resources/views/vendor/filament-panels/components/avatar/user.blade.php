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
        $initials = str(filament()->getNameForDefaultAvatar($user))
            ->trim()
            ->explode(' ')
            ->map(fn (string $segment): string => filled($segment) ? mb_substr($segment, 0, 1) : '')
            ->join(' ');
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
                    'fi-avatar object-cover object-center inline-flex items-center justify-center bg-gray-950 text-white text-xs font-medium',
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
