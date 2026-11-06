/* Applies the saved colour theme before the page is painted (loaded in <head>, no defer) so there is no flash.
   The choice is kept in this browser only. First visit: follow the device setting, falling back to dark. */
(function () {
    'use strict';
    var theme = null;
    try { theme = window.localStorage.getItem('kis-theme'); } catch (e) { theme = null; }
    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    document.documentElement.setAttribute('data-theme', theme);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) { meta.setAttribute('content', theme === 'light' ? '#f4f6fc' : '#050818'); }
})();

/* Installed app only (home-screen / standalone window): the screen is fixed, so pinch-zoom and double-tap zoom are switched off and the page always opens at
   the true device resolution. In a normal browser tab nothing changes: visitors keep their browser's zoom (needed for accessibility). */
(function () {
    'use strict';
    var standalone = (window.matchMedia && (window.matchMedia('(display-mode: standalone)').matches || window.matchMedia('(display-mode: fullscreen)').matches || window.matchMedia('(display-mode: minimal-ui)').matches)) || window.navigator.standalone === true;
    if (!standalone) { return; }
    document.documentElement.classList.add('pwa-app');
    var vp = document.querySelector('meta[name="viewport"]');
    if (vp) { vp.setAttribute('content', 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover'); }
    // iPhone: a pinch fires gesture events, which can be cancelled. (No touchmove / touchend handler on purpose: a non-passive touch listener makes the browser wait for
    // script before every scroll movement, and touch-action in the stylesheet already blocks pinch and double-tap zoom everywhere else.)
    var stop = function (e) { e.preventDefault(); };
    ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (t) { document.addEventListener(t, stop, { passive: false }); });
    document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0')) { e.preventDefault(); } });
})();
