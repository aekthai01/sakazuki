(function () {
  'use strict';

  var cfg = window.SAKAZUKI_APP_SHELL_CONFIG || {};
  var frame = document.getElementById('sakazuki-app-frame');
  if (!frame) return;

  var role = typeof cfg.role === 'string' ? cfg.role : 'user';
  var defaultRoute = typeof cfg.defaultRoute === 'string' ? cfg.defaultRoute : (role + '/buy.php');
  var shellPath = typeof cfg.shellPath === 'string' && cfg.shellPath.charAt(0) === '/' ? cfg.shellPath : '/app.php';
  var lastRoute = typeof cfg.initialRoute === 'string' && cfg.initialRoute !== '' ? cfg.initialRoute : defaultRoute;

  function normalizeRoleRoute(raw) {
    if (typeof raw !== 'string' || raw.length < 1 || raw.length > 3072) return '';
    var url;
    try { url = new URL(raw, window.location.origin); } catch (_) { return ''; }
    if (url.origin !== window.location.origin) return '';

    var path = url.pathname.replace(/^\/+/, '');
    if (path.indexOf('..') !== -1 || path.indexOf('%') !== -1 || path.indexOf('//') !== -1) return '';
    var pattern = /^(?:user|reseller|admin)\/[A-Za-z0-9_-]+\.php$/;
    if (!pattern.test(path)) return '';
    return path + url.search + url.hash;
  }

  function encodeShellRoute(route) {
    return encodeURIComponent(route).replace(/%2F/gi, '/');
  }

  function promoteAuthPage(url) {
    var path = url.pathname.replace(/^\/+/, '');
    if (path === 'login.php' || path === 'logout.php' || path === 'register.php'
      || path === 'forgot_password.php' || path === 'reset_password.php') {
      window.location.replace(url.pathname + url.search + url.hash);
      return true;
    }
    return false;
  }

  function syncFromUrl(rawUrl, title) {
    var url;
    try { url = new URL(rawUrl, window.location.origin); } catch (_) { return; }
    if (url.origin !== window.location.origin) return;
    if (promoteAuthPage(url)) return;

    var route = normalizeRoleRoute(url.href);
    if (!route) return;
    lastRoute = route;

    var shellUrl = shellPath + '?path=' + encodeShellRoute(route);
    var current = window.location.pathname + window.location.search;
    if (current !== shellUrl) {
      // Child navigations already create browser history entries. Replacing the
      // current parent URL keeps Back/Forward natural without adding a duplicate
      // history entry for every click.
      window.history.replaceState({ sakazukiShell: true, route: route }, '', shellUrl);
    }
    if (typeof title === 'string' && title.trim() !== '') document.title = title.trim();
  }

  function syncFromFrame() {
    try {
      if (!frame.contentWindow || frame.contentWindow.location.origin !== window.location.origin) return;
      syncFromUrl(frame.contentWindow.location.href, frame.contentDocument ? frame.contentDocument.title : '');
    } catch (_) {}
  }

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
    var data = event.data;
    if (!data || data.type !== 'sakazuki:shell-route') return;
    syncFromUrl(data.url || '', data.title || '');
  });

  frame.addEventListener('load', syncFromFrame);

  // If the browser restores this shell from bfcache, make the address bar
  // reflect the child that was actually restored rather than stale state.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) window.setTimeout(syncFromFrame, 0);
  });

  // Record a valid initial state without adding a history entry.
  window.history.replaceState({ sakazukiShell: true, route: lastRoute || defaultRoute }, '',
    shellPath + '?path=' + encodeShellRoute(lastRoute || defaultRoute));

  window.SakazukiAppShell = Object.freeze({
    sync: syncFromFrame,
    currentRoute: function () { return lastRoute; }
  });
})();
