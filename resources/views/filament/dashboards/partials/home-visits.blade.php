{{--
    Admin Overview: Home page visit counts and who signed in, collapsed beside the title. Nothing is read until it is
    opened (resources/js/home-activity.js), so the dashboard pays nothing for it. The panel floats over the page, so the
    fit-to-window layout does not move. wire:ignore keeps it open if the page re-renders underneath.
--}}
<details
    class="mbfd-visits"
    wire:ignore
    data-home-activity
    data-summary-url="{{ route('home.activity.summary', absolute: false) }}"
    data-members-url="{{ route('home.activity.members', absolute: false) }}"
>
    <summary class="mbfd-visits__toggle">
        <x-heroicon-o-chart-bar class="mbfd-visits__icon" />
        Home page visits
        <x-heroicon-m-chevron-down class="mbfd-visits__chevron" />
    </summary>

    <div class="mbfd-visits__panel">
        <dl class="mbfd-visits__stats">
            <div>
                <dt>Past 24 hours</dt>
                <dd data-activity-past>&ndash;</dd>
            </div>
            <div>
                <dt>Total</dt>
                <dd data-activity-total>&ndash;</dd>
            </div>
        </dl>
        <p class="mbfd-visits__caption" data-activity-caption>Loading</p>
        <button type="button" class="mbfd-visits__link" data-activity-open aria-haspopup="dialog">
            <span data-activity-link-label>Members signed in</span>
        </button>

        <dialog class="mbfd-visits-dialog" data-activity-dialog aria-labelledby="mbfd-visits-dialog-title">
            <div class="mbfd-visits-dialog__header">
                <h2 id="mbfd-visits-dialog-title" class="mbfd-visits-dialog__title">Signed in, past 24 hours</h2>
                <button type="button" class="mbfd-visits__link" data-activity-close>Close</button>
            </div>
            <div class="mbfd-visits-dialog__body" aria-live="polite">
                <p class="mbfd-visits__caption" data-activity-loading>Loading</p>
                <p class="mbfd-visits__caption" data-activity-empty hidden>No one has signed in during the past 24 hours.</p>
                <div data-activity-error hidden>
                    <p class="mbfd-visits__caption">Could not load the list.</p>
                    <button type="button" class="mbfd-visits__link" data-activity-retry>Try again</button>
                </div>
                <ul class="mbfd-visits-dialog__list" data-activity-list aria-label="Members who signed in" hidden></ul>
            </div>
        </dialog>
    </div>
</details>
