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
    const NAV_ORDER = ['/', '/facilities', '/announcements', '/faqs', '/contact'];
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

    function isAuthSplitPath(path) {
        const p = (path || '/').replace(/\/$/, '') || '/';
        const authPaths = [
            '/login', '/register', '/login-otp', '/login-setup-2fa',
            '/verify-email', '/forgot-password', '/reset-password',
        ];
        return authPaths.some(function (ap) {
            return p === ap || p.endsWith(ap);
        });
    }

    function syncPageChrome(doc, toPath) {
        let isAuthSplit = false;
        if (doc && doc.body) {
            isAuthSplit = doc.body.classList.contains('auth-split-page');
        }
        if (!isAuthSplit) {
            isAuthSplit = isAuthSplitPath(toPath);
        }
        document.body.classList.toggle('auth-split-page', isAuthSplit);
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

    function initAnnouncementsSort(root) {
        const container = root || document.querySelector(MAIN_SEL);
        if (!container) {
            return;
        }
        const select = container.querySelector('#sort-select');
        if (!select || select.dataset.frsSortBound === '1') {
            return;
        }
        select.dataset.frsSortBound = '1';
        select.removeAttribute('onchange');
        select.addEventListener('change', function () {
            const search = new URLSearchParams(window.location.search).get('search') || '';
            let url = basePath() + '/announcements?sort=' + encodeURIComponent(select.value);
            if (search) {
                url += '&search=' + encodeURIComponent(search);
            }
            navigate(url);
        });
    }

    function initContactForm(root) {
        const container = root || document.querySelector(MAIN_SEL);
        if (!container) {
            return;
        }
        const form = container.querySelector('#contact-inquiry-form');
        if (!form || form.dataset.frsContactBound === '1') {
            return;
        }
        form.dataset.frsContactBound = '1';

        const feedback = container.querySelector('#contact-form-feedback');
        const submitBtn = container.querySelector('#contact-submit-btn');
        const handlerUrl = basePath() + '/contact-handler';

        container.querySelectorAll('.cf-turnstile').forEach(function (el) {
            if (el.dataset.frsTurnstileRendered === '1' || !window.turnstile) {
                return;
            }
            el.dataset.frsTurnstileRendered = '1';
            try {
                window.turnstile.render(el);
            } catch (e) {
                /* widget may already be rendered */
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (feedback) {
                feedback.textContent = '';
                feedback.className = 'contact-form-feedback';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            const formData = new FormData(form);
            const turnstileInput = form.querySelector('[name="cf-turnstile-response"]');
            if (turnstileInput && turnstileInput.value) {
                formData.set('cf-turnstile-response', turnstileInput.value);
            }

            fetch(handlerUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: formData,
            })
                .then(function (r) {
                    return r.json().then(function (data) {
                        return { ok: r.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data && result.data.success) {
                        if (feedback) {
                            feedback.textContent = result.data.message || 'Thank you! Your inquiry has been sent.';
                            feedback.className = 'contact-form-feedback is-success';
                        }
                        form.reset();
                        if (window.turnstile) {
                            const widget = form.querySelector('.cf-turnstile');
                            if (widget) {
                                window.turnstile.reset(widget);
                            }
                        }
                    } else {
                        throw new Error(
                            (result.data && result.data.message)
                                ? result.data.message
                                : 'Unable to send your message.'
                        );
                    }
                })
                .catch(function (err) {
                    if (feedback) {
                        feedback.textContent = err.message || 'Unable to send your message. Please try again.';
                        feedback.className = 'contact-form-feedback is-error';
                    }
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                });
        });
    }

    function initFacilityCalendarClicks(root) {
        const container = root || document.querySelector(MAIN_SEL);
        if (!container) {
            return;
        }
        const days = container.querySelectorAll('.calendar .day');
        if (!days.length) {
            return;
        }
        const loginNext = encodeURIComponent(basePath() + '/dashboard/calendar');
        const loginUrl = basePath() + '/login?next=' + loginNext;
        days.forEach(function (day) {
            if (day.dataset.frsCalendarBound === '1') {
                return;
            }
            day.dataset.frsCalendarBound = '1';
            day.style.cursor = 'pointer';
            day.addEventListener('click', function () {
                window.location.href = loginUrl;
            });
        });
    }

    function reinitAfterSwap() {
        closeMobileNav();
        const main = document.querySelector(MAIN_SEL);
        if (typeof window.initMobileTables === 'function') {
            window.initMobileTables();
        }
        if (typeof window.frsInitHomeScrollAnimations === 'function') {
            window.frsInitHomeScrollAnimations(main);
        }
        initAnnouncementsSort(main);
        initFacilityCalendarClicks(main);
        initContactForm(main);
        initFacilityMap(main);
        document.dispatchEvent(new CustomEvent('frs:public-page-loaded', {
            bubbles: true,
            detail: { path: window.location.pathname },
        }));
    }

    function initFacilityMap(main) {
        const container = main || document.querySelector(MAIN_SEL);
        if (!container) return;
        
        const mapContainer = container.querySelector('#facility-map');
        if (!mapContainer) return;
        
        // Check if map already has data attributes for coordinates
        const lat = mapContainer.getAttribute('data-lat');
        const lng = mapContainer.getAttribute('data-lng');
        
        if (!lat || !lng) return;
        
        // Wait a bit for the container to be rendered
        setTimeout(function() {
            if (typeof L === 'undefined') {
                console.error('Leaflet is not loaded');
                mapContainer.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#64748b;">Map unavailable - Leaflet library not loaded</div>';
                return;
            }
            
            // Ensure container has dimensions
            if (mapContainer.offsetHeight === 0) {
                mapContainer.style.height = '300px';
                mapContainer.style.minHeight = '300px';
            }
            
            try {
                const map = L.map('facility-map', {
                    center: [parseFloat(lat), parseFloat(lng)],
                    zoom: 15,
                    zoomControl: true
                });
                
                // Add OpenStreetMap tiles
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                    maxZoom: 19
                }).addTo(map);
                
                // Add marker
                L.marker([parseFloat(lat), parseFloat(lng)], {
                    draggable: false
                }).addTo(map);
                
                // Invalidate size to fix rendering issues
                setTimeout(function() {
                    map.invalidateSize();
                }, 200);
            } catch (error) {
                console.error('Map initialization error:', error);
                mapContainer.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#64748b;">Map error: ' + error.message + '</div>';
            }
        }, 100);
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
            syncPageChrome(doc, toPath);

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

    const initialMain = document.querySelector(MAIN_SEL);
    initAnnouncementsSort(initialMain);
    initFacilityCalendarClicks(initialMain);
    initContactForm(initialMain);

    window.frsPublicNavigate = navigate;
})();
