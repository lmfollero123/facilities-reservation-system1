/**
 * Shared confirm modal — replaces native window.confirm() so dialogs stay
 * theme-aware and consistent with the rest of the dashboard's modal styling.
 * Builds its own DOM node appended directly to <body>, so it's never at risk
 * of the "moved by JS, styled by an ancestor selector that no longer wraps
 * it" scoping bug (see the blackout-dates modal fix).
 */
(function () {
    'use strict';

    var modal, titleEl, msgEl, okBtn, cancelBtn, backdrop;

    function build() {
        if (modal) {
            return;
        }
        modal = document.createElement('div');
        modal.className = 'frs-confirm-modal hidden';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML =
            '<div class="frs-confirm-backdrop"></div>' +
            '<div class="frs-confirm-box">' +
                '<h3 class="frs-confirm-title"></h3>' +
                '<p class="frs-confirm-message"></p>' +
                '<div class="frs-confirm-actions">' +
                    '<button type="button" class="btn-outline frs-confirm-cancel">Cancel</button>' +
                    '<button type="button" class="btn-primary frs-confirm-ok">Confirm</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        titleEl = modal.querySelector('.frs-confirm-title');
        msgEl = modal.querySelector('.frs-confirm-message');
        okBtn = modal.querySelector('.frs-confirm-ok');
        cancelBtn = modal.querySelector('.frs-confirm-cancel');
        backdrop = modal.querySelector('.frs-confirm-backdrop');
    }

    function frsConfirm(message, opts) {
        opts = opts || {};
        build();
        titleEl.textContent = opts.title || 'Are you sure?';
        msgEl.textContent = message || '';
        okBtn.textContent = opts.confirmText || 'Confirm';
        okBtn.classList.toggle('frs-confirm-ok--danger', !!opts.danger);
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        okBtn.focus();

        return new Promise(function (resolve) {
            function cleanup(result) {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                backdrop.removeEventListener('click', onCancel);
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onOk() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onKey(e) {
                if (e.key === 'Escape') {
                    cleanup(false);
                }
            }
            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            backdrop.addEventListener('click', onCancel);
            document.addEventListener('keydown', onKey);
        });
    }

    /**
     * Wire a form's onsubmit to use frsConfirm instead of confirm().
     * Usage: onsubmit="return frsConfirmSubmit(this, 'Delete this?')"
     */
    function frsConfirmSubmit(form, message, opts) {
        if (!form) {
            return false;
        }
        if (form.dataset.frsConfirmed === '1') {
            form.dataset.frsConfirmed = '';
            return true;
        }
        frsConfirm(message, opts).then(function (ok) {
            if (ok) {
                form.dataset.frsConfirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
        return false;
    }

    window.frsConfirm = frsConfirm;
    window.frsConfirmSubmit = frsConfirmSubmit;
})();
