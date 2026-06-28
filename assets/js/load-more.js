/* global VYG, wp */
/**
 * Vector YouTube Gallery — load-more handler.
 *
 * Listens for click on .vyg-feed__loadmore buttons; fetches the next page
 * from /wp-json/vyg/v1/feed and appends the rendered HTML to the feed wrapper.
 * Updates the button's offset + remaining count on each successful page.
 */
(function () {
    'use strict';

    if (!window.VYG) {
        return;
    }

    function htmlToElements(html) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        return Array.prototype.slice.call(tmp.children);
    }

    function findFeed(btn) {
        var node = btn.parentElement;
        while (node) {
            if (node.classList && node.classList.contains('vyg-feed')) {
                return node;
            }
            node = node.parentElement;
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.vyg-feed__loadmore');
        if (!btn) {
            return;
        }
        e.preventDefault();
        if (btn.disabled) {
            return;
        }
        btn.disabled = true;
        var originalText = btn.textContent;
        btn.textContent = '…';

        var feed = findFeed(btn);
        if (!feed) {
            btn.disabled = false;
            btn.textContent = originalText;
            return;
        }

        var url = window.VYG.restUrl
            + '?source_uuid=' + encodeURIComponent(btn.getAttribute('data-source-uuid'))
            + '&offset='    + encodeURIComponent(btn.getAttribute('data-offset'))
            + '&layout='    + encodeURIComponent(btn.getAttribute('data-layout'));

        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': window.VYG.restNonce,
                'Accept': 'application/json',
            },
        })
        .then(function (resp) {
            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }
            return resp.json();
        })
        .then(function (data) {
            if (!data || !data.html) {
                throw new Error('Empty response');
            }
            var elements = htmlToElements(data.html);
            // Insert before the button wrap.
            var wrap = btn.parentElement;
            elements.forEach(function (el) {
                feed.appendChild(el);
            });
            // Update or remove the button.
            if (data.has_more) {
                btn.setAttribute('data-offset', data.next_offset);
                btn.disabled = false;
                btn.textContent = originalText;
                // Update remaining count.
                var remainingEl = wrap.querySelector('.vyg-feed__remaining');
                if (remainingEl) {
                    remainingEl.textContent = ' (' + data.remaining + ')';
                }
            } else {
                wrap.parentNode.removeChild(wrap);
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.textContent = originalText;
            window.console && window.console.error('Vector YouTube Gallery: load-more failed', err);
        });
    });
})();