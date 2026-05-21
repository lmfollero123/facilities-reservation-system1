/**
 * Home page scroll-reveal animations (.home-animate*).
 * Runs on first load and after public AJAX navigation swaps content.
 */
(function () {
    'use strict';

    const SELECTOR = '.home-animate, .home-animate-left, .home-animate-right, .home-animate-scale';

    function isHomePath() {
        const base = (window.APP_BASE_PATH || '').replace(/\/$/, '');
        const path = window.location.pathname.replace(/\/$/, '') || '/';
        const homePath = (base + '/').replace(/\/$/, '') || '/';
        return path === '/' || path === homePath || path.endsWith('/index.php');
    }

    function initHomeScrollAnimations(root) {
        if (!isHomePath()) {
            return;
        }

        const container = root && root.querySelector ? root : document;
        const elements = container.querySelectorAll
            ? container.querySelectorAll(SELECTOR)
            : document.querySelectorAll(SELECTOR);

        if (!elements.length) {
            return;
        }

        const revealInView = () => {
            elements.forEach((el) => {
                const rect = el.getBoundingClientRect();
                if (rect.top < window.innerHeight * 0.92 && rect.bottom > 0) {
                    el.classList.add('visible');
                }
            });
        };

        revealInView();
        requestAnimationFrame(revealInView);

        if (window.__frsHomeObserver) {
            window.__frsHomeObserver.disconnect();
        }

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            },
            { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
        );

        elements.forEach((el) => observer.observe(el));
        window.__frsHomeObserver = observer;
    }

    window.frsInitHomeScrollAnimations = initHomeScrollAnimations;

    function onReady() {
        initHomeScrollAnimations(document.querySelector('.guest-content'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    document.addEventListener('frs:public-page-loaded', function (event) {
        const main = document.querySelector('.guest-content');
        if (main) {
            initHomeScrollAnimations(main);
        }
    });
})();
