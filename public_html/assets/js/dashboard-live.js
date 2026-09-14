(function () {
    'use strict';

    if (window.__sakazukiDashboardLiveReady) return;
    window.__sakazukiDashboardLiveReady = true;

    const FRESH_MS = 15000;
    const REQUEST_TIMEOUT_MS = 30000;
    const RETRY_DELAYS = [1500, 3000, 6000, 10000, 15000];
    let requestState = null;
    let retryTimer = 0;
    let retryAttempt = 0;

    function currentRoot(scope) {
        const base = scope && scope.querySelector ? scope : document;
        if (base.matches && base.matches('[data-dashboard-dynamic-root]')) return base;
        return base.querySelector ? base.querySelector('[data-dashboard-dynamic-root]') : null;
    }

    function isFresh(root) {
        const hydrated = root && root.dataset.dashboardHydrated === '1';
        const at = root ? Number(root.dataset.dashboardValidatedAt || 0) : 0;
        return hydrated && at > 0 && (Date.now() - at) < FRESH_MS;
    }

    function endpointUrl() {
        return new URL('/user/dashboard_dynamic.php', window.location.origin);
    }

    function clearRetry() {
        window.clearTimeout(retryTimer);
        retryTimer = 0;
    }

    function setStatus(root, text) {
        if (!root || !root.isConnected) return;
        let node = root.querySelector('[data-dashboard-sync-status]');
        if (!text) {
            if (node) node.remove();
            return;
        }
        if (!node) {
            node = document.createElement('div');
            node.setAttribute('data-dashboard-sync-status', '');
            node.className = 'rounded-xl border border-amber-400/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-200';
            root.prepend(node);
        }
        node.textContent = text;
    }

    function retryText() {
        const th = !window.Lang || window.Lang.current !== 'en';
        return th ? 'เซิร์ฟเวอร์ตอบช้า กำลังลองโหลดข้อมูลอีกครั้ง…' : 'Server is responding slowly. Retrying dashboard data…';
    }

    function scheduleRetry(root, preferredDelay) {
        clearRetry();
        if (!root || !root.isConnected || document.hidden) return;
        const index = Math.min(retryAttempt, RETRY_DELAYS.length - 1);
        const delay = Math.max(800, Number(preferredDelay) || RETRY_DELAYS[index]);
        retryAttempt += 1;
        setStatus(root, retryText());
        retryTimer = window.setTimeout(function () {
            const liveRoot = currentRoot(document);
            if (!liveRoot || document.hidden) return;
            hydrate(liveRoot, true);
        }, delay);
    }

    function abortDetachedRequest() {
        if (!requestState) return;
        if (!requestState.root || !requestState.root.isConnected) {
            try { if (requestState.controller) requestState.controller.abort(); } catch (_) {}
            requestState = null;
        }
    }

    async function hydrate(root, force) {
        if (!root || !root.isConnected) return null;
        if (!force && isFresh(root)) {
            setStatus(root, '');
            return null;
        }

        // A request started for an older Dashboard DOM must never block a newly
        // mounted cached Dashboard. Abort the stale request and start one for
        // the currently connected root instead.
        if (requestState) {
            if (requestState.root === root) return requestState.promise;
            try { if (requestState.controller) requestState.controller.abort(); } catch (_) {}
            requestState = null;
        }

        clearRetry();
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timeout = window.setTimeout(function () {
            try { if (controller) controller.abort(); } catch (_) {}
        }, REQUEST_TIMEOUT_MS);

        root.dataset.dashboardSyncing = '1';
        const started = performance.now();
        const state = { root: root, controller: controller, promise: null };

        state.promise = fetch(endpointUrl().href, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Dashboard-Hydration': '1'
            },
            signal: controller ? controller.signal : undefined
        }).then(async function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const html = await response.text();
            if (!root.isConnected) return null;
            if (!html || html.trim().length < 32) throw new Error('Empty dashboard fragment');

            root.innerHTML = html;
            root.dataset.dashboardHydrated = '1';
            root.dataset.dashboardValidatedAt = String(Date.now());
            const elapsedMs = Math.round(performance.now() - started);
            root.dataset.dashboardLastMs = String(elapsedMs);
            if (window.SakazukiFastNavigation && typeof window.SakazukiFastNavigation.noteServerTiming === 'function') {
                try { window.SakazukiFastNavigation.noteServerTiming(elapsedMs); } catch (_) {}
            }
            delete root.dataset.dashboardSyncFailed;
            retryAttempt = 0;
            setStatus(root, '');

            if (window.Lang && typeof window.Lang.updatePage === 'function') {
                try { window.Lang.updatePage(); } catch (_) {}
            }
            document.dispatchEvent(new CustomEvent('dashboard:hydrated', { detail: { root: root } }));
            if (window.SakazukiFastNavigation && typeof window.SakazukiFastNavigation.persist === 'function') {
                try { window.SakazukiFastNavigation.persist(); } catch (_) {}
            }
            return root;
        }).catch(function (error) {
            const detached = !root.isConnected;
            if (!detached) {
                root.dataset.dashboardSyncFailed = '1';
                const elapsedMs = Math.round(performance.now() - started);
                if (window.SakazukiFastNavigation && typeof window.SakazukiFastNavigation.noteServerTiming === 'function') {
                    try { window.SakazukiFastNavigation.noteServerTiming(elapsedMs); } catch (_) {}
                }
                if (error && error.name === 'AbortError') console.warn('[Dashboard] dynamic data timed out or was superseded');
                else console.warn('[Dashboard] dynamic data failed', error);
                scheduleRetry(root);
            }
            return null;
        }).finally(function () {
            window.clearTimeout(timeout);
            if (root && root.isConnected) delete root.dataset.dashboardSyncing;
            if (requestState === state) requestState = null;

            // If navigation replaced the root while this request was ending,
            // immediately wake the currently visible dashboard instead of
            // leaving it with a permanent skeleton.
            const liveRoot = currentRoot(document);
            if (liveRoot && liveRoot.isConnected && !isFresh(liveRoot) && liveRoot.dataset.dashboardSyncing !== '1' && !retryTimer) {
                window.setTimeout(function () { hydrate(liveRoot, false); }, 120);
            }
        });

        requestState = state;
        return state.promise;
    }

    function boot(scope) {
        abortDetachedRequest();
        const root = currentRoot(scope);
        if (!root) return;
        if (root.dataset.dashboardSyncing === '1' && requestState && requestState.root === root) return;
        hydrate(root, false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(document); }, { once: true });
    } else {
        boot(document);
    }

    document.addEventListener('fastnav:before', function (event) {
        const detail = event && event.detail ? event.detail : {};
        const oldMain = detail.currentMain;
        if (!requestState || !oldMain || !requestState.root || !oldMain.contains(requestState.root)) return;
        try { if (requestState.controller) requestState.controller.abort(); } catch (_) {}
        requestState = null;
        clearRetry();
    });

    document.addEventListener('fastnav:after', function (event) {
        boot(event && event.detail ? event.detail.main : document);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearRetry();
            return;
        }
        const root = currentRoot(document);
        if (root && !isFresh(root)) hydrate(root, false);
    });

    window.addEventListener('pageshow', function () {
        const root = currentRoot(document);
        if (root && !isFresh(root)) hydrate(root, false);
    });
})();
