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
