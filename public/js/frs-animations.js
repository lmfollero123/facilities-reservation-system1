/**
 * GSAP animation helpers with CSS fallbacks.
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function canAnimate() {
        return !prefersReducedMotion() && typeof window.gsap !== 'undefined';
    }

    function cssFadeOut(el, durationMs) {
        return new Promise(function (resolve) {
            if (!el) {
                resolve();
                return;
            }
            el.classList.add('dashboard-nav-animating', 'dashboard-fade-out');
            var done = false;
            function finish() {
                if (done) return;
                done = true;
                el.removeEventListener('transitionend', onEnd);
                el.classList.remove('dashboard-nav-animating', 'dashboard-fade-out');
                resolve();
            }
            function onEnd(e) {
                if (e.target === el && e.propertyName === 'opacity') {
                    finish();
                }
            }
            el.addEventListener('transitionend', onEnd);
            window.setTimeout(finish, durationMs + 80);
        });
    }

    function cssFadeIn(el, durationMs) {
        return new Promise(function (resolve) {
            if (!el) {
                resolve();
                return;
            }
            el.classList.add('dashboard-fade-in');
            void el.offsetWidth;
            el.classList.add('dashboard-nav-animating');
            var done = false;
            function finish() {
                if (done) return;
                done = true;
                el.removeEventListener('transitionend', onEnd);
                el.classList.remove('dashboard-nav-animating', 'dashboard-fade-in');
                el.style.opacity = '';
                resolve();
            }
            function onEnd(e) {
                if (e.target === el && e.propertyName === 'opacity') {
                    finish();
                }
            }
            el.addEventListener('transitionend', onEnd);
            window.setTimeout(finish, durationMs + 80);
        });
    }

    window.frsAnim = {
        canAnimate: canAnimate,

        pageOut: function (el, durationMs) {
            durationMs = durationMs || 0.28;
            if (!el || prefersReducedMotion()) {
                return Promise.resolve();
            }
            if (canAnimate()) {
                return window.gsap.to(el, {
                    opacity: 0,
                    y: 8,
                    duration: durationMs,
                    ease: 'power2.out',
                }).then(function () {
                    window.gsap.set(el, { clearProps: 'opacity,y' });
                });
            }
            return cssFadeOut(el, durationMs * 1000);
        },

        pageIn: function (el, durationMs) {
            durationMs = durationMs || 0.32;
            if (!el || prefersReducedMotion()) {
                return Promise.resolve();
            }
            if (canAnimate()) {
                window.gsap.set(el, { opacity: 0, y: 10 });
                return window.gsap.to(el, {
                    opacity: 1,
                    y: 0,
                    duration: durationMs,
                    ease: 'power2.out',
                });
            }
            return cssFadeIn(el, durationMs * 1000);
        },

        staggerCards: function (container) {
            if (!container || prefersReducedMotion()) {
                return;
            }
            var cards = container.querySelectorAll(
                '.booking-card, .ss-stat-card, .page-header, .dashboard-card'
            );
            if (!cards.length) {
                return;
            }
            if (canAnimate()) {
                window.gsap.from(cards, {
                    opacity: 0,
                    y: 12,
                    duration: 0.35,
                    stagger: 0.04,
                    ease: 'power2.out',
                    clearProps: 'opacity,y',
                });
            }
        },

        toastIn: function (el) {
            if (!el || prefersReducedMotion()) {
                if (el) {
                    el.classList.add('is-visible');
                }
                return Promise.resolve();
            }
            if (canAnimate()) {
                window.gsap.set(el, { opacity: 0, x: 24 });
                el.classList.add('is-visible');
                return window.gsap.to(el, {
                    opacity: 1,
                    x: 0,
                    duration: 0.4,
                    ease: 'power2.out',
                });
            }
            el.classList.add('is-visible', 'frs-toast-css-in');
            return Promise.resolve();
        },

        toastOut: function (el) {
            if (!el) {
                return Promise.resolve();
            }
            if (prefersReducedMotion()) {
                el.classList.remove('is-visible');
                return Promise.resolve();
            }
            if (canAnimate()) {
                return window.gsap.to(el, {
                    opacity: 0,
                    x: 24,
                    duration: 0.28,
                    ease: 'power2.in',
                    onComplete: function () {
                        el.classList.remove('is-visible');
                        window.gsap.set(el, { clearProps: 'opacity,x' });
                    },
                });
            }
            el.classList.remove('is-visible');
            return Promise.resolve();
        },
    };
})();
