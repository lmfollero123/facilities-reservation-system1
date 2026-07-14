/**
 * Dashboard navigation.
 *
 * Cross-page navigation uses a full browser load so each PHP page brings its
 * own CSS + scripts. Soft HTML swaps cannot reliably re-apply page styles or
 * re-bind onclick handlers (openFacilityModal, etc.).
 *
 * In-page filters / pagination / calendar still use frs-partial-update.js
 * (links with data-frs-partial are skipped here).
 */
(function () {
    'use strict';

    function initDashboardNav() {
        if (!document.body || !document.body.classList.contains('dashboard')) {
            return;
        }

        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let lastPath = window.location.pathname;
        let navigating = false;

        function basePath() {
            return (window.APP_BASE_PATH || '').replace(/\/$/, '');
        }

        function toAppPath(url) {
            try {
                const u = new URL(url, window.location.origin);
                let p = u.pathname;
                const base = basePath();
                if (base && p.startsWith(base)) {
                    p = p.slice(base.length) || '/';
                }
                p = p.replace(/\/$/, '') || '/';
                return p + (u.search || '') + (u.hash || '');
            } catch (e) {
                return '';
            }
        }

        function pathOnly(url) {
            return toAppPath(url).split('?')[0].split('#')[0];
        }

        function isDashboardUrl(url) {
            if (!url || url.startsWith('javascript:') || url.startsWith('mailto:') || url.startsWith('tel:')) {
                return false;
            }
            try {
                const u = new URL(url, window.location.origin);
                const path = u.pathname;
                const base = basePath();
                const appPath = base && path.startsWith(base) ? path.slice(base.length) : path;
                return appPath.startsWith('/dashboard') || appPath === '/dashboard';
            } catch (e) {
                return false;
            }
        }

        function shouldSkipLink(link) {
            if (!link || link.tagName !== 'A') {
                return true;
            }
            // In-page partial updates — handled by frs-partial-update.js
            if (
                link.hasAttribute('data-frs-partial')
                || link.hasAttribute('data-frs-partial-url')
                || link.classList.contains('frs-partial-link')
            ) {
                return true;
            }
            if (link.hasAttribute('data-no-transition') || link.classList.contains('no-transition')) {
                return true;
            }
            if (link.hasAttribute('download') || link.target === '_blank' || link.rel === 'external') {
                return true;
            }
            const href = link.getAttribute('href');
            if (!href || href.startsWith('#')) {
                return true;
            }
            return !isDashboardUrl(href);
        }

        function showProgress() {
            const bar = document.getElementById('dashboardNavProgress');
            if (!bar) {
                return;
            }
            bar.classList.remove('is-done');
            bar.classList.add('is-active');
            bar.setAttribute('aria-hidden', 'false');
        }

        function updateActiveNav(url) {
            const current = pathOnly(url);
            const currentSearch = (function () {
                try {
                    return new URL(url, window.location.origin).search;
                } catch (e) {
                    return '';
                }
            })();
            const normalizedHome = pathOnly((basePath() || '') + '/dashboard');
            const bookFacilityPath = pathOnly((basePath() || '') + '/dashboard/book-facility');

            const anchors = document.querySelectorAll(
                '.sidebar a[href], aside.sidebar a[href], .mobile-nav-link[href], a.sidebar-link[href]'
            );

            anchors.forEach(function (anchor) {
                const href = anchor.getAttribute('href');
                if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
                    return;
                }

                let linkPath = '';
                let linkSearch = '';
                try {
                    const linkUrl = new URL(href, window.location.origin);
                    linkPath = pathOnly(linkUrl.href);
                    linkSearch = linkUrl.search;
                } catch (e) {
                    return;
                }

                let active = false;

                if (linkPath === bookFacilityPath || linkPath === '/dashboard/book-facility') {
                    const wantMine = linkSearch.indexOf('module=mine') !== -1;
                    const haveMine = currentSearch.indexOf('module=mine') !== -1;
                    const onBook = current === bookFacilityPath || current === '/dashboard/book-facility';
                    active = onBook && (wantMine ? haveMine : !haveMine);
                } else if (linkPath === normalizedHome || linkPath === '/dashboard') {
                    active = current === normalizedHome || current === '/dashboard';
                } else if (linkPath === current) {
                    active = true;
                } else if (
                    linkPath.length > 1
                    && linkPath !== '/dashboard'
                    && linkPath !== normalizedHome
                    && current.startsWith(linkPath + '/')
                ) {
                    active = true;
                }

                anchor.classList.toggle('active', !!active);
            });
        }

        /**
         * Hard navigation between dashboard pages.
         * Soft HTML swaps cannot reliably apply each page's CSS/JS.
         */
        function navigate(url) {
            if (navigating) {
                return;
            }
            navigating = true;
            showProgress();
            window.location.href = url;
        }

        // Expose for rare programmatic use (same semantics: full load)
        window.frsDashboardNavigate = function (url) {
            navigate(url);
        };

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target.closest('a');
            if (shouldSkipLink(link)) {
                return;
            }

            const href = link.getAttribute('href');
            const resolved = new URL(href, window.location.href).href;
            const fromPath = pathOnly(window.location.href);
            const toPath = pathOnly(resolved);

            // Same URL (path + query): allow hash scroll only
            try {
                const fromUrl = new URL(window.location.href);
                const toUrl = new URL(resolved);
                if (fromPath === toPath && fromUrl.search === toUrl.search) {
                    if (toUrl.hash) {
                        const target = document.querySelector(toUrl.hash);
                        if (target) {
                            event.preventDefault();
                            target.scrollIntoView({
                                behavior: prefersReducedMotion ? 'auto' : 'smooth',
                            });
                        }
                    }
                    return;
                }
            } catch (e) {
                // fall through to hard nav
            }

            event.preventDefault();
            updateActiveNav(resolved);
            navigate(resolved);
        });

        // Initial active state (helpful after hard loads with odd URLs)
        updateActiveNav(window.location.href);

        if (history.state === null) {
            history.replaceState({ frsDashboardNav: true, path: lastPath }, '', window.location.href);
        }

        const initialMain = document.querySelector('.dashboard-content');
        if (initialMain && window.frsAnim && typeof window.frsAnim.staggerCards === 'function') {
            window.frsAnim.staggerCards(initialMain);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboardNav);
    } else {
        initDashboardNav();
    }
})();
