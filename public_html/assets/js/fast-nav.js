(function () {
    'use strict';

    if (window.__sakazukiFastNavigationReady) return;
    window.__sakazukiFastNavigationReady = true;

    const SAFE_PAGES = new Set(['dashboard.php', 'mykeys.php', 'history.php', 'rankings.php', 'account.php']);
    const HOST_PAGES = new Set(['dashboard.php', 'mykeys.php', 'history.php', 'rankings.php', 'account.php', 'buy.php']);
    const PERSIST_PAGES = new Set(['dashboard.php', 'mykeys.php', 'history.php', 'rankings.php']);
    const SESSION_CACHE_VERSION = 4;
    const SESSION_CACHE_LIMIT_BYTES = 3_200_000;
    const SESSION_VIEW_LIMIT_BYTES = 1_100_000;
    const FRESH_MS = Object.freeze({
        'dashboard.php': 15000,
        'mykeys.php': 12000,
        'history.php': 15000,
        'rankings.php': 30000,
        'account.php': 60000
    });
    const PRELOAD_ORDER = ['mykeys.php', 'history.php', 'rankings.php', 'account.php', 'dashboard.php'];
    const viewCache = new Map();
    const inflight = new Map();
    const network = { lastMs: 0, averageMs: 0, samples: 0, slowUntil: 0 };
    let activeKey = '';
    let activeView = null;
    let navigationSerial = 0;
    let preloadRunning = false;
    let preloadTimer = 0;
    const preloadAttempts = new Map();

    function basename(pathname) {
        const clean = String(pathname || '').replace(/\/+$/, '');
        return clean.slice(clean.lastIndexOf('/') + 1).toLowerCase();
    }

    function isUserRoute(url) {
        return url.origin === window.location.origin
            && /\/user\//i.test(url.pathname)
            && SAFE_PAGES.has(basename(url.pathname));
    }

    function currentRouteIsSafe() {
        return /\/user\//i.test(window.location.pathname)
            && SAFE_PAGES.has(basename(window.location.pathname));
    }

    function currentRouteSupportsFastNav() {
        return /\/user\//i.test(window.location.pathname)
            && HOST_PAGES.has(basename(window.location.pathname));
    }

    function currentMainNode() {
        return document.querySelector('main[data-fast-page], main[data-fast-host]');
    }

    function storageKey() {
        const scope = String(window.SAKAZUKI_CACHE_SCOPE || 'tab');
        return 'sakazuki:view-cache:v' + SESSION_CACHE_VERSION + ':' + scope;
    }

    function clearPersistedViews() {
        try { window.sessionStorage.removeItem(storageKey()); } catch (_) {}
    }

    function cleanupForeignViewCaches() {
        try {
            const keep = storageKey();
            const remove = [];
            for (let i = 0; i < window.sessionStorage.length; i += 1) {
                const key = window.sessionStorage.key(i);
                if (key && key.indexOf('sakazuki:view-cache:v') === 0 && key !== keep) remove.push(key);
            }
            remove.forEach(function (key) { window.sessionStorage.removeItem(key); });
        } catch (_) {}
    }

    function readPersistedPayload() {
        try {
            const raw = window.sessionStorage.getItem(storageKey());
            if (!raw) return { version: SESSION_CACHE_VERSION, savedAt: 0, rows: [] };
            const payload = JSON.parse(raw);
            if (!payload || payload.version !== SESSION_CACHE_VERSION || !Array.isArray(payload.rows)) {
                return { version: SESSION_CACHE_VERSION, savedAt: 0, rows: [] };
            }
            return payload;
        } catch (_) {
            return { version: SESSION_CACHE_VERSION, savedAt: 0, rows: [] };
        }
    }

    function compactHtml(html) {
        return String(html || '').replace(/>\s+</g, '><').trim();
    }

    function isBetterPreviousDashboard(row, html) {
        if (!row || typeof row.html !== 'string') return false;
        if (!/\/dashboard\.php(?:$|[?#])/i.test(String(row.url || ''))) return false;
        return row.html.indexOf('data-dashboard-hydrated="1"') !== -1
            && html.indexOf('data-dashboard-hydrated="1"') === -1;
    }

    function persistViews() {
        try {
            const previous = readPersistedPayload();
            const previousByKey = new Map((previous.rows || []).map(function (row) { return [String(row.key || ''), row]; }));
            const rows = [];
            const represented = new Set();
            let total = 0;

            const candidates = Array.from(viewCache.values())
                .filter(function (view, index, all) {
                    return view && view.main && !view.placeholder && PERSIST_PAGES.has(basename(new URL(view.url).pathname))
                        && all.findIndex(function (candidate) { return candidate && candidate.key === view.key; }) === index;
                })
                .sort(function (a, b) { return (b.lastUsedAt || 0) - (a.lastUsedAt || 0); });

            candidates.forEach(function (view) {
                if (represented.has(view.key)) return;
                const clone = view.main.cloneNode(true);
                clone.querySelectorAll('input[type="password"]').forEach(function (node) { node.value = ''; node.removeAttribute('value'); });
                const html = compactHtml(clone.outerHTML);
                const row = {
                    key: view.key, url: view.url, html: html, styles: view.styles || [], title: view.title || '',
                    bodyClass: view.bodyClass || '', navBalances: view.navBalances || [], validatedAt: view.validatedAt || 0,
                    createdAt: view.createdAt || Date.now(), lastUsedAt: view.lastUsedAt || Date.now(), scrollY: view.scrollY || 0
                };

                let chosen = row;
                const previousRow = previousByKey.get(view.key);
                if (isBetterPreviousDashboard(previousRow, html)) chosen = previousRow;

                let bytes = JSON.stringify(chosen).length;
                if (bytes > SESSION_VIEW_LIMIT_BYTES && previousRow) {
                    const previousBytes = JSON.stringify(previousRow).length;
                    if (previousBytes <= SESSION_VIEW_LIMIT_BYTES) {
                        chosen = previousRow;
                        bytes = previousBytes;
                    }
                }
                if (bytes > SESSION_VIEW_LIMIT_BYTES || total + bytes > SESSION_CACHE_LIMIT_BYTES) return;
                rows.push(chosen);
                represented.add(view.key);
                total += bytes;
            });

            // Do not destroy a previously useful cache just because the current
            // in-memory map is temporarily incomplete (for example while the
            // user is on buy.php or a preload request failed). Keep older valid
            // rows until there is a newer row for the same route or space runs out.
            (previous.rows || []).forEach(function (row) {
                const key = String(row && row.key || '');
                if (!key || represented.has(key)) return;
                let url;
                try { url = new URL(String(row.url || ''), window.location.href); } catch (_) { return; }
                if (!isUserRoute(url) || !PERSIST_PAGES.has(basename(url.pathname))) return;
                const bytes = JSON.stringify(row).length;
                if (bytes > SESSION_VIEW_LIMIT_BYTES || total + bytes > SESSION_CACHE_LIMIT_BYTES) return;
                rows.push(row);
                represented.add(key);
                total += bytes;
            });

            // setItem is atomic. If the browser quota is exhausted, the old
            // payload remains intact instead of being partially destroyed.
            window.sessionStorage.setItem(storageKey(), JSON.stringify({ version: SESSION_CACHE_VERSION, savedAt: Date.now(), rows: rows }));
        } catch (error) {
            console.warn('[FastNav] view cache persistence skipped', error);
        }
    }

    function restorePersistedViews() {
        try {
            const raw = window.sessionStorage.getItem(storageKey());
            if (!raw) return;
            const payload = JSON.parse(raw);
            if (!payload || payload.version !== SESSION_CACHE_VERSION || !Array.isArray(payload.rows)) return;
            payload.rows.forEach(function (row) {
                if (!row || typeof row.url !== 'string' || typeof row.html !== 'string') return;
                let url;
                try { url = new URL(row.url, window.location.href); } catch (_) { return; }
                if (!isUserRoute(url) || !PERSIST_PAGES.has(basename(url.pathname))) return;
                const template = document.createElement('template');
                template.innerHTML = row.html.trim();
                const main = template.content.firstElementChild;
                if (!main || !main.matches('main[data-fast-page]')) return;
                optimizeInsertedImages(main);
                const view = {
                    key: routeKey(url), url: url.href, main: main, styles: Array.isArray(row.styles) ? row.styles : [],
                    title: String(row.title || ''), bodyClass: String(row.bodyClass || ''),
                    navBalances: Array.isArray(row.navBalances) ? row.navBalances : [],
                    validatedAt: Number(row.validatedAt || 0), createdAt: Number(row.createdAt || Date.now()),
                    lastUsedAt: Number(row.lastUsedAt || 0), scrollY: Math.max(0, Number(row.scrollY || 0)), dirty: false, placeholder: false, persisted: true
                };
                viewCache.set(view.key, view);
            });
        } catch (_) { clearPersistedViews(); }
    }

    function routeKey(url) {
        const copy = new URL(url.href);
        copy.hash = '';
        return copy.href;
    }

    function pageFreshMs(url) {
        return FRESH_MS[basename(url.pathname)] || 15000;
    }

    function eligibleLink(link) {
        if (!(link instanceof HTMLAnchorElement)) return null;
        if (!currentRouteSupportsFastNav()) return null;
        if (link.hasAttribute('download') || link.hasAttribute('data-no-fast-nav') || link.hasAttribute('data-no-page-loader')) return null;
        const target = String(link.getAttribute('target') || '').trim();
        if (target && target !== '_self') return null;
        const href = String(link.getAttribute('href') || '').trim();
        if (!href || href[0] === '#' || /^javascript:/i.test(href)) return null;

        let url;
        try { url = new URL(link.href, window.location.href); } catch (_) { return null; }
        if (!isUserRoute(url)) return null;
        return url;
    }

    function extractStyles(doc) {
        return Array.from(doc.querySelectorAll('style[data-fast-page-style]')).map(function (style) {
            return style.textContent || '';
        });
    }

    function extractPersistentSlots(doc) {
        return Array.from(doc.querySelectorAll('[data-nav-balance-value]')).map(function (node) {
            return node.textContent || '';
        });
    }

    function createViewFromDocument(doc, url, validatedAt) {
        const sourceMain = doc.querySelector('main[data-fast-page]');
        if (!sourceMain) throw new Error('Fast page marker missing');
        const main = document.importNode(sourceMain, true);
        optimizeInsertedImages(main);
        return {
            key: routeKey(url),
            url: url.href,
            main: main,
            styles: extractStyles(doc),
            title: doc.title || document.title,
            bodyClass: doc.body ? doc.body.className : document.body.className,
            navBalances: extractPersistentSlots(doc),
            validatedAt: validatedAt || Date.now(),
            createdAt: Date.now(),
            lastUsedAt: Date.now(),
            scrollY: 0,
            dirty: false,
            placeholder: false
        };
    }

    function createCurrentView() {
        const main = document.querySelector('main[data-fast-page]');
        if (!main) return null;
        const url = new URL(window.location.href);
        const view = {
            key: routeKey(url),
            url: url.href,
            main: main,
            styles: extractStyles(document),
            title: document.title,
            bodyClass: document.body.className,
            navBalances: extractPersistentSlots(document),
            validatedAt: Date.now(),
            createdAt: Date.now(),
            lastUsedAt: Date.now(),
            scrollY: window.scrollY || 0,
            dirty: false,
            placeholder: false
        };
        viewCache.set(view.key, view);
        persistViews();
        activeKey = view.key;
        activeView = view;
        return view;
    }

    function mountPersistedDashboardOnInitialLoad() {
        if (basename(window.location.pathname) !== 'dashboard.php') return false;
        const url = new URL(window.location.href);
        const key = routeKey(url);
        const cached = viewCache.get(key);
        const liveMain = document.querySelector('main[data-fast-page]');
        if (!cached || !cached.persisted || !cached.main || !liveMain) return false;

        const cachedRoot = cached.main.querySelector('[data-dashboard-dynamic-root]');
        const liveRoot = liveMain.querySelector('[data-dashboard-dynamic-root]');
        const cachedHydrated = cachedRoot && cachedRoot.dataset.dashboardHydrated === '1';
        const liveHydrated = liveRoot && liveRoot.dataset.dashboardHydrated === '1';
        if (!cachedHydrated || liveHydrated) return false;

        applyStyles(cached.styles);
        syncPersistentSlots(cached);
        document.title = cached.title || document.title;
        document.body.className = cached.bodyClass || document.body.className;
        liveMain.replaceWith(cached.main);
        activeView = cached;
        activeKey = cached.key;
        cached.lastUsedAt = Date.now();
        updateActiveNav(url);
        window.scrollTo({ top: Math.max(0, Number(cached.scrollY) || 0), left: 0, behavior: 'auto' });
        document.dispatchEvent(new CustomEvent('fastnav:after', {
            detail: { url: url.href, main: cached.main, cached: true, restoredInitial: true }
        }));
        return true;
    }

    function recordNetwork(ms) {
        const safe = Math.max(0, Number(ms) || 0);
        network.lastMs = safe;
        network.samples += 1;
        network.averageMs = network.samples === 1 ? safe : ((network.averageMs * 0.72) + (safe * 0.28));
        if (safe >= 5000) network.slowUntil = Date.now() + 15000;
        else if (safe >= 2500) network.slowUntil = Math.max(network.slowUntil, Date.now() + 6000);
    }

    function serverLooksSlow() {
        return Date.now() < network.slowUntil || network.averageMs >= 2800;
    }

    async function fetchView(url, purpose, forceFresh) {
        const key = routeKey(url);
        if (!forceFresh) {
            const cached = viewCache.get(key);
            if (cached && !cached.placeholder) return cached;
        }
        if (inflight.has(key)) return inflight.get(key);

        const started = performance.now();
        let networkRecorded = false;
        const request = fetch(url.href, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'follow',
            headers: {
                'Accept': 'text/html,application/xhtml+xml',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Fast-Navigation': String(purpose || 'navigate')
            }
        }).then(async function (response) {
            recordNetwork(performance.now() - started);
            networkRecorded = true;
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const finalUrl = new URL(response.url || url.href, window.location.href);
            if (!isUserRoute(finalUrl)) throw new Error('Unsafe redirect');
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const view = createViewFromDocument(doc, finalUrl, Date.now());
            viewCache.set(view.key, view);
            if (view.key !== key) viewCache.set(key, view);
            persistViews();
            return view;
        }).catch(function (error) {
            if (!networkRecorded) recordNetwork(performance.now() - started);
            throw error;
        }).finally(function () {
            inflight.delete(key);
        });

        inflight.set(key, request);
        return request;
    }

    function progressNode() {
        let node = document.getElementById('fast-nav-progress');
        if (node) return node;
        node = document.createElement('div');
        node.id = 'fast-nav-progress';
        node.setAttribute('aria-hidden', 'true');
        node.innerHTML = '<span></span>';
        document.body.appendChild(node);
        return node;
    }

    function setProgress(active) {
        progressNode().classList.toggle('is-active', !!active);
    }

    function applyStyles(styleTexts) {
        document.querySelectorAll('style[data-fast-page-style]').forEach(function (style) { style.remove(); });
        (styleTexts || []).forEach(function (text) {
            const style = document.createElement('style');
            style.setAttribute('data-fast-page-style', '');
            style.textContent = text;
            document.head.appendChild(style);
        });
    }

    function syncPersistentSlots(view) {
        if (!view || !Array.isArray(view.navBalances) || view.navBalances.length === 0) return;
        const currentBalances = Array.from(document.querySelectorAll('[data-nav-balance-value]'));
        currentBalances.forEach(function (node, index) {
            const next = view.navBalances[index] || view.navBalances[0];
            if (typeof next === 'string' && next !== '') node.textContent = next;
        });
    }

    function updateActiveNav(url) {
        const targetPage = basename(url.pathname);
        document.querySelectorAll('a[href]').forEach(function (link) {
            let linkUrl;
            try { linkUrl = new URL(link.href, window.location.href); } catch (_) { return; }
            if (!/\/user\//i.test(linkUrl.pathname)) return;
            const active = basename(linkUrl.pathname) === targetPage;
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    function closeNavigationChrome() {
        document.documentElement.classList.remove('nav-drawer-open');
        document.body.classList.remove('nav-drawer-open');
        document.querySelectorAll('.drawer.open').forEach(function (node) { node.classList.remove('open'); });
        document.querySelectorAll('.drawer-overlay.open').forEach(function (node) { node.classList.remove('open'); });
        document.querySelectorAll('[data-nav-dropdown-menu]').forEach(function (node) {
            node.classList.add('hidden');
            node.setAttribute('aria-hidden', 'true');
        });
        document.querySelectorAll('[data-nav-dropdown-toggle]').forEach(function (node) { node.setAttribute('aria-expanded', 'false'); });
    }

    function optimizeInsertedImages(scope) {
        if (!scope || !scope.querySelectorAll) return;
        scope.querySelectorAll('img').forEach(function (image) {
            if (!image.hasAttribute('decoding')) image.decoding = 'async';
            if (!image.hasAttribute('loading')) image.loading = 'lazy';
        });
    }

    function saveActiveView() {
        if (!activeView || !activeView.main) return;
        activeView.scrollY = Math.max(0, window.scrollY || 0);
        activeView.lastUsedAt = Date.now();
        const liveMain = document.querySelector('main[data-fast-page]');
        if (liveMain && !liveMain.hasAttribute('data-fast-loading')) activeView.main = liveMain;
        viewCache.set(activeView.key, activeView);
        persistViews();
    }

    function mountView(view, targetUrl, options) {
        options = options || {};
        const currentMain = currentMainNode();
        if (!currentMain || !view || !view.main) throw new Error('Fast page container missing');

        saveActiveView();
        document.dispatchEvent(new CustomEvent('fastnav:before', {
            detail: { from: window.location.href, to: targetUrl.href, currentMain: currentMain, cached: true }
        }));

        applyStyles(view.styles);
        syncPersistentSlots(view);
        document.title = view.title || document.title;
        document.body.className = view.bodyClass || document.body.className;

        const replacement = view.main;
        replacement.removeAttribute('data-fast-no-enter');
        currentMain.replaceWith(replacement);
        activeView = view;
        activeKey = view.key;
        view.lastUsedAt = Date.now();
        view.placeholder = false;
        closeNavigationChrome();
        updateActiveNav(targetUrl);

        if (window.Lang && typeof window.Lang.updatePage === 'function') {
            try { window.Lang.updatePage(); } catch (error) { console.warn('[FastNav] language update failed', error); }
        }

        if (options.history !== false) {
            window.history.pushState({ fastNavigation: true }, '', targetUrl.href);
        }

        const restore = options.restoreScroll !== false;
        const scrollTop = restore ? Math.max(0, Number(view.scrollY) || 0) : 0;
        window.scrollTo({ top: scrollTop, left: 0, behavior: 'auto' });

        document.dispatchEvent(new CustomEvent('fastnav:after', {
            detail: { url: targetUrl.href, main: replacement, cached: true }
        }));
        schedulePreload(220);
    }

    function loadingView(targetUrl, label) {
        const main = document.createElement('main');
        main.setAttribute('data-fast-page', '');
        main.setAttribute('data-fast-loading', '');
        main.className = 'fast-view-loading';
        const safeLabel = String(label || '').trim() || targetUrl.pathname.split('/').pop().replace(/\.php$/i, '');
        const heading = document.createElement('div');
        heading.className = 'fast-view-loading-heading';
        heading.textContent = safeLabel;
        const body = document.createElement('div');
        body.className = 'fast-view-loading-body';
        body.innerHTML = '<span></span><span></span><span></span><span></span>';
        main.append(heading, body);
        return main;
    }

    function mountLoading(targetUrl, label, options) {
        options = options || {};
        const currentMain = currentMainNode();
        if (!currentMain) return;
        saveActiveView();
        document.dispatchEvent(new CustomEvent('fastnav:before', {
            detail: { from: window.location.href, to: targetUrl.href, currentMain: currentMain, cached: false }
        }));
        const placeholder = loadingView(targetUrl, label);
        currentMain.replaceWith(placeholder);
        activeView = null;
        activeKey = routeKey(targetUrl);
        closeNavigationChrome();
        updateActiveNav(targetUrl);
        if (options.history !== false) window.history.pushState({ fastNavigation: true }, '', targetUrl.href);
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    }

    function shouldRevalidate(view, url) {
        if (!view || view.placeholder) return true;
        return (Date.now() - (view.validatedAt || 0)) > pageFreshMs(url);
    }

    function userIsEditing(view) {
        if (!view || !view.main) return false;
        if (view.dirty) return true;
        if (view.main.querySelector('#detailModalOverlay:not(.hidden), [role="dialog"]:not(.hidden)')) return true;
        const active = document.activeElement;
        return !!(active && view.main.isConnected && view.main.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/i.test(active.tagName));
    }

    async function revalidate(url, expectedView) {
        const key = routeKey(url);
        try {
            const fresh = await fetchView(url, 'revalidate', true);
            const currentIsTarget = activeKey === key && currentMainNode();
            if (!currentIsTarget) return fresh;
            if (expectedView && userIsEditing(expectedView)) return fresh;

            const oldMain = currentMainNode();
            if (!oldMain) return fresh;
            const oldScroll = window.scrollY || 0;
            document.dispatchEvent(new CustomEvent('fastnav:before-refresh', {
                detail: { url: url.href, currentMain: oldMain }
            }));
            applyStyles(fresh.styles);
            syncPersistentSlots(fresh);
            document.title = fresh.title || document.title;
            document.body.className = fresh.bodyClass || document.body.className;
            fresh.main.setAttribute('data-fast-no-enter', '');
            oldMain.replaceWith(fresh.main);
            fresh.scrollY = oldScroll;
            activeView = fresh;
            activeKey = fresh.key;
            window.scrollTo({ top: oldScroll, left: 0, behavior: 'auto' });
            if (window.Lang && typeof window.Lang.updatePage === 'function') {
                try { window.Lang.updatePage(); } catch (_) {}
            }
            document.dispatchEvent(new CustomEvent('fastnav:after', {
                detail: { url: url.href, main: fresh.main, cached: false, refreshed: true }
            }));
            return fresh;
        } catch (error) {
            console.warn('[FastNav] background sync failed', error);
            return null;
        }
    }

    async function navigate(url, options) {
        options = options || {};
        const key = routeKey(url);
        const serial = ++navigationSerial;
        const cached = viewCache.get(key);
        if (window.AppPageLoader && typeof window.AppPageLoader.hide === 'function') window.AppPageLoader.hide();

        if (cached && !cached.placeholder) {
            try {
                mountView(cached, url, options);
            } catch (error) {
                console.error('[FastNav]', error);
                window.location.assign(url.href);
                return;
            }
            if (shouldRevalidate(cached, url)) revalidate(url, cached);
            return;
        }

        mountLoading(url, options.label || '', options);
        const progressDelay = window.setTimeout(function () {
            if (serial === navigationSerial) setProgress(true);
        }, 180);

        try {
            const fresh = await fetchView(url, 'navigate', true);
            if (serial !== navigationSerial) return;
            const currentMain = currentMainNode();
            if (!currentMain) throw new Error('Fast page container missing');
            applyStyles(fresh.styles);
            syncPersistentSlots(fresh);
            document.title = fresh.title || document.title;
            document.body.className = fresh.bodyClass || document.body.className;
            currentMain.replaceWith(fresh.main);
            activeView = fresh;
            activeKey = fresh.key;
            updateActiveNav(url);
            if (window.Lang && typeof window.Lang.updatePage === 'function') {
                try { window.Lang.updatePage(); } catch (_) {}
            }
            document.dispatchEvent(new CustomEvent('fastnav:after', {
                detail: { url: url.href, main: fresh.main, cached: false }
            }));
            schedulePreload(220);
        } catch (error) {
            console.error('[FastNav]', error);
            window.location.assign(url.href);
        } finally {
            window.clearTimeout(progressDelay);
            if (serial === navigationSerial) window.setTimeout(function () { setProgress(false); }, 80);
        }
    }

    function prefetch(url, purpose) {
        if (!currentRouteSupportsFastNav() || !isUserRoute(url)) return Promise.resolve(null);
        const key = routeKey(url);
        const cached = viewCache.get(key);
        if (cached && !cached.placeholder) return Promise.resolve(cached);
        return fetchView(url, purpose || 'prefetch', true).catch(function () { return null; });
    }

    function routeUrlForPage(page) {
        return new URL(page, window.location.href);
    }

    function preloadDelay() {
        if (network.lastMs >= 5000 || serverLooksSlow()) return 1400;
        if (network.lastMs >= 2500) return 700;
        if (network.lastMs >= 1200) return 300;
        return 70;
    }

    async function waitUntilVisible() {
        if (!document.hidden) return;
        await new Promise(function (resolve) {
            const resume = function () {
                if (document.hidden) return;
                document.removeEventListener('visibilitychange', resume);
                resolve();
            };
            document.addEventListener('visibilitychange', resume);
        });
    }

    function missingPreloadPages() {
        return PRELOAD_ORDER.filter(function (page) {
            const url = routeUrlForPage(page);
            const cached = viewCache.get(routeKey(url));
            return routeKey(url) !== activeKey && (!cached || cached.placeholder);
        });
    }

    async function preloadWorker(queue) {
        while (queue.length) {
            await waitUntilVisible();
            const page = queue.shift();
            if (!page) return;
            const url = routeUrlForPage(page);
            const key = routeKey(url);
            const cached = viewCache.get(key);
            if (key === activeKey || (cached && !cached.placeholder)) {
                preloadAttempts.delete(page);
                continue;
            }

            const result = await prefetch(url, 'preload');
            if (result) preloadAttempts.delete(page);
            else preloadAttempts.set(page, Math.min(8, (preloadAttempts.get(page) || 0) + 1));
            await new Promise(function (resolve) { window.setTimeout(resolve, preloadDelay()); });
        }
    }

    function retryWarmDelay(missing) {
        const attempts = missing.reduce(function (max, page) { return Math.max(max, preloadAttempts.get(page) || 0); }, 0);
        if (serverLooksSlow()) return Math.min(15000, 2500 + (attempts * 1800));
        return Math.min(10000, 1200 + (attempts * 1200));
    }

    async function preloadSafeViews() {
        if (preloadRunning || !currentRouteSupportsFastNav()) return;
        const queue = missingPreloadPages();
        if (!queue.length) return;

        preloadRunning = true;
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        const constrained = !!(connection && (connection.saveData || /(?:2g|slow-2g)/i.test(String(connection.effectiveType || ''))));
        // Until we have at least one real response-time sample, warm one page at
        // a time. This avoids competing with the first Dashboard hydration on a
        // busy shared host. Once the server proves responsive we can use two.
        const workerCount = constrained || serverLooksSlow() || network.samples === 0 ? 1 : 2;
        try {
            await Promise.all(Array.from({ length: workerCount }, function () { return preloadWorker(queue); }));
            persistViews();
        } finally {
            preloadRunning = false;
        }

        const missing = missingPreloadPages();
        if (missing.length) schedulePreload(retryWarmDelay(missing));
    }

    function schedulePreload(delay) {
        window.clearTimeout(preloadTimer);
        preloadTimer = window.setTimeout(function () {
            preloadTimer = 0;
            preloadSafeViews();
        }, Math.max(40, Number(delay) || 100));
    }

    document.addEventListener('input', function (event) {
        if (!activeView || !activeView.main || !activeView.main.contains(event.target)) return;
        if (/^(INPUT|TEXTAREA|SELECT)$/i.test(event.target.tagName)) activeView.dirty = true;
    }, true);

    document.addEventListener('change', function (event) {
        if (!activeView || !activeView.main || !activeView.main.contains(event.target)) return;
        activeView.dirty = true;
    }, true);

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        const url = eligibleLink(link);
        if (!url) return;
        if (routeKey(url) === activeKey) {
            event.preventDefault();
            closeNavigationChrome();
            return;
        }
        event.preventDefault();
        const label = String(link.textContent || '').replace(/\s+/g, ' ').trim();
        navigate(url, { history: true, restoreScroll: true, label: label });
    }, true);

    ['pointerenter', 'focusin', 'touchstart', 'pointerdown'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
            const url = eligibleLink(link);
            if (url) prefetch(url, 'intent');
        }, { capture: true, passive: true });
    });

    window.addEventListener('popstate', function () {
        const url = new URL(window.location.href);
        if (!isUserRoute(url)) return;
        navigate(url, { history: false, restoreScroll: true, label: '' });
    });

    window.addEventListener('pagehide', persistViews);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) schedulePreload(120);
    });
    window.addEventListener('online', function () { schedulePreload(80); });
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = String(form.getAttribute('action') || '');
        if (/logout\.php/i.test(action)) clearPersistedViews();
    }, true);

    const style = document.createElement('style');
    style.id = 'fast-nav-style';
    style.textContent = [
        '#fast-nav-progress{position:fixed;left:0;right:0;top:0;height:2px;z-index:10050;pointer-events:none;opacity:0;overflow:hidden;transition:opacity .08s ease}',
        '#fast-nav-progress.is-active{opacity:1}',
        '#fast-nav-progress span{display:block;width:34%;height:100%;background:linear-gradient(90deg,#3b82f6,#8b5cf6,#22d3ee);transform:translateX(-110%)}',
        '#fast-nav-progress.is-active span{animation:fast-nav-progress 1s cubic-bezier(.22,1,.36,1) infinite}',
        '@keyframes fast-nav-progress{0%{transform:translateX(-110%)}70%{transform:translateX(210%)}100%{transform:translateX(310%)}}',
        'main[data-fast-page]:not([data-fast-loading]):not([data-fast-no-enter]){animation:fast-nav-enter .10s cubic-bezier(.22,1,.36,1) both}',
        '@keyframes fast-nav-enter{from{opacity:.88;transform:translate3d(0,2px,0)}to{opacity:1;transform:none}}',
        '.fast-view-loading{min-height:calc(100dvh - 88px);padding:1.25rem;max-width:72rem;margin:0 auto;width:100%}',
        '.fast-view-loading-heading{font-size:1.2rem;font-weight:700;color:#e5e7eb;margin:.25rem 0 1rem}',
        '.fast-view-loading-body{display:grid;gap:.75rem}',
        '.fast-view-loading-body span{display:block;height:76px;border-radius:14px;background:linear-gradient(100deg,rgba(255,255,255,.045) 20%,rgba(255,255,255,.09) 35%,rgba(255,255,255,.045) 50%);background-size:240% 100%;animation:fast-nav-shimmer 1.1s linear infinite}',
        '.fast-view-loading-body span:nth-child(2){height:112px}.fast-view-loading-body span:nth-child(3){height:92px}.fast-view-loading-body span:nth-child(4){height:132px}',
        '@keyframes fast-nav-shimmer{to{background-position-x:-240%}}',
        'a[aria-current="page"]{background:rgba(59,130,246,.10);color:#60a5fa}',
        '@media(prefers-reduced-motion:reduce){#fast-nav-progress span,main[data-fast-page],.fast-view-loading-body span{animation:none!important}}'
    ].join('');
    if (!document.getElementById(style.id)) document.head.appendChild(style);

    function bootFastNavigation() {
        cleanupForeignViewCaches();
        restorePersistedViews();
        if (currentRouteIsSafe() && !activeView) {
            if (!mountPersistedDashboardOnInitialLoad()) createCurrentView();
        } else if (!activeKey) {
            activeKey = routeKey(new URL(window.location.href));
        }
        updateActiveNav(new URL(window.location.href));
        schedulePreload(80);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootFastNavigation, { once: true });
    } else {
        bootFastNavigation();
    }

    window.SakazukiFastNavigation = Object.freeze({
        navigate: navigate,
        prefetch: prefetch,
        cacheSize: function () { return viewCache.size; },
        clearCache: function () { viewCache.clear(); clearPersistedViews(); },
        persist: persistViews,
        networkState: function () {
            return { lastMs: network.lastMs, averageMs: network.averageMs, slow: serverLooksSlow() };
        },
        warmState: function () {
            return {
                running: preloadRunning,
                missing: missingPreloadPages(),
                attempts: Object.fromEntries(Array.from(preloadAttempts.entries())),
                cached: Array.from(viewCache.keys())
            };
        },
        noteServerTiming: function (ms) {
            recordNetwork(ms);
            schedulePreload(120);
        }
    });
})();
