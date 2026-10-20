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

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Progress bars: widths come from data-pct (inline styles are blocked by the CSP). They grow from zero unless motion is reduced.
    var bars = document.querySelectorAll('[data-pct]');
    bars.forEach(function (el) {
        el.style.width = reduceMotion ? Math.max(0, Math.min(100, parseInt(el.getAttribute('data-pct'), 10) || 0)) + '%' : '0%';
    });
    if (!reduceMotion && bars.length) {
        window.requestAnimationFrame(function () { window.requestAnimationFrame(function () {
            bars.forEach(function (el) { el.style.width = Math.max(0, Math.min(100, parseInt(el.getAttribute('data-pct'), 10) || 0)) + '%'; });
        }); });
    }

    // Count-up for dashboard figures: the real number is already in the page; this only animates it.
    if (!reduceMotion) {
        document.querySelectorAll('[data-count]').forEach(function (el) {
            var end = parseInt(el.getAttribute('data-count'), 10);
            if (!isFinite(end) || end < 1 || end > 1000000) { return; }
            var start = null, dur = 700;
            el.textContent = '0';
            function tick(t) {
                if (start === null) { start = t; }
                var k = Math.min(1, (t - start) / dur);
                el.textContent = String(Math.round(end * (1 - Math.pow(1 - k, 3))));
                if (k < 1) { window.requestAnimationFrame(tick); } else { el.textContent = String(end); }
            }
            window.requestAnimationFrame(tick);
        });
    }

    // Thin loading bar at the top while a link or form navigates to the next page.
    if (!reduceMotion) {
        var bar = document.createElement('div');
        bar.className = 'nav-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
        var start = function () { bar.classList.remove('is-running'); void bar.offsetWidth; bar.classList.add('is-running'); };
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-print')) { return; }
            var h = a.getAttribute('href') || '';
            if (h.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(h) || (a.host && a.host !== window.location.host)) { return; }
            start();
        });
        document.addEventListener('submit', function (e) { if (!e.defaultPrevented) { start(); } });
        window.addEventListener('pageshow', function () { bar.classList.remove('is-running'); });
    }

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

/* Public website: dropdown toggles (small screens), live FAQ search, portfolio category filter. The pages work without this script. */
(function () {
    'use strict';
    document.querySelectorAll('[data-sub-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('.has-sub');
            var open = li.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
    var input = document.querySelector('[data-faq-search]');
    if (input) {
        var items = document.querySelectorAll('[data-faq-item]');
        var groups = document.querySelectorAll('[data-faq-group]');
        var none = document.querySelector('[data-faq-none]');
        var run = function () {
            var q = input.value.trim().toLowerCase(), shown = 0;
            items.forEach(function (el) {
                var hit = q === '' || el.textContent.toLowerCase().indexOf(q) !== -1;
                el.hidden = !hit;
                if (hit) { shown++; }
            });
            groups.forEach(function (g) {
                var n = g.nextElementSibling, any = false;
                while (n && n.hasAttribute('data-faq-item')) { if (!n.hidden) { any = true; } n = n.nextElementSibling; }
                g.hidden = !any;
            });
            if (none) { none.hidden = shown !== 0 || items.length === 0; }
        };
        input.addEventListener('input', run);
        var form = input.form;
        if (form) { form.addEventListener('submit', function (e) { e.preventDefault(); run(); }); }
        run();
    }
    var chips = document.querySelectorAll('[data-filter-group] [data-filter]');
    if (chips.length) {
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var want = chip.getAttribute('data-filter');
                chips.forEach(function (c) { c.classList.toggle('is-on', c === chip); });
                document.querySelectorAll('.work-card[data-category]').forEach(function (card) {
                    card.hidden = want !== '' && card.getAttribute('data-category') !== want;
                });
            });
        });
    }
    // Hiring page: pick a role, live progress, counters, file name, role-aware hints
    (function () {
        var form = document.querySelector('[data-hire-form]');
        if (!form) { return; }
        var select = form.querySelector('[data-hire-role]');
        var cards = document.querySelectorAll('[data-hire-roles] .role-card');
        var linkLabel = form.querySelector('[data-hire-link-label]');
        var skillsLabel = form.querySelector('[data-hire-skills-label]');
        var skills = form.querySelector('#skills_experience');
        var fileInput = form.querySelector('#resume');
        var fileName = form.querySelector('[data-hire-file-name]');
        var drop = form.querySelector('[data-hire-drop]');
        var bar = form.parentNode.querySelector('[data-hire-bar]');
        var barText = form.parentNode.querySelector('[data-hire-progress-text]');
        var required = form.querySelectorAll('[required]');
        var update = function () {
            var done = 0;
            required.forEach(function (el) {
                if ((el.type === 'checkbox' ? el.checked : el.value.trim() !== '') && el.checkValidity()) { done++; }
            });
            var pct = Math.round(done * 100 / required.length);
            if (bar) { bar.style.width = pct + '%'; }
            if (barText) { barText.textContent = pct + '% complete'; }
        };
        var family = function () {
            var o = select.options[select.selectedIndex];
            return o ? o.getAttribute('data-family') : '';
        };
        var sync = function () {
            var fam = family();
            cards.forEach(function (c) {
                var on = c.getAttribute('data-role') === select.value;
                c.classList.toggle('is-selected', on);
                c.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (linkLabel) {
                linkLabel.textContent = fam === 'video' ? 'Showreel or portfolio link (optional, https://…)' : 'GitHub or portfolio link (optional, https://…)';
            }
            if (skillsLabel) {
                skillsLabel.textContent = fam === 'video' ? 'Editing tools and experience' : 'Tech stack and experience';
            }
            if (skills) {
                skills.placeholder = fam === 'video' ? 'For example: editing software you use, kinds of videos you have edited, how fast you work.' : 'For example: languages, frameworks, databases and the projects you have built.';
            }
            update();
        };
        cards.forEach(function (c) {
            c.addEventListener('click', function () {
                select.value = c.getAttribute('data-role');
                sync();
                var target = document.getElementById('apply');
                if (target) {
                    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                }
                var first = form.querySelector('#full_name');
                if (first) { window.setTimeout(function () { first.focus({ preventScroll: true }); }, 400); }
            });
        });
        select.addEventListener('change', sync);
        form.querySelectorAll('[data-hire-count]').forEach(function (ta) {
            var out = ta.parentNode.querySelector('[data-hire-count-out]');
            var upd = function () { if (out) { out.textContent = String(ta.value.length); } };
            ta.addEventListener('input', upd); upd();
        });
        if (fileInput && fileName) {
            fileInput.addEventListener('change', function () {
                var f = fileInput.files && fileInput.files[0];
                fileName.textContent = f ? f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)' : 'Choose a file or drop it here';
            });
            if (drop) {
                ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
                ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
                drop.addEventListener('drop', function (e) {
                    if (e.dataTransfer && e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileInput.dispatchEvent(new Event('change')); }
                });
            }
        }
        form.addEventListener('input', update);
        form.addEventListener('change', update);
        form.addEventListener('submit', function () {
            var b = form.querySelector('[data-hire-submit]');
            if (b) { b.disabled = true; b.textContent = 'Sending…'; }
        });
        sync();
    })();
})();
