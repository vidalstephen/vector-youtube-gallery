/* global VYG */
/**
 * Vector YouTube Gallery — lightbox.
 *
 * Listens for clicks on any anchor with data-vyg-lightbox; opens an overlay
 * with the YouTube embed iframe. Closes on background click, close-button
 * click, or Escape key. Stops the video on close by clearing the iframe src.
 */
(function () {
    'use strict';

    var overlay = null;
    var frame = null;
    var closeBtn = null;

    function ensureOverlay() {
        if (overlay) {
            return;
        }
        overlay = document.createElement('div');
        overlay.className = 'vyg-lightbox';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-label', 'Video player');
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
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

    function open(url) {
        ensureOverlay();
        // Build iframe dynamically (don't autoplay until click).
        var iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('frameborder', '0');
        // Clear previous iframe.
        while (frame.firstChild && frame.firstChild !== closeBtn) {
            frame.removeChild(frame.firstChild);
        }
        frame.insertBefore(iframe, closeBtn);
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        if (!overlay) {
            return;
        }
        overlay.classList.remove('is-open');
        // Stop playback by removing iframe.
        while (frame.firstChild && frame.firstChild !== closeBtn) {
            frame.removeChild(frame.firstChild);
        }
        document.body.style.overflow = '';
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
            open(url);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
            close();
        }
    });
})();