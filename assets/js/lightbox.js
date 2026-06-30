/* global VYG */
/**
 * Vector YouTube Gallery — lightbox.
 *
 * Listens for clicks on any anchor with data-vyg-lightbox; opens an overlay
 * with the YouTube embed iframe. Closes on background click, close-button
 * click, or Escape key. Stops the video on close by clearing the iframe src.
 *
 * Phase 9.7 perf pass:
 *   - The iframe is lazily constructed on click (never in initial page load).
 *   - When constructed, we attach `loading="lazy"` which allows the browser
 *     to defer iframe work until the overlay is actually visible/painted.
 *   - autoplay is requested in the URL only when overlay opens; the URL
 *     itself comes from data-vyg-lightbox (already URL-encoded by PHP).
 */
(function () {
    'use strict';

    var overlay = null;
    var frame = null;
    var closeBtn = null;
    var previouslyFocused = null;

    function ensureOverlay() {
        if (overlay) {
            return;
        }
        overlay = document.createElement('div');
        overlay.className = 'vyg-lightbox';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Video player');
        overlay.addEventListener('click', function (e) {
            if (e && e.target === overlay) {
                close();
            }
        });

        frame = document.createElement('div');
        frame.className = 'vyg-lightbox__frame';

        closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'vyg-lightbox__close';
        closeBtn.setAttribute('aria-label', 'Close video');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', close);

        frame.appendChild(closeBtn);
        overlay.appendChild(frame);
        document.body.appendChild(overlay);
    }

    function open(url, title) {
        ensureOverlay();
        // Build iframe dynamically (don't autoplay until click).
        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('loading', 'lazy');
        if (title) {
            iframe.setAttribute('title', title);
        }
        // Clear previous iframe.
        while (frame.firstChild && frame.firstChild !== closeBtn) {
            frame.removeChild(frame.firstChild);
        }
        frame.insertBefore(iframe, closeBtn);
        // Phase 11.1: dispatch lightbox_open event so analytics.js can record it.
        // The card is found by walking up from the click target (we don't have
        // a direct ref here — the listener binds it via closest('.vyg-card')).
        var anchor = document.activeElement;
        var card   = anchor && anchor.closest && anchor.closest('.vyg-card');
        var videoId = card && card.getAttribute ? card.getAttribute('data-video-id') : '';
        var wrapper = card && card.closest ? card.closest('.vyg-feed') : null;
        try {
            document.dispatchEvent(new CustomEvent('vyg:lightbox-open', {
                detail: {
                    videoId:   videoId || '',
                    wrapperId: wrapper && wrapper.id ? wrapper.id : ''
                }
            }));
        } catch (e) { /* analytics is best-effort */ }
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        previouslyFocused = document.activeElement;
        // Move focus into the dialog so keyboard users land in a sensible spot.
        setTimeout(function () {
            if (closeBtn) {
                closeBtn.focus();
            }
        }, 0);
    }

    function close() {
        if (!overlay) {
            return;
        }
        overlay.classList.remove('is-open');
        // Stop playback by removing iframe (releasing the player).
        while (frame.firstChild && frame.firstChild !== closeBtn) {
            frame.removeChild(frame.firstChild);
        }
        document.body.style.overflow = '';
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
    }

    document.addEventListener('click', function (e) {
        var target = e.target;
        // Walk up to find a[data-vyg-lightbox].
        var anchor = null;
        while (target && target !== document) {
            if (target.tagName === 'A' && target.hasAttribute('data-vyg-lightbox')) {
                anchor = target;
                break;
            }
            target = target.parentNode;
        }
        if (!anchor) {
            return;
        }
        e.preventDefault();
        var url = anchor.getAttribute('data-vyg-lightbox');
        if (url) {
            var title = anchor.getAttribute('data-vyg-title') || '';
            open(url, title);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
            close();
        }
    });
})();
