/**
 * Smooth page navigation for dashboard pages.
 * Fade transitions with progress indicator.
 */
(function () {
    'use strict';

    // Wait for DOM to be ready before checking body class
    function initDashboardNav() {
        if (!document.body || !document.body.classList.contains('dashboard')) {
            return;
        }

        const MAIN_SEL = '.dashboard-content';
        const FADE_MS = 300;
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        let busy = false;
        let lastPath = window.location.pathname;

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

        function clearFadeClasses(main) {
            main.classList.remove(
                'dashboard-nav-animating',
                'dashboard-fade-out',
                'dashboard-fade-in'
            );
            main.style.opacity = '';
        }

        function waitForTransition(el, timeout) {
            return new Promise((resolve) => {
                let finished = false;
                const done = () => {
                    if (finished) {
                        return;
                    }
                    finished = true;
                    el.removeEventListener('transitionend', onEnd);
                    resolve();
                };
                const onEnd = (event) => {
                    if (event.target === el && event.propertyName === 'opacity') {
                        done();
                    }
                };
                el.addEventListener('transitionend', onEnd);
                setTimeout(done, timeout);
            });
        }

        function forceReflow(el) {
            void el.offsetWidth;
        }

        function stripEntranceAnimations(main) {
            main.classList.remove('dashboard-fade-in');
            main.querySelectorAll('.dashboard-fade-in, .page-content-animate').forEach((node) => {
                node.classList.remove('dashboard-fade-in', 'page-content-animate');
                node.style.animation = 'none';
            });
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

        function hideProgress() {
            const bar = document.getElementById('dashboardNavProgress');
            if (!bar) {
                return;
            }
            bar.classList.remove('is-active');
            bar.classList.add('is-done');
            setTimeout(() => {
                bar.classList.remove('is-done');
                bar.setAttribute('aria-hidden', 'true');
            }, 280);
        }

        function updateActiveNav(url) {
            const current = pathOnly(url);
            const normalizedHome = pathOnly((basePath() || '') + '/dashboard');

            document.querySelectorAll('.sidebar-link, .mobile-nav-link').forEach((anchor) => {
                const href = anchor.getAttribute('href');
                if (!href) {
                    return;
                }
                const linkPath = pathOnly(href);
                let active = linkPath === current;

                if (!active && (linkPath === normalizedHome || linkPath === '/dashboard')) {
                    active = current === normalizedHome || current === '/dashboard';
                }

                if (!active && linkPath !== '/dashboard' && current.startsWith(linkPath) && linkPath.length > 1) {
                    active = true;
                }

                anchor.classList.toggle('active', active);
            });
        }

        function reinitAfterSwap() {
            const main = document.querySelector(MAIN_SEL);
            if (typeof window.initMobileTables === 'function') {
                window.initMobileTables();
            }
            if (typeof window.frsInitReservationCharts === 'function') {
                window.frsInitReservationCharts(window.frsChartConfig || {});
            }
            document.dispatchEvent(new CustomEvent('frs:dashboard-page-loaded', {
                bubbles: true,
                detail: { path: window.location.pathname },
            }));
        }

        async function animateOut(main) {
            if (prefersReducedMotion) {
                return;
            }
            clearFadeClasses(main);
            forceReflow(main);
            main.classList.add('dashboard-nav-animating');
            main.classList.add('dashboard-fade-out');
            await waitForTransition(main, FADE_MS + 80);
            main.classList.remove('dashboard-nav-animating', 'dashboard-fade-out');
        }

        async function animateIn(main) {
            if (prefersReducedMotion) {
                return;
            }
            clearFadeClasses(main);
            main.classList.add('dashboard-fade-in');
            forceReflow(main);
            main.classList.add('dashboard-nav-animating');
            await waitForTransition(main, FADE_MS + 80);
            clearFadeClasses(main);
        }

        async function navigate(url, pushState) {
            if (busy) {
                return;
            }

            const main = document.querySelector(MAIN_SEL);
            if (!main) {
                window.location.href = url;
                return;
            }

            const fromPath = pathOnly(window.location.href);
            const toPath = pathOnly(url);
            const fromUrl = new URL(window.location.href);
            const toUrl = new URL(url, window.location.origin);

            if (fromPath === toPath && fromUrl.search === toUrl.search) {
                if (url.includes('#')) {
                    const hash = toUrl.hash;
                    const target = hash ? document.querySelector(hash) : null;
                    if (target) {
                        target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth' });
                    } else {
                        window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
                    }
                } else {
                    window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
                }
                return;
            }

            busy = true;
            showProgress();

            await animateOut(main);

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'FRS-Dashboard-Nav',
                    },
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const newMain = doc.querySelector(MAIN_SEL);

                if (!newMain) {
                    throw new Error('Missing dashboard content');
                }

                const title = doc.querySelector('title');
                if (title && title.textContent) {
                    document.title = title.textContent;
                }

                main.innerHTML = newMain.innerHTML;
                stripEntranceAnimations(main);
                clearFadeClasses(main);

                if (pushState !== false) {
                    history.pushState({ frsDashboardNav: true, path: toPath }, '', url);
                }

                lastPath = toPath;
                updateActiveNav(url);
                window.scrollTo(0, 0);

                await animateIn(main);
                reinitAfterSwap();
            } catch (err) {
                console.warn('Dashboard nav fallback:', err);
                window.location.href = url;
            } finally {
                hideProgress();
                busy = false;
            }
        }

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

            event.preventDefault();
            navigate(resolved, true);
        });

        window.addEventListener('popstate', function () {
            if (!document.body.classList.contains('dashboard')) {
                return;
            }
            const newPath = pathOnly(window.location.href);
            lastPath = newPath;
            navigate(window.location.href, false);
        });

        if (history.state === null) {
            history.replaceState({ frsDashboardNav: true, path: lastPath }, '', window.location.href);
        }

        window.frsDashboardNavigate = navigate;
    }

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboardNav);
    } else {
        initDashboardNav();
    }
})();
