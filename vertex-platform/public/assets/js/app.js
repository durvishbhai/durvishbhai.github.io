/* Vertex front-end behaviour. No inline scripts are allowed by the CSP, so everything lives here. */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    // Mobile navigation
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.getElementById('site-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            var open = nav.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        nav.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') {
                nav.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Confirmation dialogs for sensitive forms. Progressive enhancement ONLY: the server independently requires the
    // actor's password for every sensitive action, so disabling JS does not bypass anything.
    document.addEventListener('submit', function (e) {
        var msg = e.target && e.target.getAttribute && e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });

    // Print button on invoices and quotations (inline handlers are blocked by the CSP).
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-print]') : null;
        if (t) { e.preventDefault(); window.print(); }
    });

    // Loading state for slow submits (uploads): form[data-busy] shows the message on its submit button and prevents a double submit.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || !f.getAttribute || e.defaultPrevented) { return; }
        var msg = f.getAttribute('data-busy');
        if (!msg) { return; }
        var btn = f.querySelector('button[type=submit]');
        if (btn) { btn.setAttribute('data-label', btn.textContent); btn.textContent = msg; btn.disabled = true; }
        f.classList.add('is-busy');
    });

    // Progress bars: widths come from data-pct (inline styles are blocked by the CSP).
    document.querySelectorAll('[data-pct]').forEach(function (el) {
        el.style.width = Math.max(0, Math.min(100, parseInt(el.getAttribute('data-pct'), 10) || 0)) + '%';
    });

    // Scroll reveal (content stays visible if IntersectionObserver is unavailable)
    var items = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window)) {
        items.forEach(function (el) { el.classList.add('is-visible'); });
        return;
    }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    items.forEach(function (el) { io.observe(el); });
})();
