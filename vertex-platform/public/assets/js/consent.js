/* Cookie consent: the banner, the settings dialog, and the gate for optional scripts. No outside code. Without JavaScript nothing optional ever runs, and the
   /cookie-settings page works as an ordinary form. */
(function () {
    'use strict';
    var NAME = 'kis_consent';
    var banner = document.getElementById('kis-cookie-banner');
    var dialog = document.getElementById('kis-cookie-dialog');

    function readCookie() {
        var m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : '';
    }
    function version() { return banner ? parseInt(banner.getAttribute('data-version') || '1', 10) : 1; }
    function parse() {
        var m = /^v(\d{1,6})\.(\d{9,11})\.([01])([01])([01])\.[a-f0-9]{32}$/.exec(readCookie());
        if (!m) { return null; }
        var days = banner ? parseInt(banner.getAttribute('data-days') || '180', 10) : 180;
        var at = parseInt(m[2], 10);
        if (parseInt(m[1], 10) !== version() || at + days * 86400 < Date.now() / 1000) { return null; }
        return { preferences: m[3] === '1', analytics: m[4] === '1', marketing: m[5] === '1' };
    }
    var state = parse();
    var API = {
        get: function () { return state; },
        allowed: function (cat) { return cat === 'necessary' || !!(state && state[cat]); },
        /* Browser storage for a choice the visitor made (like the theme): kept for good only with permission for 'preferences', else just for this tab. */
        store: function () { try { return API.allowed('preferences') ? window.localStorage : window.sessionStorage; } catch (e) { return null; } }
    };
    window.KISConsent = API;

    var loaded = {};
    function loadScripts() {
        if (!banner) { return; }
        var list = [];
        try { list = JSON.parse(banner.getAttribute('data-scripts') || '[]'); } catch (e) { list = []; }
        list.forEach(function (s) {
            if (!API.allowed(s.category) || loaded[s.key] || !/^https:\/\//.test(s.script_url)) { return; }
            loaded[s.key] = true;
            var el = document.createElement('script');
            el.src = s.script_url;
            el.async = true;
            document.head.appendChild(el);
        });
    }

    function show() { if (banner) { banner.hidden = false; } }
    function hide() { if (banner) { banner.hidden = true; } }
    function fill(form) {
        if (!form) { return; }
        ['preferences', 'analytics', 'marketing'].forEach(function (c) {
            var box = form.querySelector('[data-cat="' + c + '"]');
            if (box) { box.checked = !!(state && state[c]); }
        });
    }
    function send(action, form) {
        var body = new URLSearchParams();
        body.set('action', action);
        if (action === 'custom' && form) {
            ['preferences', 'analytics', 'marketing'].forEach(function (c) {
                var box = form.querySelector('[data-cat="' + c + '"]');
                if (box && box.checked) { body.set(c, '1'); }
            });
        }
        return fetch('/cookie-settings', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var wasOn = state && (state.preferences || state.analytics || state.marketing);
                state = { preferences: !!j.preferences, analytics: !!j.analytics, marketing: !!j.marketing };
                if (!state.preferences) { try { window.localStorage.removeItem('kis-theme'); window.sessionStorage.setItem('kis-theme', document.documentElement.getAttribute('data-theme') || ''); } catch (e) { /* storage unavailable */ } }
                hide();
                if (dialog && dialog.open) { dialog.close(); }
                loadScripts();
                document.dispatchEvent(new CustomEvent('kis:consent', { detail: state }));
                if (wasOn && (!state.analytics || !state.marketing) && banner && JSON.parse(banner.getAttribute('data-scripts') || '[]').length) { window.location.reload(); }
            });
    }

    if (banner) {
        if (!state) { show(); }
        loadScripts();
        document.addEventListener('click', function (e) {
            var t = e.target;
            if (!(t instanceof Element)) { return; }
            var c = t.closest('[data-consent]');
            if (c && !c.closest('[data-cookie-page-form]')) {
                e.preventDefault();
                send(c.getAttribute('data-consent'), c.closest('form'));
                return;
            }
            if (t.closest('[data-consent-customize]')) {
                e.preventDefault();
                if (dialog && dialog.showModal) { fill(dialog.querySelector('form')); dialog.showModal(); } else { window.location.href = '/cookie-settings'; }
                return;
            }
            if (t.closest('[data-consent-close]')) { e.preventDefault(); if (dialog) { dialog.close(); } return; }
            var open = t.closest('a[data-cookie-settings]');
            if (open && dialog && dialog.showModal) {
                e.preventDefault();
                fill(dialog.querySelector('form'));
                dialog.showModal();
            }
        });
        if (dialog) {
            dialog.addEventListener('submit', function (e) {
                var s = e.submitter;
                e.preventDefault();
                send(s && s.value ? s.value : 'custom', dialog.querySelector('form'));
            });
        }
    }
})();
