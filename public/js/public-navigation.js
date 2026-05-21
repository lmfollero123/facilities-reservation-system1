/**
 * Smooth in-page navigation for public (guest) pages.
 * Horizontal slide transitions (forward / back).
 */
(function () {
    'use strict';

    if (!document.body.classList.contains('landing-page')) {
        return;
    }

    const MAIN_SEL = '.guest-content';
    const AUTH_PATH_RE = /\/(login|register|logout|forgot-password|reset-password|verify-email|login-otp|dashboard)(\/|$)/i;
    const NAV_ORDER = ['/', '/facilities', '/announcements', '/faqs', '/faq', '/contact'];
    const SLIDE_MS = 340;
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let busy = false;
    let lastPath = pathOnly(window.location.href);

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

    function navIndex(path) {
        const p = path || '/';
        if (p === '/') {
            return 0;
        }
        for (let i = 1; i < NAV_ORDER.length; i++) {
            const segment = NAV_ORDER[i];
            if (p === segment || p.startsWith(segment + '/')) {
                return i;
            }
        }
        return -1;
    }

    function slideDirection(fromPath, toPath) {
        const fromIdx = navIndex(fromPath);
        const toIdx = navIndex(toPath);
        if (fromIdx >= 0 && toIdx >= 0 && fromIdx !== toIdx) {
            return toIdx > fromIdx ? 'forward' : 'back';
        }
        return 'forward';
    }

    function isAuthUrl(url) {
        return AUTH_PATH_RE.test(toAppPath(url));
    }

    function isPublicNavUrl(url) {
        if (!url || url.startsWith('javascript:') || url.startsWith('mailto:') || url.startsWith('tel:')) {
            return false;
        }
        if (isAuthUrl(url)) {
            return false;
        }
        try {
            const u = new URL(url, window.location.origin);
            return u.origin === window.location.origin;
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
        return !isPublicNavUrl(href);
    }

    function clearSlideClasses(main) {
        main.classList.remove(
            'public-nav-animating',
            'public-exit-left',
            'public-exit-right',
            'public-enter-from-right',
            'public-enter-from-left'
        );
        main.style.transform = '';
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
                if (event.target === el && (event.propertyName === 'transform' || event.propertyName === 'opacity')) {
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
        main.classList.remove('public-fade-in');
        main.querySelectorAll('.public-fade-in, .page-content-animate').forEach((node) => {
            node.classList.remove('public-fade-in', 'page-content-animate');
            node.style.animation = 'none';
        });
    }

    function showProgress() {
        const bar = document.getElementById('publicNavProgress');
        if (!bar) {
            return;
        }
        bar.classList.remove('is-done');
        bar.classList.add('is-active');
        bar.setAttribute('aria-hidden', 'false');
    }

    function hideProgress() {
        const bar = document.getElementById('publicNavProgress');
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

    function closeMobileNav() {
        document.getElementById('mobileNavSidebar')?.classList.remove('active');
        document.getElementById('mobileNavBackdrop')?.classList.remove('active');
        const toggle = document.getElementById('mobileNavToggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
        document.body.style.overflow = '';
    }

    function updateActiveNav(url) {
        const current = pathOnly(url);
        const normalizedHome = pathOnly((basePath() || '') + '/');

        document.querySelectorAll('#mainNav .nav-link, .mobile-nav-link').forEach((anchor) => {
            const href = anchor.getAttribute('href');
            if (!href) {
                return;
            }
            const linkPath = pathOnly(href);
            let active = linkPath === current;

            if (!active && (linkPath === normalizedHome || linkPath === '/')) {
                active = current === normalizedHome || current === '/';
            }

            if (!active && linkPath !== '/' && current.startsWith(linkPath) && linkPath.length > 1) {
                active = true;
            }

            anchor.classList.toggle('active', active);
        });
    }

    function reinitAfterSwap() {
        closeMobileNav();
        if (typeof window.initMobileTables === 'function') {
            window.initMobileTables();
        }
        if (typeof window.frsInitHomeScrollAnimations === 'function') {
            window.frsInitHomeScrollAnimations(document.querySelector(MAIN_SEL));
        }
        document.dispatchEvent(new CustomEvent('frs:public-page-loaded', {
            bubbles: true,
            detail: { path: window.location.pathname },
        }));
    }

    async function animateOut(main, direction) {
        if (prefersReducedMotion) {
            return;
        }
        clearSlideClasses(main);
        forceReflow(main);
        main.classList.add('public-nav-animating');
        main.classList.add(direction === 'back' ? 'public-exit-right' : 'public-exit-left');
        await waitForTransition(main, SLIDE_MS + 80);
        main.classList.remove('public-nav-animating', 'public-exit-left', 'public-exit-right');
    }

    async function animateIn(main, direction) {
        if (prefersReducedMotion) {
            return;
        }
        clearSlideClasses(main);
        main.classList.add(direction === 'back' ? 'public-enter-from-left' : 'public-enter-from-right');
        forceReflow(main);
        main.classList.add('public-nav-animating');
        main.classList.remove('public-enter-from-right', 'public-enter-from-left');
        await waitForTransition(main, SLIDE_MS + 80);
        clearSlideClasses(main);
    }

    async function navigate(url, pushState, forcedDirection) {
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

        if (fromPath === toPath) {
            if (url.includes('#')) {
                const hash = new URL(url, window.location.origin).hash;
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

        const direction = forcedDirection || slideDirection(fromPath, toPath);

        busy = true;
        showProgress();

        await animateOut(main, direction);

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'FRS-Public-Nav',
                },
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newMain = doc.querySelector(MAIN_SEL);

            if (!newMain) {
                throw new Error('Missing guest content');
            }

            const title = doc.querySelector('title');
            if (title && title.textContent) {
                document.title = title.textContent;
            }

            main.innerHTML = newMain.innerHTML;
            stripEntranceAnimations(main);
            clearSlideClasses(main);

            if (typeof window.frsInitHomeScrollAnimations === 'function') {
                window.frsInitHomeScrollAnimations(main);
            }

            if (pushState !== false) {
                history.pushState({ frsPublicNav: true, path: toPath }, '', url);
            }

            lastPath = toPath;
            updateActiveNav(url);
            window.scrollTo(0, 0);

            await animateIn(main, direction);
            reinitAfterSwap();
        } catch (err) {
            console.warn('Public nav fallback:', err);
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

        if (href.includes('#page-top') && pathOnly(resolved) === pathOnly(window.location.href)) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
            closeMobileNav();
            return;
        }

        event.preventDefault();
        const direction = slideDirection(pathOnly(window.location.href), pathOnly(resolved));
        navigate(resolved, true, direction);
    });

    window.addEventListener('popstate', function () {
        if (!document.body.classList.contains('landing-page')) {
            return;
        }
        const newPath = pathOnly(window.location.href);
        const direction = navIndex(newPath) < navIndex(lastPath) ? 'back' : 'forward';
        lastPath = newPath;
        navigate(window.location.href, false, direction);
    });

    if (history.state === null) {
        history.replaceState({ frsPublicNav: true, path: lastPath }, '', window.location.href);
    }

    window.frsPublicNavigate = navigate;
})();
