/* A small rich-text editor for the Owner's legal documents: no outside code, so the strict CSP stays as it is. The textarea stays in the page (and is what is
   submitted), so the form still works without JavaScript; the server sanitises whatever arrives. */
(function () {
    'use strict';
    var ta = document.querySelector('textarea[data-rich-editor]');
    if (!ta) { return; }
    var form = ta.form;
    var wrap = document.createElement('div');
    wrap.className = 'rte';
    var bar = document.createElement('div');
    bar.className = 'rte-bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Text formatting');
    var area = document.createElement('div');
    area.className = 'rte-area prose policy';
    area.contentEditable = 'true';
    area.setAttribute('role', 'textbox');
    area.setAttribute('aria-multiline', 'true');
    area.setAttribute('aria-label', 'Document text');
    area.innerHTML = ta.value;
    var source = false;

    function cmd(name, value) { area.focus(); document.execCommand(name, false, value || null); }
    function button(label, title, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-ghost btn-sm';
        b.textContent = label;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.addEventListener('click', function (e) { e.preventDefault(); if (!source) { fn(); } });
        bar.appendChild(b);
        return b;
    }
    button('H2', 'Section heading', function () { cmd('formatBlock', 'h2'); });
    button('H3', 'Sub-heading', function () { cmd('formatBlock', 'h3'); });
    button('¶', 'Normal paragraph', function () { cmd('formatBlock', 'p'); });
    button('B', 'Bold', function () { cmd('bold'); });
    button('I', 'Italic', function () { cmd('italic'); });
    button('• List', 'Bulleted list', function () { cmd('insertUnorderedList'); });
    button('1. List', 'Numbered list', function () { cmd('insertOrderedList'); });
    button('Quote', 'Quote', function () { cmd('formatBlock', 'blockquote'); });
    button('Link', 'Add a link', function () {
        var url = window.prompt('Link address (https://…, mailto:… or /page)');
        if (url) { cmd('createLink', url); }
    });
    button('Unlink', 'Remove a link', function () { cmd('unlink'); });
    button('Clear', 'Clear formatting', function () { cmd('removeFormat'); });
    button('Undo', 'Undo', function () { cmd('undo'); });
    button('Redo', 'Redo', function () { cmd('redo'); });

    var tokens = {};
    try { tokens = JSON.parse(ta.getAttribute('data-tokens') || '{}'); } catch (e) { tokens = {}; }
    var sel = document.createElement('select');
    sel.setAttribute('aria-label', 'Insert a business fact');
    var first = document.createElement('option');
    first.value = '';
    first.textContent = 'Insert fact…';
    sel.appendChild(first);
    Object.keys(tokens).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = tokens[k];
        sel.appendChild(o);
    });
    sel.addEventListener('change', function () {
        if (sel.value && !source) { cmd('insertText', '{{' + sel.value + '}}'); }
        sel.value = '';
    });
    bar.appendChild(sel);

    var toggle = button('HTML', 'Switch between the editor and the HTML source', function () {});
    toggle.addEventListener('click', function () {
        if (!source) {
            ta.value = area.innerHTML;
            area.hidden = true;
            ta.hidden = false;
            source = true;
            toggle.setAttribute('aria-pressed', 'true');
        } else {
            area.innerHTML = ta.value;
            ta.hidden = true;
            area.hidden = false;
            source = false;
            toggle.setAttribute('aria-pressed', 'false');
        }
    });
    toggle.setAttribute('aria-pressed', 'false');

    ta.hidden = true;
    ta.parentNode.insertBefore(wrap, ta);
    wrap.appendChild(bar);
    wrap.appendChild(area);
    wrap.appendChild(ta);
    if (form) {
        form.addEventListener('submit', function () { if (!source) { ta.value = area.innerHTML; } });
    }
})();
