<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use ZipArchive;

/** Admin users and odd-shaped files for the library's upload, replace and version tests. */
trait BuildsResourceLibraryAdmins
{
    /** @param  list<string>  $permissions */
    protected function adminWith(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->givePermissionTo(array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'), $permissions));

        return $user;
    }

    protected function manager(): User
    {
        return $this->adminWith(['admin.access', 'admin.resources.view', 'admin.resources.manage']);
    }

    protected function viewer(): User
    {
        return $this->adminWith(['admin.access', 'admin.resources.view']);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $user;
    }

    /**
     * A zip with exactly the given parts.
     *
     * @param  list<string>  $parts  entry names, e.g. ['[Content_Types].xml', 'word/document.xml']
     */
    protected function makeZip(string $name, array $parts): string
    {
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($parts as $part) {
            $zip->addFromString($part, '<x/>');
        }
        $zip->close();

        return $path;
    }

    /** A file that starts like a PDF and is one byte over the library limit. */
    protected function makeOversizePdf(string $name): string
    {
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.$name;
        $handle = fopen($path, 'wb');
        fwrite($handle, '%PDF-1.4');
        ftruncate($handle, ResourceLibraryService::MAX_BYTES + 1);
        fclose($handle);

        return $path;
    }
}
