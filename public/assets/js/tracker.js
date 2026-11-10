(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var siteKey = script.getAttribute('data-site-key') || '';
    var endpoint = script.getAttribute('data-endpoint') || new URL('/track', script.src).toString();

    if (!/^[a-f0-9]{64}$/i.test(siteKey)) return;

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    var payload = {
        site_key: siteKey,
        event_id: uuid(),
        page_url: window.location.href.slice(0, 2048),
        referrer: document.referrer.slice(0, 2048)
    };

    if (navigator.sendBeacon) {
        var body = new Blob([JSON.stringify(payload)], { type: 'application/json' });
        navigator.sendBeacon(endpoint, body);
    } else {
        fetch(endpoint, {
            method: 'POST',
            mode: 'cors',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(function () {});
    }
}());
