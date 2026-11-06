<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Media\MemberMedia;
use App\Support\Security\Base64Image;
use Filament\Forms\Components\FileUpload;

/**
 * Filament fields for member images (App\Support\Media\MemberMedia).
 *
 * A photo uploaded here goes to the private disk. An existing photo is shown through a
 * signed address for the signed-in viewer, from whichever disk holds it, and is never
 * dropped from the form because it still sits on the public disk: Filament's own existence
 * check would look on the private disk only and silently clear the field on save.
 */
final class MemberMediaFields
{
    /**
     * The address an ImageColumn/ImageEntry shows for a stored value: a signed member image
     * URL for a stored path, an inline image kept as a valid data URI by older records as is,
     * and nothing for anything else.
     */
    public static function imageSource(mixed $value): ?string
    {
        if (is_string($value) && str_starts_with($value, 'data:image/')) {
            return Base64Image::decode($value) !== null ? $value : null;
        }

        return MemberMedia::url($value);
    }

    public static function photoUpload(string $name, string $directory): FileUpload
    {
        return FileUpload::make($name)
            ->disk(MemberMedia::disk())
            ->directory($directory)
            ->visibility('private')
            ->fetchFileInformation(false)
            ->getUploadedFileUsing(static function (string $file): ?array {
                $url = MemberMedia::url($file);
                if ($url === null) {
                    return null;
                }

                return ['name' => basename($file), 'size' => MemberMedia::size($file) ?? 0, 'type' => null, 'url' => $url];
            });
    }
}
