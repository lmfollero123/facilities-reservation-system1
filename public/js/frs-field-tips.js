/**
 * Field help tooltips (ⓘ) — single floating layer on document.body.
 */
(function () {
    'use strict';

    var FLOAT_ID = 'frs-tip-float';
    var closeTimer = null;
    var activeBtn = null;
    var floatEl = null;

    function hoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }

    function getFloat() {
        if (floatEl && document.body.contains(floatEl)) {
            return floatEl;
        }
        floatEl = document.getElementById(FLOAT_ID);
        if (!floatEl) {
            floatEl = document.createElement('div');
            floatEl.id = FLOAT_ID;
            floatEl.className = 'frs-tip-float';
            floatEl.setAttribute('role', 'tooltip');
            document.body.appendChild(floatEl);
        }
        return floatEl;
    }

    function tipTextFromBtn(btn) {
        if (!btn) return '';
        if (btn.dataset.frsTip) {
            return btn.dataset.frsTip;
        }
        var wrap = btn.closest('.frs-tip');
        if (!wrap) return '';
        var legacy = wrap.querySelector('.frs-tip-popup');
        return legacy ? (legacy.textContent || '').trim() : '';
    }

    function hideFloat() {
        clearTimeout(closeTimer);
        var el = getFloat();
        el.classList.remove('is-visible');
        el.textContent = '';
        el.removeAttribute('style');
        activeBtn = null;
        document.querySelectorAll('.frs-tip.is-open').forEach(function (t) {
            t.classList.remove('is-open');
        });
    }

    function positionFloat(btn) {
        var el = getFloat();
        var text = tipTextFromBtn(btn);
        if (!text) {
            hideFloat();
            return;
        }

        el.textContent = text;
        el.classList.add('is-visible');

        var margin = 10;
        var maxW = Math.min(300, window.innerWidth - margin * 2);
        el.style.maxWidth = maxW + 'px';

        /* Measure without flashing (visibility only; display comes from .is-visible) */
        el.style.visibility = 'hidden';
        var ph = el.offsetHeight;
        var pw = el.offsetWidth;
        el.style.visibility = '';

        var br = btn.getBoundingClientRect();
        var top = br.bottom + 6;
        var left = br.left + br.width / 2 - pw / 2;

        if (left + pw > window.innerWidth - margin) {
            left = window.innerWidth - margin - pw;
        }
        if (left < margin) {
            left = margin;
        }
        if (top + ph > window.innerHeight - margin) {
            top = br.top - ph - 8;
        }
        if (top < margin) {
            top = margin;
        }

        el.style.top = Math.round(top) + 'px';
        el.style.left = Math.round(left) + 'px';
    }

    function showTip(btn) {
        if (!btn) return;
        clearTimeout(closeTimer);
        document.querySelectorAll('.frs-tip.is-open').forEach(function (t) {
            t.classList.remove('is-open');
        });
        var tip = btn.closest('.frs-tip');
        if (tip) {
            tip.classList.add('is-open');
        }
        activeBtn = btn;
        positionFloat(btn);
    }

    function scheduleHide() {
        clearTimeout(closeTimer);
        closeTimer = setTimeout(hideFloat, 80);
    }

    function isTipBtn(el) {
        return el && el.closest && el.closest('.frs-tip-btn');
    }

    function migrateLegacyPopups() {
        document.querySelectorAll('.frs-tip').forEach(function (tip, idx) {
            if (!tip.id) {
                tip.id = 'frs-tip-' + idx;
            }
            var btn = tip.querySelector('.frs-tip-btn');
            var pop = tip.querySelector('.frs-tip-popup');
            if (btn && pop && !btn.dataset.frsTip) {
                btn.dataset.frsTip = (pop.textContent || '').trim();
            }
        });
    }

    function initTips() {
        getFloat();
        migrateLegacyPopups();

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.frs-tip-btn');
            if (btn) {
                if (!hoverCapable()) {
                    var wasOpen = activeBtn === btn && getFloat().classList.contains('is-visible');
                    hideFloat();
                    if (!wasOpen) {
                        showTip(btn);
                    }
                }
                e.stopPropagation();
                return;
            }
            if (!e.target.closest('.frs-tip-btn')) {
                hideFloat();
            }
        });

        document.addEventListener('mouseover', function (e) {
            if (!hoverCapable()) return;
            var btn = e.target.closest('.frs-tip-btn');
            if (btn) {
                showTip(btn);
            }
        });

        document.addEventListener('mouseout', function (e) {
            if (!hoverCapable()) return;
            var btn = e.target.closest('.frs-tip-btn');
            if (!btn) return;
            var to = e.relatedTarget;
            if (isTipBtn(to)) {
                return;
            }
            scheduleHide();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideFloat();
            }
        });

        window.addEventListener('resize', function () {
            if (activeBtn && getFloat().classList.contains('is-visible')) {
                positionFloat(activeBtn);
            }
        });

        window.addEventListener('scroll', function () {
            if (activeBtn && getFloat().classList.contains('is-visible')) {
                positionFloat(activeBtn);
            }
        }, true);
    }

    window.frsRefreshFieldTips = migrateLegacyPopups;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTips);
    } else {
        initTips();
    }
})();
