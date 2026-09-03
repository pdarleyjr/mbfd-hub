@php
    $candidate = request()->query('return_to');
    $returnTarget = is_string($candidate) && preg_match('#^/daily/stations/[0-9]+$#', $candidate) ? $candidate : null;
    $destination = $returnTarget ?? '/';
@endphp
<div class="employee-global-back">
    <a
        href="{{ $destination }}"
        aria-label="{{ $returnTarget ? 'Back to Station' : 'MBFD Hub Home' }}"
    >
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L5.56 9.25h10.69A.75.75 0 0 1 17 10Z" clip-rule="evenodd"/></svg>
        <span>{{ $returnTarget ? 'Back to Station' : 'MBFD Hub Home' }}</span>
    </a>
    <style>.employee-global-back{margin-bottom:.75rem}.employee-global-back a{display:inline-flex;min-height:3rem;align-items:center;gap:.5rem;padding:.55rem .9rem;border:1px solid #d6d3d1;border-radius:.75rem;background:#fff;color:#1e3a5f;font-size:.875rem;font-weight:800;text-decoration:none;box-shadow:0 1px 2px rgb(15 23 42/.04)}.employee-global-back a:hover{border-color:#93c5fd;background:#eff6ff}.employee-global-back a:focus-visible{outline:3px solid #2563eb;outline-offset:2px}.employee-global-back svg{width:1.1rem;height:1.1rem}</style>
</div>
