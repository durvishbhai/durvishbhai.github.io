/* Kanban drag-and-drop. Progressive enhancement: every card also carries a Move form that works without JavaScript.
 * The server (POST /tasks/{id}/status) validates every move; this script never decides what is allowed. */
(function () {
    'use strict';

    var board = document.querySelector('[data-board]');
    if (!board) { return; }

    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : board.getAttribute('data-csrf');
    var needReason = (board.getAttribute('data-reason-for') || '').split(',');
    var live = document.getElementById('board-status');
    var labels = {};
    board.querySelectorAll('.board-col').forEach(function (col) {
        labels[col.getAttribute('data-status')] = col.getAttribute('aria-label');
    });
    var dragged = null;

    function say(message) {
        if (live) { live.textContent = message; }
        if (message && !live) { window.alert(message); }
    }

    function counts() {
        board.querySelectorAll('.board-col').forEach(function (col) {
            var n = col.querySelectorAll('.board-card').length;
            var c = col.querySelector('[data-count]');
            if (c) { c.textContent = String(n); }
            var empty = col.querySelector('.board-empty');
            if (n === 0 && !empty) {
                var p = document.createElement('p');
                p.className = 'muted board-empty';
                p.textContent = 'Nothing here.';
                col.querySelector('[data-dropzone]').appendChild(p);
            } else if (n > 0 && empty) {
                empty.remove();
            }
        });
    }

    function refreshMove(card, allowed) {
        card.setAttribute('data-allowed', allowed.join(','));
        var select = card.querySelector('.board-move select');
        if (!select) { return; }
        while (select.firstChild) { select.removeChild(select.firstChild); }
        allowed.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s;
            o.textContent = labels[s] || s;
            select.appendChild(o);
        });
    }

    function clearMarks() {
        board.querySelectorAll('.board-col').forEach(function (c) { c.classList.remove('can-drop', 'cannot-drop', 'is-over'); });
    }

    board.addEventListener('dragstart', function (e) {
        var card = e.target.closest ? e.target.closest('.board-card') : null;
        if (!card || card.getAttribute('draggable') !== 'true') { return; }
        dragged = card;
        card.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.getAttribute('data-task'));
        var allowed = (card.getAttribute('data-allowed') || '').split(',');
        board.querySelectorAll('.board-col').forEach(function (col) {
            var s = col.getAttribute('data-status');
            col.classList.add(allowed.indexOf(s) !== -1 ? 'can-drop' : 'cannot-drop');
        });
    });

    board.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('is-dragging'); }
        dragged = null;
        clearMarks();
    });

    board.addEventListener('dragover', function (e) {
        var col = e.target.closest ? e.target.closest('.board-col') : null;
        if (!col || !dragged) { return; }
        if (col.classList.contains('can-drop')) {
            e.preventDefault();
            col.classList.add('is-over');
        }
    });

    board.addEventListener('dragleave', function (e) {
        var col = e.target.closest ? e.target.closest('.board-col') : null;
        if (col) { col.classList.remove('is-over'); }
    });

    board.addEventListener('drop', function (e) {
        var col = e.target.closest ? e.target.closest('.board-col') : null;
        if (!col || !dragged || !col.classList.contains('can-drop')) { return; }
        e.preventDefault();
        var card = dragged;
        var to = col.getAttribute('data-status');
        clearMarks();
        if (to === card.getAttribute('data-status')) { return; }
        var reason = '';
        if (needReason.indexOf(to) !== -1) {
            reason = window.prompt('Why is this task moving to ' + (labels[to] || to) + '?') || '';
            if (reason.trim() === '') { say('Move cancelled: a reason is required.'); return; }
        }
        move(card, col, to, reason);
    });

    function move(card, col, to, reason) {
        var body = new URLSearchParams();
        body.set('status', to);
        body.set('reason', reason);
        card.classList.add('is-saving');
        fetch('/tasks/' + encodeURIComponent(card.getAttribute('data-task')) + '/status', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': token },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, error: 'The server returned an unexpected response (' + res.status + ').' }; });
        }).then(function (json) {
            card.classList.remove('is-saving');
            if (!json.ok) { say(json.error || 'That move was not allowed.'); return; }
            col.querySelector('[data-dropzone]').appendChild(card);
            card.setAttribute('data-status', json.status);
            refreshMove(card, json.allowed || []);
            if ((json.allowed || []).length === 0) { card.removeAttribute('draggable'); }
            counts();
            say('Moved to ' + json.label + '.');
        }).catch(function () {
            card.classList.remove('is-saving');
            say('Could not reach the server. The task was not moved.');
        });
    }
})();
