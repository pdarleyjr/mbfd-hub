{{--
    Compact, dismissible "Install MBFD Hub" card. Inline in the page flow (never a modal or fixed overlay).
    Hidden until resources/js/pwa/install-ui.ts decides the visitor is eligible: not installed, not snoozed,
    and the browser can install. "Install" appears only when the browser offers a native prompt; otherwise
    "Show me how" opens the /install guide.
--}}
<section data-mbfd-install-card hidden aria-labelledby="mbfd-install-card-title" {{ $attributes->merge(['class' => 'hub-install-card']) }}>
    <img class="hub-install-card__icon" src="/images/mbfd_app_icon_96.png" width="48" height="48" alt="">
    <div class="hub-install-card__body">
        <h2 id="mbfd-install-card-title" class="hub-install-card__title">Install MBFD Hub</h2>
        <p class="hub-install-card__text">Open MBFD Hub directly from your Home Screen or desktop for faster access to inspections, forms, notifications, and department tools.</p>
        <div class="hub-install-card__actions">
            <button type="button" class="hub-install-card__btn hub-install-card__btn--primary" data-install-action="install" hidden>Install</button>
            <a class="hub-install-card__btn hub-install-card__btn--primary" href="{{ url('/install') }}" data-install-action="how">Show me how</a>
            <button type="button" class="hub-install-card__btn" data-install-action="dismiss">Not now</button>
        </div>
    </div>
</section>
