/**
 * Shared form validation helpers — focus first invalid field.
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function resolveFocusTarget(el, focusSelector) {
        if (focusSelector) {
            const explicit = document.querySelector(focusSelector);
            if (explicit) {
                return explicit;
            }
        }
        if (!el) {
            return null;
        }
        if (el.type === 'hidden') {
            const label = el.closest('label');
            if (label) {
                const display = label.querySelector('[role="status"], .bcf-res-date-readonly, .input-wrapper');
                if (display) {
                    return display;
                }
            }
        }
        if (el.id === 'start-time') {
            return document.getElementById('bcf-start-time-trigger') || el;
        }
        if (el.id === 'end-time') {
            return document.getElementById('bcf-end-time-trigger') || el;
        }
        if (el.classList && el.classList.contains('bcf-time-select-native')) {
            const slot = el.closest('.bcf-scroll-select-slot');
            if (slot) {
                return slot.querySelector('.bcf-scroll-select-trigger') || slot;
            }
        }
        const wrapper = el.closest('.input-wrapper, .bcf-scroll-select-slot, label');
        if (wrapper && wrapper !== el && (el.tagName === 'SELECT' || el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) {
            return wrapper;
        }
        return el;
    }

    function highlightField(el) {
        if (!el) {
            return;
        }
        el.classList.add('frs-field-error-highlight');
        window.setTimeout(function () {
            el.classList.remove('frs-field-error-highlight');
        }, 3500);
    }

    function focusField(el, focusSelector) {
        const target = resolveFocusTarget(el, focusSelector);
        if (!target) {
            return;
        }
        highlightField(target);
        if (typeof target.focus === 'function' && target.tagName !== 'DIV') {
            try {
                target.focus({ preventScroll: true });
            } catch (e) {
                target.focus();
            }
        }
        target.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'center',
        });
    }

    function showValidationMessage(message) {
        if (!message) {
            return;
        }
        if (typeof window.frsShowToast === 'function') {
            window.frsShowToast(message, 'error');
            return;
        }
        window.alert(message);
    }

    /**
     * @param {Array<{selector: string, focusSelector?: string, test?: function, message?: string, beforeFocus?: function, fallbackSelector?: string, focusDelay?: number}>} rules
     * @returns {{ok: boolean, field?: Element, message?: string}}
     */
    function frsFocusFirstInvalid(rules) {
        if (!Array.isArray(rules)) {
            return { ok: true };
        }

        for (let i = 0; i < rules.length; i++) {
            const rule = rules[i];
            const el = document.querySelector(rule.selector);
            let valid = true;

            try {
                if (typeof rule.test === 'function') {
                    valid = !!rule.test(el);
                } else if (el && typeof el.checkValidity === 'function') {
                    valid = el.checkValidity();
                } else {
                    valid = !!(el && String(el.value || '').trim());
                }
            } catch (e) {
                console.error('Validation error for rule:', rule.selector, e);
                valid = false;
            }

            if (!valid) {
                if (typeof rule.beforeFocus === 'function') {
                    rule.beforeFocus();
                }

                const focusSel = rule.focusSelector || rule.selector;
                const delay = rule.focusDelay != null
                    ? rule.focusDelay
                    : (rule.beforeFocus ? 240 : 0);

                window.setTimeout(function () {
                    focusField(el, focusSel);
                    if (!el && rule.fallbackSelector) {
                        focusField(document.querySelector(rule.fallbackSelector), rule.fallbackSelector);
                    }
                }, delay);

                showValidationMessage(rule.message || 'Please complete all required fields.');
                return { ok: false, field: el, message: rule.message || '' };
            }
        }

        return { ok: true };
    }

    function frsFocusBySelector(selector) {
        const el = document.querySelector(selector);
        if (el) {
            focusField(el);
        }
        return !!el;
    }

    function frsFocusByFieldKey(fieldKey, map) {
        if (!fieldKey || !map || !map[fieldKey]) {
            return false;
        }
        return frsFocusBySelector(map[fieldKey]);
    }

    window.frsFocusFirstInvalid = frsFocusFirstInvalid;
    window.frsFocusBySelector = frsFocusBySelector;
    window.frsFocusByFieldKey = frsFocusByFieldKey;
})();
