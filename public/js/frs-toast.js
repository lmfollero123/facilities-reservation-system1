/**
 * Dashboard toast notifications (login welcome, etc.).
 */
(function () {
    'use strict';

    var AUTO_DISMISS_MS = 5000;

    function getStack() {
        return document.getElementById('frsToastStack');
    }

    function dismissToast(toast) {
        if (!toast || toast.dataset.dismissing === '1') {
            return;
        }
        toast.dataset.dismissing = '1';
        var remove = function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        };
        if (window.frsAnim && typeof window.frsAnim.toastOut === 'function') {
            window.frsAnim.toastOut(toast).then(remove);
        } else {
            toast.classList.remove('is-visible');
            window.setTimeout(remove, 280);
        }
    }

    function showToast(message, type) {
        var stack = getStack();
        if (!stack || !message) {
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'frs-toast frs-toast-' + (type || 'success');
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');

        var icon = document.createElement('span');
        icon.className = 'frs-toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = type === 'error' ? '!' : '✓';

        var text = document.createElement('span');
        text.className = 'frs-toast-message';
        text.textContent = message;

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'frs-toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', function () {
            dismissToast(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(closeBtn);
        stack.appendChild(toast);

        if (window.frsAnim && typeof window.frsAnim.toastIn === 'function') {
            window.frsAnim.toastIn(toast);
        } else {
            toast.classList.add('is-visible', 'frs-toast-css-in');
        }

        window.setTimeout(function () {
            dismissToast(toast);
        }, AUTO_DISMISS_MS);
    }

    function initFromDom() {
        var stack = getStack();
        if (!stack) {
            return;
        }
        var preset = stack.querySelector('[data-frs-toast-message]');
        if (preset) {
            var msg = preset.getAttribute('data-frs-toast-message') || '';
            var type = preset.getAttribute('data-frs-toast-type') || 'success';
            preset.parentNode.removeChild(preset);
            if (msg) {
                showToast(msg, type);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFromDom);
    } else {
        initFromDom();
    }

    window.frsShowToast = showToast;
})();
