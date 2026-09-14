(function () {
  'use strict';

  var cfg = window.SAKAZUKI_SHELL_BRIDGE || {};
  var eligible = cfg.eligible === true;

  function isSameOriginParent() {
    if (window.self === window.top) return false;
    try { return window.parent.location.origin === window.location.origin; } catch (_) { return false; }
  }

  function currentUrl() {
    return window.location.pathname + window.location.search + window.location.hash;
  }

  function notifyParent() {
    if (!isSameOriginParent()) return;
    try {
      window.parent.postMessage({
        type: 'sakazuki:shell-route',
        url: window.location.href,
        title: document.title || ''
      }, window.location.origin);
    } catch (_) {}
  }

  if (isSameOriginParent()) {
    document.documentElement.classList.add('sakazuki-shell-child');
    window.SAKAZUKI_SHELL_CHILD = true;

    // Keep the parent address bar in sync even when a page changes its URL with
    // history.pushState/replaceState (for example instant filters) and no iframe
    // load event occurs.
    try {
      var nativePushState = window.history.pushState.bind(window.history);
      var nativeReplaceState = window.history.replaceState.bind(window.history);
      window.history.pushState = function () {
        var result = nativePushState.apply(window.history, arguments);
        notifyParent();
        return result;
      };
      window.history.replaceState = function () {
        var result = nativeReplaceState.apply(window.history, arguments);
        notifyParent();
        return result;
      };
    } catch (_) {}

    window.addEventListener('DOMContentLoaded', notifyParent, { once: true });
    window.addEventListener('popstate', notifyParent);
    window.addEventListener('hashchange', notifyParent);
    window.addEventListener('pageshow', notifyParent);
    return;
  }

  if (!eligible) return;

  // Emergency compatibility escape hatch: opening a role page with ?__shell=0
  // keeps the legacy full-page behavior and the legacy per-page music player.
  try {
    if (new URLSearchParams(window.location.search).get('__shell') === '0') return;
  } catch (_) {}

  var path = window.location.pathname.replace(/^\/+/, '');
  if (!/^(?:user|reseller|admin)\/[A-Za-z0-9_-]+\.php$/.test(path)) return;

  var route = path + window.location.search + window.location.hash;
  var encodedRoute = encodeURIComponent(route).replace(/%2F/gi, '/');
  // Stop the legacy per-page player from starting during the very short
  // direct-page -> shell handoff. The persistent parent will own playback.
  window.SAKAZUKI_SHELL_REDIRECTING = true;
  window.location.replace('/app.php?path=' + encodedRoute);
})();
