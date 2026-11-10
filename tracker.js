(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) {
        var scripts = document.querySelectorAll('script[data-site-key]');
        script = scripts.length ? scripts[scripts.length - 1] : null;
    }
    if (!script) return;

    var siteKey = script.getAttribute('data-site-key') || '';
    if (!/^[a-f0-9]{64}$/i.test(siteKey)) return;

    var endpoint = new URL('track', script.src || window.location.href).toString();
    var eventId = (window.crypto && typeof window.crypto.randomUUID === 'function')
        ? window.crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });

    var payload = JSON.stringify({
        site_key: siteKey,
        event_id: eventId,
        page_url: window.location.href,
        referrer: document.referrer || ''
    });

    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([payload], { type: 'text/plain;charset=UTF-8' }));
        } else {
            fetch(endpoint, {
                method: 'POST',
                mode: 'no-cors',
                credentials: 'omit',
                keepalive: true,
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                body: payload
            }).catch(function () {});
        }
    } catch (error) {
        // Tracking must never interrupt the visitor's page.
    }
})();
