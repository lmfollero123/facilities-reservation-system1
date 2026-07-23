/**
 * In-page partial updates (filters, pagination, calendar) without full reload.
 */
(function () {
    'use strict';

    let busy = false;

    function basePath() {
        return (window.APP_BASE_PATH || '').replace(/\/$/, '');
    }

    function resolveTarget(partialId) {
        if (!partialId) return null;
        const byId = document.querySelector('[data-frs-partial-id="' + partialId + '"]');
        if (byId) return byId;
        if (partialId.charAt(0) === '#') {
            return document.querySelector(partialId);
        }
        return document.getElementById(partialId);
    }

    function buildUrlFromForm(form) {
        const action = form.getAttribute('action') || window.location.pathname;
        const url = new URL(action, window.location.origin);
        const params = new URLSearchParams(new FormData(form));
        params.forEach(function (value, key) {
            url.searchParams.set(key, value);
        });
        return url.toString();
    }

    function extractPartial(html, partialId) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const found = doc.querySelector('[data-frs-partial-id="' + partialId + '"]');
        if (found) {
            return found.innerHTML;
        }
        return null;
    }

    function executeInlineScripts(container) {
        if (!container) return;
        const scripts = Array.from(container.querySelectorAll('script'));
        scripts.forEach(function (oldScript) {
            const type = (oldScript.getAttribute('type') || '').trim().toLowerCase();
            if (type && type !== 'text/javascript' && type !== 'application/javascript' && type !== 'module') {
                return;
            }
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function (attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                newScript.src = oldScript.src;
                newScript.async = false;
            } else {
                newScript.textContent = oldScript.textContent;
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function setLoading(target, on) {
        if (!target) return;
        target.classList.toggle('frs-partial-loading', on);
        target.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function tryOpenMineDayModal() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('open_day_modal') !== '1' || !params.get('selected_date')) {
            return;
        }
        const modal = document.getElementById('dayReservationsModal');
        if (modal) {
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }

    function mountMineDayModal() {
        const partialRoot = document.querySelector('[data-frs-partial-id="mine-calendar"]');
        const modal = partialRoot
            ? partialRoot.querySelector('#dayReservationsModal') || document.getElementById('dayReservationsModal')
            : document.getElementById('dayReservationsModal');
        if (!modal) return;
        document.querySelectorAll('#dayReservationsModal').forEach(function (el) {
            if (el !== modal) el.remove();
        });
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
    }

    function isNestedMineModalOpen() {
        return ['cancelReservationModal', 'editDetailsModal', 'rescheduleModal'].some(function (id) {
            const el = document.getElementById(id);
            return el && el.style.display === 'flex';
        });
    }

    function closeMineDayModal() {
        const modal = document.getElementById('dayReservationsModal');
        if (!modal || modal.style.display !== 'flex') return;
        if (isNestedMineModalOpen()) return;
        modal.style.display = 'none';
        document.body.style.overflow = '';
        const url = new URL(window.location.href);
        url.searchParams.delete('selected_date');
        url.searchParams.delete('open_day_modal');
        window.history.replaceState(window.history.state, '', url);
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('#closeDayReservationsModal')) {
            closeMineDayModal();
            return;
        }
        const dayModal = document.getElementById('dayReservationsModal');
        if (dayModal && e.target === dayModal && !isNestedMineModalOpen()) {
            closeMineDayModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const dayModal = document.getElementById('dayReservationsModal');
        if (!dayModal || dayModal.style.display !== 'flex') return;
        if (isNestedMineModalOpen()) return;
        closeMineDayModal();
    });

    function dispatchLoaded(partialId, target) {
        if (partialId === 'mine-calendar') {
            mountMineDayModal();
        }
        tryOpenMineDayModal();
        if (typeof window.frsOnPartialLoaded === 'function') {
            window.frsOnPartialLoaded(partialId, target);
        }
        document.dispatchEvent(new CustomEvent('frs:partial-loaded', {
            bubbles: true,
            detail: { id: partialId, target: target },
        }));
    }

    async function loadPartial(url, partialId, options) {
        const opts = options || {};
        const target = resolveTarget(partialId);
        if (!target || busy) {
            return false;
        }

        busy = true;
        setLoading(target, true);

        try {
            const resp = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/html',
                    'X-FRS-Partial': partialId,
                },
            });
            if (!resp.ok) {
                throw new Error('Partial load failed: ' + resp.status);
            }
            const html = await resp.text();
            const fragment = extractPartial(html, partialId);
            if (fragment === null) {
                if (opts.fallbackNavigate !== false) {
                    window.location.href = url;
                }
                return false;
            }
            target.innerHTML = fragment;
            if (opts.pushState !== false) {
                window.history.pushState({ frsPartial: partialId }, '', url);
            }
            executeInlineScripts(target);
            dispatchLoaded(partialId, target);
            return true;
        } catch (err) {
            console.error('frsPartialLoad', err);
            if (opts.fallbackNavigate !== false) {
                window.location.href = url;
            }
            return false;
        } finally {
            setLoading(target, false);
            busy = false;
        }
    }

    function partialIdFromEl(el) {
        return el.getAttribute('data-frs-partial') || el.closest('[data-frs-partial-root]')?.getAttribute('data-frs-partial-id') || '';
    }

    function shouldHandleLink(link, e) {
        if (!link || link.tagName !== 'A') return false;
        if (e.defaultPrevented) return false;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        if (link.hasAttribute('download') || link.target === '_blank') return false;
        if (link.hasAttribute('data-no-partial')) return false;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
        return link.hasAttribute('data-frs-partial') || link.classList.contains('frs-partial-link');
    }

    document.addEventListener('click', function (e) {
        const urlEl = e.target.closest('[data-frs-partial-url]');
        if (urlEl) {
            const partialId = partialIdFromEl(urlEl);
            const url = urlEl.getAttribute('data-frs-partial-url');
            if (partialId && url) {
                e.preventDefault();
                loadPartial(url, partialId);
            }
            return;
        }

        const link = e.target.closest('a[data-frs-partial], a.frs-partial-link');
        if (!shouldHandleLink(link, e)) return;
        const partialId = partialIdFromEl(link);
        if (!partialId) return;
        e.preventDefault();
        loadPartial(link.href, partialId);
    });

    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-frs-partial]');
        if (!form) return;
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'get') return;
        e.preventDefault();
        const partialId = form.getAttribute('data-frs-partial');
        if (!partialId) return;
        loadPartial(buildUrlFromForm(form), partialId);
    });

    // ---- AJAX POST forms (opt-in via data-frs-ajax) --------------------
    // Progressive enhancement: on ANY resolution failure we return without
    // preventDefault so the browser performs a normal full-page submit.

    function ajaxFormTargetId(form) {
        const explicit = (form.getAttribute('data-frs-ajax-target') || '').trim();
        if (explicit) return explicit;
        const region = form.closest('[data-frs-partial-id]');
        return region ? region.getAttribute('data-frs-partial-id') : '';
    }

    function setSubmitting(form, on) {
        form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach(function (btn) {
            btn.disabled = on;
            btn.setAttribute('aria-busy', on ? 'true' : 'false');
        });
    }

    function readToastHeader(resp) {
        const raw = resp.headers.get('X-FRS-Toast');
        if (!raw) return null;
        try {
            const parsed = JSON.parse(decodeURIComponent(raw));
            if (parsed && typeof parsed.message === 'string') {
                return { message: parsed.message, type: parsed.type === 'error' ? 'error' : 'success' };
            }
        } catch (err) {
            console.error('frsAjaxForm toast header', err);
        }
        return null;
    }

    function closeOnSuccess(form) {
        const selector = (form.getAttribute('data-frs-ajax-close') || '').trim();
        if (!selector) return;
        document.querySelectorAll(selector).forEach(function (el) {
            el.style.display = 'none';
        });
        document.body.style.overflow = '';
    }

    async function submitAjaxForm(form, submitter) {
        const targetId = ajaxFormTargetId(form);
        const target = resolveTarget(targetId);
        if (!target) return false; // defensive only: the submit listener verifies the region exists before preventDefault

        const body = new FormData(form);
        if (submitter && submitter.name) {
            body.append(submitter.name, submitter.value || '');
        }

        form.setAttribute('data-frs-ajax-busy', '1');
        busy = true;
        setSubmitting(form, true);
        setLoading(target, true);

        try {
            const resp = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                credentials: 'same-origin',
                body: body,
                headers: {
                    Accept: 'text/html',
                    'X-FRS-Partial': targetId,
                    'X-Requested-With': 'FRSAjaxForm',
                },
            });
            const html = await resp.text();
            const fragment = extractPartial(html, targetId);
            if (fragment === null) {
                // Login page, fatal error page, unexpected layout: never strand the user.
                window.location.href = resp.url || window.location.href;
                return true;
            }
            const toast = readToastHeader(resp);
            target.innerHTML = fragment;
            executeInlineScripts(target);
            dispatchLoaded(targetId, target);
            if (toast) {
                if (typeof window.frsShowToast === 'function') {
                    window.frsShowToast(toast.message, toast.type);
                }
                if (toast.type === 'success') {
                    closeOnSuccess(form);
                }
            }
            if (!toast || toast.type === 'error') {
                if (typeof window.frsFocusFirstInvalid === 'function') {
                    window.frsFocusFirstInvalid();
                }
            }
            if (target.getBoundingClientRect().top < 0) {
                target.scrollIntoView({ block: 'nearest' });
            }
            return true;
        } catch (err) {
            console.error('frsAjaxForm', err);
            if (typeof window.frsShowToast === 'function') {
                window.frsShowToast('Connection problem — your changes were not saved. Please try again.', 'error');
            }
            return true; // handled (do not native-resubmit a possibly-applied action)
        } finally {
            form.removeAttribute('data-frs-ajax-busy');
            busy = false;
            setSubmitting(form, false);
            setLoading(target, false);
        }
    }

    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form[data-frs-ajax]');
        if (!form) return;
        if (e.defaultPrevented) return; // a page-level validation listener blocked this submit
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;
        if (!window.fetch || !window.FormData || !window.DOMParser) return; // native submit
        if (form.hasAttribute('data-frs-ajax-busy')) {
            e.preventDefault(); // double-submit guard
            return;
        }
        const targetId = ajaxFormTargetId(form);
        if (!targetId || !resolveTarget(targetId)) {
            console.warn('frsAjaxForm: no swap region for form; submitting normally', form);
            return; // native submit
        }
        if (busy) { return; } // a GET partial load is in flight; native submit is the safe fallback
        e.preventDefault();
        submitAjaxForm(form, e.submitter || null);
    });

    document.addEventListener('change', function (e) {
        const select = e.target.closest('select');
        if (!select) return;
        const form = select.closest('form[data-frs-partial][data-frs-partial-auto]');
        if (!form) return;
        const partialId = form.getAttribute('data-frs-partial');
        if (!partialId) return;
        loadPartial(buildUrlFromForm(form), partialId);
    });

    window.addEventListener('popstate', function (e) {
        const partialId = (e.state && e.state.frsPartial)
            || document.querySelector('[data-frs-partial-id][data-frs-partial-root]')?.getAttribute('data-frs-partial-id');
        if (partialId) {
            loadPartial(window.location.href, partialId, { pushState: false });
        }
    });

    window.frsPartialLoad = loadPartial;

    document.addEventListener('DOMContentLoaded', function () {
        mountMineDayModal();
        tryOpenMineDayModal();
    });
})();
