/**
 * Vector YouTube Gallery — analytics capture (Phase 11.1).
 *
 * Fires `impression` / `play` / `lightbox_open` / `load_more_click`
 * events to /vyg/v1/events when analytics is enabled. When disabled
 * (the default — privacy by default), the JS still loads but is a
 * complete no-op: no fetch is issued, no DOM mutation, no extra
 * cookie writes.
 *
 * Detection: we read `window.VYG_ANALYTICS` (injected by the asset
 * manager on `wp_localize_script`). If the value is `0` or missing,
 * we early-return from every method.
 */
( function ( wp, settings ) {
    "use strict";
    if ( ! wp || ! settings || ! settings.enabled ) {
        return;
    }
    var rest   = settings.restRoot || '/wp-json/vyg/v1/';
    var nonce  = settings.nonce    || '';
    var url    = rest.replace(/\/$/, '') + '/events';

    /**
     * Tiny beacon helper. Failures swallowed silently — analytics
     * is best-effort and MUST NOT break the page.
     */
    function send(event) {
        try {
            if (navigator && navigator.sendBeacon) {
                var payload = new Blob([ JSON.stringify(event) ], { type: 'application/json' });
                if (navigator.sendBeacon(url, payload)) {
                    return;
                }
            }
        } catch (e) { /* fall through */ }
        try {
            if (window.fetch) {
                window.fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    body: JSON.stringify(event),
                    keepalive: true
                } ).catch(function () { /* swallow */ });
            }
        } catch (e) { /* swallow */ }
    }

    function getSessionId() {
        try {
            var c = '; ' + document.cookie;
            var parts = c.split('; vyg_sid=');
            if (parts.length === 2) {
                var v = parts.pop().split(';').shift();
                if (v) { return v; }
            }
        } catch (e) { /* swallow */ }
        // Mint a new ephemeral id (24h). No PII.
        try {
            var arr = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(arr);
            } else {
                for (var i = 0; i < 16; i++) { arr[i] = Math.floor(Math.random() * 256); }
            }
            var sid = '';
            for (var j = 0; j < arr.length; j++) {
                var h = arr[j].toString(16);
                if (h.length === 1) { h = '0' + h; }
                sid += h;
            }
            var expires = new Date(Date.now() + 86400000).toUTCString();
            document.cookie = 'vyg_sid=' + sid + '; expires=' + expires + '; path=/; SameSite=Lax';
            return sid;
        } catch (e) {
            return '';
        }
    }

    var sid = getSessionId();

    // 1. impression — fired via IntersectionObserver for every visible card.
    function attachImpressions(root) {
        if (typeof window.IntersectionObserver === 'undefined') {
            return;
        }
        var seen = new Set();
        var io = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (! entry.isIntersecting) {
                    return;
                }
                var card = entry.target;
                var vid  = card.getAttribute('data-video-id');
                if (! vid || seen.has(vid)) {
                    return;
                }
                seen.add(vid);
                var wrapper = card.closest('.vyg-feed');
                send({
                    event_type: 'impression',
                    youtube_video_id: vid,
                    wrapper_id: wrapper ? wrapper.id : '',
                    session_id: sid
                });
                io.unobserve(card);
            });
        }, { threshold: 0.5 });
        var cards = (root || document).querySelectorAll('.vyg-card[data-video-id]');
        cards.forEach(function (card) { io.observe(card); });
    }

    function onClick(event) {
        var card = event.target && event.target.closest && event.target.closest('.vyg-card');
        if (! card) {
            return;
        }
        var wrapper = card.closest('.vyg-feed');
        var videoId = card.getAttribute('data-video-id') || '';
        // Lightbox opens are detected via .vyg-lightbox iframe being added
        // by lightbox.js. We dispatch a custom event from there.
        var anchor = event.target.closest && event.target.closest('a.vyg-card__link');
        if (anchor) {
            // If the link is for the lightbox (data-vyg-lightbox), the lightbox
            // listener handles 'lightbox_open'; the click itself is a 'play'
            // candidate when no lightbox opens (e.g. operator disabled lightbox).
            // We always fire 'play' here so analytics captures the click intent.
            send({
                event_type: 'play',
                youtube_video_id: videoId,
                wrapper_id: wrapper ? wrapper.id : '',
                session_id: sid
            });
        }
    }

    function onLoadMore(event) {
        var btn = event.target && event.target.closest && event.target.closest('.vyg-feed__loadmore');
        if (! btn) {
            return;
        }
        var wrapper = btn.closest('.vyg-feed');
        send({
            event_type: 'load_more_click',
            wrapper_id: wrapper ? wrapper.id : '',
            session_id: sid
        });
    }

    function bindAll(root) {
        attachImpressions(root);
        // Use delegation so AJAX-loaded feeds are auto-bound without rebinding.
        (root || document).addEventListener('click', onClick);
        (root || document).addEventListener('click', onLoadMore);
    }

    // 2. lightbox_open — listen for a custom event from lightbox.js.
    document.addEventListener('vyg:lightbox-open', function (event) {
        var detail = (event && event.detail) || {};
        send({
            event_type: 'lightbox_open',
            youtube_video_id: detail.videoId || '',
            wrapper_id: detail.wrapperId || '',
            session_id: sid
        });
    });

    // 3. Initial bind.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bindAll(document); });
    } else {
        bindAll(document);
    }

    // 4. Re-bind on AJAX-loaded feeds.
    var mo = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var m = mutations[i];
            if (m.addedNodes && m.addedNodes.length) {
                for (var j = 0; j < m.addedNodes.length; j++) {
                    var n = m.addedNodes[j];
                    if (n.nodeType !== 1) { continue; }
                    if (n.classList && (n.classList.contains('vyg-feed') || n.querySelector && n.querySelector('.vyg-feed'))) {
                        bindAll(n);
                    }
                }
            }
        }
    });
    mo.observe(document.body, { childList: true, subtree: true });
} )( window.wp, window.VYG_ANALYTICS );