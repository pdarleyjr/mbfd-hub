@if(auth('web')->check() && ! request()->is('admin/set-password', 'employee/set-password', 'training/set-password', 'workgroups/set-password'))
<div class="hub-issue-widget" data-hub-issue-widget data-hub-issue-route-name="{{ request()->route()?->getName() }}">
    <a class="hub-issue-trigger" data-hub-issue-trigger href="{{ route('hub-support.create') }}">Report an Issue</a>
    <dialog class="hub-issue-dialog" data-hub-issue-dialog aria-labelledby="hub-issue-title">
        <h2 id="hub-issue-title">Report an Issue</h2>
        <form action="{{ route('hub-support.store') }}" method="post" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="client_submission_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label for="hub-issue-description">What went wrong?</label>
            <textarea id="hub-issue-description" name="description" required maxlength="10000" placeholder="Tell us what happened.&#10;&#10;Example: I tapped Submit but nothing happened."></textarea>
            <label class="hub-issue-file" data-hub-issue-files for="hub-issue-attachments">+ Add screenshot or file
                <input id="hub-issue-attachments" type="file" name="attachments[]" accept="image/png,image/jpeg,application/pdf" multiple>
            </label>
            <span data-hub-issue-selected aria-live="polite"></span>
            <p>We’ll automatically include the page you’re on and safe technical details that may help us find the problem.</p>
            <p class="hub-issue-error" data-hub-issue-error role="alert" hidden>We couldn’t send this yet. Your report is still here.</p>
            <div class="hub-issue-actions">
                <button class="hub-issue-secondary" type="button" data-hub-issue-close>Cancel</button>
                <button class="hub-issue-primary" type="submit" data-hub-issue-send>Send</button>
            </div>
        </form>
        <section data-hub-issue-confirmation hidden role="status" tabindex="-1">
            <p>Thanks — we got it.</p>
            <p>We included the page and technical information that may help us track down the problem.</p>
            <p>Reference: <span data-hub-issue-reference></span></p>
            <div class="hub-issue-actions">
                <button class="hub-issue-secondary" type="button" data-hub-issue-done>Done</button>
                <a class="hub-issue-primary" href="{{ route('hub-support.index') }}">View My Reports</a>
            </div>
        </section>
    </dialog>
</div>
@vite('resources/js/hub-support/blade.js')
@endif
