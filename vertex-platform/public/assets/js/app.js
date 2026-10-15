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

    // Portal sidebar (small screens)
    var sideBtn = document.querySelector('[data-side-toggle]');
    var side = document.getElementById('portal-side');
    if (sideBtn && side) {
        sideBtn.addEventListener('click', function () {
            var open = side.classList.toggle('is-open');
            sideBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        side.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') { side.classList.remove('is-open'); sideBtn.setAttribute('aria-expanded', 'false'); }
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

/* Form builder: show/hide questions that depend on another answer. The server repeats the same rule, so this is only a convenience. */
(function () {
    'use strict';
    var form = document.querySelector('form[data-dynamic-form]');
    if (!form) { return; }
    function value(key) {
        var els = form.querySelectorAll('[name="a[' + key + ']"], [name="a[' + key + '][]"]');
        var out = [];
        els.forEach(function (el) {
            if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) { out.push(el.value); } }
            else if (el.value !== '') { out.push(el.value); }
        });
        return out;
    }
    function shown(box, depth) {
        var ctrl = box.getAttribute('data-show-field');
        if (!ctrl || depth > 60) { return true; }
        var parent = form.querySelector('[data-key="' + ctrl + '"]');
        if (parent && !shown(parent, depth + 1)) { return false; }
        var vals = value(ctrl), want = box.getAttribute('data-show-value') || '', op = box.getAttribute('data-show-op');
        var joined = vals.join('\n').toLowerCase();
        if (op === 'equals') { return vals.indexOf(want) !== -1; }
        if (op === 'not_equals') { return vals.indexOf(want) === -1; }
        if (op === 'contains') { return want !== '' && joined.indexOf(want.toLowerCase()) !== -1; }
        if (op === 'filled') { return vals.length > 0 && joined.trim() !== '' && joined !== '0'; }
        return true;
    }
    function update() {
        form.querySelectorAll('[data-show-field]').forEach(function (box) {
            var on = shown(box, 0);
            box.hidden = !on;
            box.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !on; });
        });
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
}());
