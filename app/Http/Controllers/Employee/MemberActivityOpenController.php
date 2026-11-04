<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Activity\MemberActivityFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens one "My activity" item: marks it seen for the signed-in member, then goes to the record.
 * The key is only a lookup. The item has to come out of this member's own records or the answer is 404,
 * so another member's key reveals nothing and changes nothing.
 */
final class MemberActivityOpenController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(Request $request, string $key, MemberActivityFeed $feed): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        $item = $feed->find($user, $this->authenticatedEmployee(), $key);
        abort_if($item === null, 404);

        $feed->markSeen($user, $item);

        return redirect()->to($item->url);
    }
}
