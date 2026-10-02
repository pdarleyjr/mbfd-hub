<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class ViewerErrorController
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'document' => 'nullable|integer|min:1', 'revision' => 'nullable|uuid', 'page' => 'nullable|integer|min:1|max:10000',
            'route' => 'nullable|string|max:512', 'error' => 'required|string|max:100',
        ]);
        $allowed = ['Error', 'TypeError', 'RangeError', 'AbortError', 'DOMException', 'InvalidStateError', 'NetworkError', 'SecurityError', 'InvalidPDFException', 'MissingPDFException', 'UnexpectedResponseException', 'UnknownErrorException', 'RenderingCancelledException', 'AbortException', 'FormatError', 'PasswordException'];
        $safeError = in_array($data['error'], $allowed, true) ? $data['error'] : 'ViewerError';
        Log::warning('policy_library_viewer_error', [
            'user_id' => $request->user('web')->getAuthIdentifier(), 'node_id' => $data['document'] ?? null,
            'revision' => $data['revision'] ?? null, 'page' => $data['page'] ?? null, 'error' => $safeError,
            'route' => ($data['route'] ?? null) === '/' ? '/' : null,
        ]);

        return response()->noContent();
    }
}
