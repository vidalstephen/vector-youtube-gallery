/* Vector YouTube Gallery — accessible carousel controller.
 *
 * Wires every .vyg-carousel element on the page:
 *   - Prev / Next buttons scroll the track by one slide.
 *   - ArrowLeft / ArrowRight move to prev / next; Home/End jump to ends.
 *   - Touch swipe (horizontal pointer drag) scrolls natively.
 *   - Live-region announces current slide to assistive tech.
 *   - prefers-reduced-motion: scroll-behavior auto (no smooth-jump).
 *
 * No jQuery, no external slider library. Vanilla JS only.
 *
 * Resilient to multiple carousels per page (each gets its own controller).
 */
(function () {
  'use strict';

  if (typeof window === 'undefined' || !('document' in window)) {
    return;
  }

  function initCarousel(root) {
    if (!root || root.dataset.vygCarouselReady === '1') {
      return;
    }
    root.dataset.vygCarouselReady = '1';

    var track = root.querySelector('.vyg-carousel__track');
    var prev = root.querySelector('.vyg-carousel__btn--prev');
    var next = root.querySelector('.vyg-carousel__btn--next');
    var live = root.querySelector('.vyg-carousel__live');
    var slides = root.querySelectorAll('.vyg-carousel__slide');
    if (!track || slides.length === 0) {
      return;
    }

    var total = slides.length;
    var current = 1; // 1-indexed for ARIA.

    function announce() {
      if (!live) {
        return;
      }
      var slide = slides[current - 1];
      var title = slide ? (slide.querySelector('.vyg-card__title') || {}).textContent : '';
      live.textContent = total > 0
        ? (title ? 'Slide ' + current + ' of ' + total + ': ' + title : 'Slide ' + current + ' of ' + total)
        : '';
    }

    function getStep() {
      // Get the width of the first slide + the column gap (1rem = 16px).
      var first = slides[0];
      if (!first) return 200;
      var width = first.getBoundingClientRect().width || 200;
      var gap = 16;
      return width + gap;
    }

    function scrollTo(targetIndex) {
      var step = getStep();
      var safeIndex = Math.max(1, Math.min(total, targetIndex));
      current = safeIndex;
      var x = (safeIndex - 1) * step;
      var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      try {
        track.scrollTo({
          left: x,
          behavior: reduce ? 'auto' : 'smooth'
        });
      } catch (e) {
        track.scrollLeft = x;
      }
      // Update aria-selected on slides.
      slides.forEach(function (slide, i) {
        var selected = (i + 1) === current;
        slide.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      updateButtons();
      announce();
    }

    function updateButtons() {
      if (prev) {
        prev.disabled = current <= 1;
      }
      if (next) {
        // Disable when on last slide. We approximate by per-view width; the
        // check against total + per-view is the canonical "no more slides".
        next.disabled = current >= total;
      }
    }

    if (prev) {
      prev.addEventListener('click', function (e) {
        e.preventDefault();
        scrollTo(current - 1);
      });
    }
    if (next) {
      next.addEventListener('click', function (e) {
        e.preventDefault();
        scrollTo(current + 1);
      });
    }

    // Keyboard navigation (only when the track has focus).
    track.addEventListener('keydown', function (e) {
      var key = e.key;
      if (key === 'ArrowLeft' || key === 'Left') {
        e.preventDefault();
        scrollTo(current - 1);
      } else if (key === 'ArrowRight' || key === 'Right') {
        e.preventDefault();
        scrollTo(current + 1);
      } else if (key === 'Home') {
        e.preventDefault();
        scrollTo(1);
      } else if (key === 'End') {
        e.preventDefault();
        scrollTo(total);
      }
    });

    // Touch swipe: track native horizontal scroll, sync the current slide to
    // the closest snapped slide on scroll-end.
    var scrollEndTimer = null;
    track.addEventListener('scroll', function () {
      if (scrollEndTimer) {
        clearTimeout(scrollEndTimer);
      }
      scrollEndTimer = setTimeout(function () {
        var step = getStep();
        var scrolled = track.scrollLeft || 0;
        var approx = Math.round(scrolled / step) + 1;
        if (approx !== current) {
          current = Math.max(1, Math.min(total, approx));
          updateButtons();
          announce();
        }
      }, 100);
    });

    updateButtons();
    announce();
  }

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var carousels = document.querySelectorAll('.vyg-carousel');
    carousels.forEach(initCarousel);

    // Watch for DOM insertions (e.g. AJAX load-more loads a new grid — but
    // carousel uses its own pagination model; keep this hook for parity).
    if (typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var nodes = mutations[i].addedNodes;
          for (var j = 0; j < nodes.length; j++) {
            var node = nodes[j];
            if (node.nodeType !== 1) continue;
            if (node.classList && node.classList.contains('vyg-carousel')) {
              initCarousel(node);
            } else if (node.querySelectorAll) {
              node.querySelectorAll('.vyg-carousel').forEach(initCarousel);
            }
          }
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  });
})();
