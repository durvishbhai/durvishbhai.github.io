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

    // Public website: tag the content so it animates in as it scrolls into view (skipped for people who prefer reduced motion)
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (document.body.classList.contains('site') && !reduce) {
        var tag = function (selector, variant, stagger) {
            document.querySelectorAll('main ' + selector).forEach(function (el, i) {
                if (el.classList.contains('reveal')) { return; }
                el.classList.add('reveal');
                if (variant) { el.classList.add(variant); }
                if (stagger) {
                    var idx = Array.prototype.indexOf.call(el.parentNode.children, el);
                    el.style.transitionDelay = (Math.min(idx, 7) * 80) + 'ms';
                }
            });
        };
        tag('.pub-section h2, .pub-cta h2, .group-title', null, false);
        tag('.pub-section .container > .prose, .pub-section .container > .muted, .pub-cta .container > .lead, .pub-cta .container > .hero-actions', null, false);
        tag('.service-card, .team-card, .partner-card, .job-card, .work-card, .role-card', 'reveal-zoom', true);
        tag('.steps > li, .check-list > li, .faq-item, .empty-card, .side-card, .hire-terms, .hire-form-panel, .contact-grid > .panel', 'reveal-left', true);
        tag('.pub-chip', 'reveal-zoom', true);
        // a soft light that follows the pointer over the cards
        document.querySelectorAll('main .service-card, main .team-card, main .partner-card, main .job-card, main .role-card, main .work-card').forEach(function (card) {
            card.classList.add('spot');
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
                card.style.setProperty('--my', (e.clientY - r.top) + 'px');
            });
        });
        // a thin progress bar showing how far down the page you are
        var bar = document.createElement('div');
        bar.className = 'scroll-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
        var ticking = false;
        var paint = function () {
            var h = document.documentElement.scrollHeight - window.innerHeight;
            bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, window.scrollY / h) : 0) + ')';
            ticking = false;
        };
        window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(paint); } }, { passive: true });
        paint();
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
                var t = entry.target;
                t.classList.add('is-visible');
                io.unobserve(t);
                if (t.style.transitionDelay) { window.setTimeout(function () { t.style.transitionDelay = ''; }, 1400); } // so later hover effects are not delayed
            }
        });
    }, { threshold: 0.01, rootMargin: '0px 0px -6% 0px' });
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
            var key = fam === 'video' ? 'data-video' : 'data-dev';
            if (linkLabel && linkLabel.getAttribute(key)) { linkLabel.textContent = linkLabel.getAttribute(key); }
            if (skillsLabel && skillsLabel.getAttribute(key)) { skillsLabel.textContent = skillsLabel.getAttribute(key); }
            if (skills) {
                var ph = skills.getAttribute(fam === 'video' ? 'data-ph-video' : 'data-ph-dev');
                if (ph) { skills.placeholder = ph; }
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
        var errBox = form.parentNode.querySelector('[data-form-errors]');
        var labelOf = function (el) {
            var l = el.id ? form.querySelector('label[for="' + el.id + '"]') : null;
            if (el.type === 'checkbox') { return 'Agreeing to the privacy notice'; }
            return l ? l.textContent.replace(/\(.*?\)/g, '').replace(/\s+/g, ' ').trim() : (el.name || 'a field');
        };
        form.addEventListener('input', function (e) { var f = e.target.closest ? e.target.closest('.field') : null; if (f) { f.classList.remove('is-invalid'); } });
        form.addEventListener('submit', function (e) {
            // The browser's own checks are off (novalidate), so check here and say clearly what is missing, right beside the button
            var bad = [];
            required.forEach(function (el) {
                var empty = el.type === 'checkbox' ? !el.checked : (el.type === 'file' ? !(el.files && el.files.length) : el.value.trim() === '');
                var wrong = !empty && el.type !== 'checkbox' && el.type !== 'file' && !el.checkValidity();
                var f = el.closest('.field');
                if (f) { f.classList.toggle('is-invalid', empty || wrong); }
                if (empty || wrong) { bad.push({ el: el, text: labelOf(el) + (empty ? ' is required' : ' is not valid') }); }
            });
            if (bad.length) {
                e.preventDefault();
                if (errBox) {
                    errBox.innerHTML = '';
                    var strong = document.createElement('strong'); strong.textContent = 'Please complete the form before sending:'; errBox.appendChild(strong);
                    var ul = document.createElement('ul');
                    bad.forEach(function (b) { var li = document.createElement('li'); li.textContent = b.text + '.'; ul.appendChild(li); });
                    errBox.appendChild(ul);
                    errBox.hidden = false;
                    errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    errBox.focus({ preventScroll: true });
                }
                return;
            }
            var b = form.querySelector('[data-hire-submit]');
            if (b) { window.setTimeout(function () { b.disabled = true; b.textContent = 'Sending…'; }, 0); }
        });
        sync();
    })();
    // Payment request page: countdown, and ask the server (which asks Razorpay) whether it has been paid
    (function () {
        var box = document.querySelector('[data-pay-poll]');
        if (!box) { return; }
        var url = box.getAttribute('data-pay-poll');
        var expires = parseInt(box.getAttribute('data-pay-expires'), 10) * 1000;
        var left = box.querySelector('[data-pay-left]');
        var liveEl = box.querySelector('[data-pay-live]');
        var paidEl = box.querySelector('[data-pay-paid]');
        var overEl = box.querySelector('[data-pay-over]');
        var done = box.getAttribute('data-pay-state') !== 'live';
        var show = function (state) {
            done = true;
            if (liveEl) { liveEl.hidden = true; }
            if (paidEl) { paidEl.hidden = state !== 'paid'; }
            if (overEl) { overEl.hidden = state === 'paid'; }
        };
        var tick = function () {
            if (done) { return; }
            var ms = expires - Date.now();
            if (ms <= 0) { show('over'); return; }
            var s = Math.floor(ms / 1000);
            if (left) { left.textContent = ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2); }
        };
        var poll = function () {
            if (done) { return; }
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
                if (!d) { return; }
                if (d.paid) { show('paid'); } else if (!d.live) { show('over'); }
            }).catch(function () {});
        };
        tick();
        window.setInterval(tick, 1000);
        window.setInterval(poll, 5000);
    })();
    // Razorpay Standard Web Checkout: create the order on our server, open Razorpay's window, send the result back to be verified (only the server decides it is paid)
    (function () {
        var btn = document.querySelector('[data-checkout-start]');
        if (!btn) { return; }
        var base = btn.getAttribute('data-checkout-start');
        var verifyUrl = btn.getAttribute('data-checkout-verify');
        var errEl = document.querySelector('[data-checkout-error]');
        var meta = document.querySelector('meta[name="csrf-token"]');
        var csrf = meta ? meta.getAttribute('content') : '';
        var label = btn.textContent;
        var fail = function (msg) {
            if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
            btn.disabled = false;
            btn.textContent = label;
        };
        var post = function (url, fields) {
            return fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf },
                body: new URLSearchParams(fields || {}).toString()
            }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
        };
        var loadScript = function () {
            return new Promise(function (resolve, reject) {
                if (window.Razorpay) { resolve(); return; }
                var s = document.createElement('script');
                s.src = 'https://checkout.razorpay.com/v1/checkout.js';
                s.onload = function () { resolve(); };
                s.onerror = function () { reject(new Error('load')); };
                document.head.appendChild(s);
            });
        };
        btn.addEventListener('click', function () {
            if (errEl) { errEl.hidden = true; }
            btn.disabled = true;
            btn.textContent = 'Opening Razorpay…';
            Promise.all([post(base + '/checkout'), loadScript()]).then(function (res) {
                var o = res[0];
                if (!o.ok) { fail(o.data.error || 'Payment could not be started. Please try again.'); return; }
                var d = o.data;
                var settled = false;
                var rzp = new window.Razorpay({
                    key: d.key_id, amount: d.amount, currency: d.currency, order_id: d.order_id, name: d.name, description: d.description, prefill: d.prefill || {},
                    theme: { color: '#4f6bff' },
                    handler: function (resp) {
                        settled = true;
                        btn.textContent = 'Confirming your payment…';
                        post(verifyUrl, { razorpay_payment_id: resp.razorpay_payment_id, razorpay_order_id: resp.razorpay_order_id, razorpay_signature: resp.razorpay_signature }).then(function (v) {
                            if (v.ok && v.data.success) { window.location.reload(); return; }
                            fail(v.data.error || 'We could not confirm the payment. If money was taken it will be matched to the invoice automatically.');
                        }).catch(function () { fail('We could not confirm the payment. If money was taken it will be matched to the invoice automatically.'); });
                    },
                    modal: { ondismiss: function () { if (!settled) { fail('The payment window was closed before paying. Nothing was charged. You can try again.'); } } }
                });
                rzp.on('payment.failed', function (resp) {
                    fail((resp && resp.error && resp.error.description) ? resp.error.description + ' You can try again.' : 'The payment failed. You can try again.');
                });
                rzp.open();
            }).catch(function () { fail('Razorpay could not be loaded. Check your connection and try again.'); });
        });
    })();
    // Light / dark theme switch (the saved choice itself is applied earlier by theme.js)
    (function () {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        if (!buttons.length) { return; }
        var root = document.documentElement;
        var paint = function () {
            var light = root.getAttribute('data-theme') === 'light';
            buttons.forEach(function (b) { b.setAttribute('aria-label', light ? 'Switch to dark theme' : 'Switch to light theme'); b.setAttribute('title', light ? 'Switch to dark theme' : 'Switch to light theme'); });
            var meta = document.querySelector('meta[name="theme-color"]');
            if (meta) { meta.setAttribute('content', light ? '#f4f6fc' : '#050818'); }
        };
        buttons.forEach(function (b) {
            b.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                root.setAttribute('data-theme', next);
                try { window.localStorage.setItem('kis-theme', next); } catch (e) { /* private mode: the choice lasts for this page only */ }
                paint();
            });
        });
        paint();
    })();
    // "Copy link" boxes
    document.querySelectorAll('[data-copy-button]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var src = btn.parentNode.querySelector('[data-copy-source]');
            if (!src) { return; }
            src.select();
            var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; window.setTimeout(function () { btn.textContent = t; }, 1600); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(src.value).then(done, function () { document.execCommand('copy'); done(); }); } else { document.execCommand('copy'); done(); }
        });
    });
})();
