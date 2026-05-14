{{--
    Install-prompt partial: the actual prompt is rendered dynamically by
    resources/js/admin-pwa/main.ts when:
        - the device matches `(min-width: 1280px) and (pointer: fine)`,
        - the user has not dismissed in the last 24h,
        - and the browser has fired `beforeinstallprompt`.

    This file exists only to keep the BODY_END render hook composition
    symmetric with the other partials and to provide a stable element the
    Playwright admin-pwa spec can assert against if needed.
--}}
<span data-admin-pwa-install-mount aria-hidden="true" style="position:absolute; width:0; height:0; overflow:hidden;"></span>
