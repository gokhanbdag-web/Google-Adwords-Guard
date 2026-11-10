(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) return;

    var siteKey = script.getAttribute('data-site-key') || '';
    var endpoint = script.getAttribute('data-endpoint') || new URL('../../track', script.src).toString();
    if (!/^[a-f0-9]{64}$/i.test(siteKey)) return;

    function createUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' +
            hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    var payload = JSON.stringify({
        site_key: siteKey,
        event_id: createUuid(),
        page_url: window.location.href.slice(0, 2048),
        referrer: document.referrer.slice(0, 2048)
    });

    if (navigator.sendBeacon) {
        var queued = navigator.sendBeacon(endpoint, new Blob([payload], { type: 'text/plain;charset=UTF-8' }));
        if (queued) return;
    }

    fetch(endpoint, {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: payload,
        keepalive: true
    }).catch(function () {});
}());
