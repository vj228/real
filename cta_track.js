(function () {
    function safeVisitId() {
        var v = window.YHOME_MARKETING_VISIT_ID;
        if (typeof v !== 'number' || !Number.isFinite(v) || v <= 0) {
            return null;
        }

        return v;
    }

    function beacon(ctaId) {
        fetch('/track_cta_click.php', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                cta_id: ctaId,
                page_path: window.location.pathname || '/',
                visit_id: safeVisitId(),
                referrer: document.referrer ? String(document.referrer).slice(0, 4096) : ''
            }),
            keepalive: true,
            credentials: 'same-origin'
        }).catch(function () {});
    }

    document.addEventListener(
        'click',
        function (e) {
            var el = e.target && e.target.closest ? e.target.closest('[data-cta-id]') : null;
            if (!el) {
                return;
            }
            var id = el.getAttribute('data-cta-id');
            if (!id) {
                return;
            }

            beacon(String(id));
        },
        true
    );
})();
