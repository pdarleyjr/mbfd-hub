{{--
    Home visit card: the past-24-hours and all-time counters, and (admins only) a link that opens the list of members
    who signed in over the past day. resources/js/home-activity.js refreshes the numbers and fills the list; this
    markup is complete without it. $signedIn is null for members who may not see the list, and then no link is drawn.
--}}
@props(['summary', 'signedIn' => null])

<section
    data-home-activity
    data-summary-url="{{ route('home.activity.summary', absolute: false) }}"
    @if($signedIn !== null) data-members-url="{{ route('home.activity.members', absolute: false) }}" @endif
    aria-label="Home page visits"
    class="hub-panel home-activity"
>
    <div class="home-activity__body">
        {{-- Refreshed every minute while the page is open, so it is not announced each time. --}}
        <dl class="home-activity__stats" aria-live="off">
            <div class="home-activity__stat">
                <dt>Past 24 hours</dt>
                <dd data-activity-past>{{ number_format($summary['past_24_hours']) }}</dd>
            </div>
            <div class="home-activity__stat">
                <dt>Total</dt>
                <dd data-activity-total>{{ number_format($summary['total']) }}</dd>
            </div>
        </dl>
        <p class="home-activity__caption" data-activity-caption>Home page visits{{ $summary['since'] !== null ? ' since '.$summary['since'] : '' }}</p>
    </div>

    @if($signedIn !== null)
        <div class="hub-panel__footer">
            <button type="button" class="hub-link hub-link--standalone home-activity__link" data-activity-open aria-haspopup="dialog">
                <span data-activity-link-label>{{ number_format($signedIn) }} {{ \Illuminate\Support\Str::plural('member', $signedIn) }} signed in</span>
            </button>
        </div>

        <dialog class="home-activity-dialog" data-activity-dialog aria-labelledby="home-activity-dialog-title">
            <div class="home-activity-dialog__header">
                <h2 id="home-activity-dialog-title" class="home-activity-dialog__title">Signed in, past 24 hours</h2>
                <button type="button" class="hub-btn hub-btn--tertiary" data-activity-close>Close</button>
            </div>
            <div class="home-activity-dialog__body" aria-live="polite">
                <div class="home-activity-dialog__loading" data-activity-loading>
                    <span class="hub-skeleton"></span>
                    <span class="hub-skeleton"></span>
                    <span class="hub-skeleton"></span>
                    <span class="hub-visually-hidden">Loading</span>
                </div>
                <div class="hub-empty" data-activity-empty hidden>
                    <p class="hub-empty__body">No one has signed in during the past 24 hours.</p>
                </div>
                <div class="hub-empty" data-activity-error hidden>
                    <p class="hub-empty__body">Could not load the list. Try again.</p>
                    <button type="button" class="hub-btn hub-btn--secondary" data-activity-retry>Retry</button>
                </div>
                <ul class="home-activity-dialog__list" data-activity-list aria-label="Members who signed in" hidden></ul>
            </div>
        </dialog>
    @endif
</section>
