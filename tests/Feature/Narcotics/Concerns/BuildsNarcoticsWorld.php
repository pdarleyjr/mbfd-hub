<?php

declare(strict_types=1);

namespace Tests\Feature\Narcotics\Concerns;

use App\Models\Narcotics\NarcoticsCheck;
use App\Models\User;
use App\Notifications\Inspections\InspectionEventNotification;
use App\Services\Narcotics\NarcoticsImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;

/**
 * A small narcotics check world: a member, another member, a Captain 5 seat holder (the reviewer), a
 * Captain-rank officer and an Admin Panel administrator who are NOT reviewers, and a super administrator.
 * Reuses the EMS people builders; the EMS source import is not needed (buildEmsWorld() is not called).
 */
trait BuildsNarcoticsWorld
{
    use BuildsEmsWorld;

    protected User $member;

    protected User $otherMember;

    protected User $captain5;

    protected User $officer;

    protected User $administrator;

    protected User $superAdmin;

    protected function buildNarcoticsWorld(): void
    {
        Storage::fake(NarcoticsImageStore::disk());
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('narcotics_checks.enabled', true);

        $this->member = $this->emsMember('Firefighter', 'Pat Member');
        $this->otherMember = $this->emsMember('Firefighter', 'Olive Other');
        $this->captain5 = $this->emsMember('Captain', 'Cap Five');
        $this->emsCaptain5Seat($this->captain5);
        $this->officer = $this->emsCaptain('Cap Officer'); // Captain rank: may open the Captain 5 page, may NOT review narcotics checks
        $this->administrator = $this->emsAdmin([], 'Ada Admin');
        $this->superAdmin = $this->emsSuperAdmin();
        Notification::fake();
    }

    /** Signs everyone out (actingAs in setUp otherwise lasts for the whole test). */
    protected function signOut(): void
    {
        $this->app['auth']->guard('web')->forgetUser();
    }

    // ----------------------------------------------------------------- images

    /** A solid-colour left half and right half, so a rotation or flip can be seen in the pixels. */
    protected function jpegBytes(int $width = 800, int $height = 600, int $orientation = 1, int $left = 0xE02020, int $right = 0x2020E0): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, $left);
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, $right);
        ob_start();
        imagejpeg($image, null, 92);
        $bytes = (string) ob_get_clean();

        return $orientation > 1 ? $this->withExifOrientation($bytes, $orientation) : $bytes;
    }

    protected function pngBytes(int $width = 800, int $height = 600, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, 0x20A020);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Inserts a minimal EXIF segment (with GPS-looking text) carrying the orientation right after the JPEG start marker. */
    protected function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0).pack('V', 0);
        $payload = "Exif\0\0".$tiff.'GPSLatitudeRef=N';

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    protected function upload(string $bytes, string $name = 'photo.jpg', string $mime = 'image/jpeg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    // ------------------------------------------------------------------ calls

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function sendCheck(User $user, ?UploadedFile $photo = null, array $fields = [], bool $withPhoto = true): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user);
        $payload = $fields + ['client_submission_id' => (string) Str::uuid()];
        if ($withPhoto) {
            $payload['photo'] = $photo ?? $this->upload($this->jpegBytes());
        }

        return $this->post('/api/narcotics-checks', $payload, ['Accept' => 'application/json']);
    }

    protected function submitOk(User $user, array $fields = [], ?string $bytes = null): NarcoticsCheck
    {
        $response = $this->sendCheck($user, $this->upload($bytes ?? $this->jpegBytes()), $fields)->assertStatus(201);

        return NarcoticsCheck::query()->where('uuid', $response->json('data.id'))->firstOrFail();
    }

    protected function staffPost(User $user, string $uri, array $payload = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user);

        return $this->postJson($uri, $payload);
    }

    protected function approve(User $user, NarcoticsCheck $check): \Illuminate\Testing\TestResponse
    {
        return $this->staffPost($user, "/api/narcotics-checks/staff/checks/{$check->uuid}/approve");
    }

    protected function reject(User $user, NarcoticsCheck $check, ?string $explanation = null): \Illuminate\Testing\TestResponse
    {
        return $this->staffPost($user, "/api/narcotics-checks/staff/checks/{$check->uuid}/reject", $explanation === null ? [] : ['explanation' => $explanation]);
    }

    /** How many times a user was sent a notification of this kind (optionally for one ledger event key). */
    protected function sentTimes(User $user, string $kind, ?string $eventKey = null): int
    {
        return Notification::sent($user, InspectionEventNotification::class)
            ->filter(fn (InspectionEventNotification $n): bool => $n->kind === $kind && ($eventKey === null || $n->eventKey === $eventKey))
            ->count();
    }

    /** @return list<string> every file on the private disk */
    protected function storedFiles(): array
    {
        return Storage::disk(NarcoticsImageStore::disk())->allFiles('narcotics-checks');
    }
}
