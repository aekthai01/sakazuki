(function () {
    'use strict';

    if (window.InstantFilterNavigation) return;

    const requestState = new Map();
    const searchTimers = new WeakMap();
    let composing = false;

    function injectStyles() {
        if (document.getElementById('instant-filter-style')) return;
        const style = document.createElement('style');
        style.id = 'instant-filter-style';
        style.textContent = `
            [data-instant-panel] { position: relative; }
            [data-instant-panel][aria-busy="true"] { pointer-events: none; }
            [data-instant-panel][aria-busy="true"]::before {
                content: "";
                position: absolute;
                inset: 0;
                z-index: 90;
                border-radius: inherit;
                background: rgba(8, 11, 20, .34);
                backdrop-filter: blur(1px);
            }
            [data-instant-panel][aria-busy="true"]::after {
                content: "";
                position: absolute;
                z-index: 91;
                top: 1rem;
                right: 1rem;
                width: 1.15rem;
                height: 1.15rem;
                border: 2px solid rgba(255,255,255,.28);
                border-top-color: rgba(255,255,255,.95);
                border-radius: 9999px;
                animation: instant-filter-spin .65s linear infinite;
            }
            @keyframes instant-filter-spin { to { transform: rotate(360deg); } }
        `;
        document.head.appendChild(style);
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
        return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    function getSelectors(source) {
        const explicit = source && source.dataset ? String(source.dataset.instantTargets || '').trim() : '';
        if (explicit) {
            return explicit.split(',').map(value => value.trim()).filter(Boolean);
        }
        const panel = source && source.closest ? source.closest('[data-instant-panel][id]') : null;
        if (panel) {
            const inherited = String(panel.dataset.instantTargets || '').trim();
            if (inherited) return inherited.split(',').map(value => value.trim()).filter(Boolean);
            return ['#' + cssEscape(panel.id)];
        }
        return [];
    }

    function isManagedForm(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        if ((form.method || 'get').toLowerCase() !== 'get') return false;
        return form.hasAttribute('data-instant-filter') || !!form.closest('[data-instant-panel]');
    }

    function cleanFormUrl(form) {
        const action = form.getAttribute('action') || window.location.href;
        const url = new URL(action, window.location.href);
        if (url.origin !== window.location.origin) return null;

        const data = new FormData(form);
        url.search = '';
        for (const [name, rawValue] of data.entries()) {
            if (typeof rawValue !== 'string') continue;
            const value = rawValue.trim();
            if (value === '') continue;
            url.searchParams.append(name, value);
        }
        return url;
    }

    function linkUrl(link) {
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#')) return null;
        if (link.hasAttribute('download') || link.target || link.hasAttribute('data-no-instant')) return null;
        const url = new URL(href, window.location.href);
        if (url.origin !== window.location.origin) return null;
        if (url.pathname !== window.location.pathname) return null;
        return url;
    }

    function rememberFocus() {
        const active = document.activeElement;
        if (!(active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement || active instanceof HTMLSelectElement)) return null;
        const panel = active.closest('[data-instant-panel]');
        if (!panel || !panel.id) return null;
        return {
            panelId: panel.id,
            id: active.id || '',
            name: active.name || '',
            start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
            end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
        };
    }

    function restoreFocus(saved) {
        if (!saved) return;
        const panel = document.getElementById(saved.panelId);
        if (!panel) return;
        let field = null;
        if (saved.id) field = panel.querySelector('#' + cssEscape(saved.id));
        if (!field && saved.name) field = panel.querySelector('[name="' + cssEscape(saved.name) + '"]');
        if (!field || typeof field.focus !== 'function') return;
        field.focus({ preventScroll: true });
        if (typeof field.setSelectionRange === 'function' && saved.start !== null && saved.end !== null) {
            try { field.setSelectionRange(saved.start, saved.end); } catch (_) {}
        }
    }

    function setBusy(selectors, busy) {
        selectors.forEach(selector => {
            const node = document.querySelector(selector);
            if (!node) return;
            if (busy) node.setAttribute('aria-busy', 'true');
            else node.removeAttribute('aria-busy');
        });
    }

    function requestKey(selectors) {
        return selectors.join('|');
    }

    async function refresh(url, selectors, options) {
        options = options || {};
        if (!url || !selectors.length) {
            if (url) window.location.assign(url.href);
            return;
        }

        const key = requestKey(selectors);
        const previous = requestState.get(key);
        if (previous) previous.abort();
        const controller = new AbortController();
        requestState.set(key, controller);

        const focus = rememberFocus();
        setBusy(selectors, true);

        try {
            const response = await fetch(url.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'follow',
                headers: {
                    'Accept': 'text/html,application/xhtml+xml',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Instant-Filter': '1'
                },
                signal: controller.signal
            });

            if (!response.ok) throw new Error('HTTP ' + response.status);
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');

            if (response.redirected && new URL(response.url).pathname !== url.pathname) {
                window.location.assign(response.url);
                return;
            }

            const replacements = [];
            for (const selector of selectors) {
                const current = document.querySelector(selector);
                const next = parsed.querySelector(selector);
                if (!current || !next) throw new Error('Missing instant-filter target: ' + selector);
                replacements.push([current, next]);
            }

            replacements.forEach(([current, next]) => {
                current.replaceWith(document.importNode(next, true));
            });

            if (parsed.title) document.title = parsed.title;
            if (window.Lang && typeof window.Lang.updatePage === 'function') {
                try { window.Lang.updatePage(); } catch (languageError) { console.warn('[InstantFilter] language refresh failed', languageError); }
            }
            const historyMethod = options.pushHistory ? 'pushState' : 'replaceState';
            window.history[historyMethod]({ instantFilter: true }, '', url.href);
            restoreFocus(focus);

            document.dispatchEvent(new CustomEvent('instantfilter:updated', {
                detail: { url: url.href, selectors: selectors.slice() }
            }));
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            console.error('[InstantFilter]', error);
            if (options.fallback !== false) window.location.assign(url.href);
        } finally {
            if (requestState.get(key) === controller) {
                requestState.delete(key);
                setBusy(selectors, false);
            }
        }
    }

    function submitManagedForm(form, options) {
        if (!isManagedForm(form)) return false;
        const selectors = getSelectors(form);
        const url = cleanFormUrl(form);
        if (!url || !selectors.length) return false;
        refresh(url, selectors, options || {});
        return true;
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!isManagedForm(form)) return;
        event.preventDefault();
        submitManagedForm(form, { pushHistory: true });
    });

    document.addEventListener('change', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement || target instanceof HTMLInputElement)) return;
        const form = target.form;
        if (!isManagedForm(form)) return;
        if (form.hasAttribute('data-instant-submit-only') || target.hasAttribute('data-instant-submit-only')) return;
        if (target instanceof HTMLInputElement && !['checkbox', 'radio', 'date', 'month', 'week'].includes(target.type)) return;
        submitManagedForm(form, { pushHistory: true });
    });

    function liveTypingEnabled(form, field) {
        if (!form || !field) return false;
        if (form.hasAttribute('data-instant-submit-only') || field.hasAttribute('data-instant-submit-only')) return false;
        return form.hasAttribute('data-instant-live-search') || field.hasAttribute('data-instant-live-search');
    }

    function liveTypingMeetsThreshold(form, field) {
        const value = String(field.value || '').trim();
        const rawMinimum = field.dataset.instantMinChars || form.dataset.instantMinChars || '2';
        const minimum = Math.max(1, Math.min(20, Number(rawMinimum) || 2));
        return value === '' || value.length >= minimum;
    }

    function clearFieldTimer(field) {
        const timer = searchTimers.get(field);
        if (timer) window.clearTimeout(timer);
        searchTimers.delete(field);
    }

    function scheduleLiveSearch(form, field) {
        if (!liveTypingEnabled(form, field) || !liveTypingMeetsThreshold(form, field)) {
            clearFieldTimer(field);
            return;
        }
        clearFieldTimer(field);
        const delay = Math.max(180, Math.min(2000, Number(field.dataset.instantDelay || form.dataset.instantDelay || 420)));
        const timer = window.setTimeout(function () {
            searchTimers.delete(field);
            if (!field.isConnected || !form.isConnected) return;
            submitManagedForm(form, { pushHistory: false });
        }, delay);
        searchTimers.set(field, timer);
    }

    document.addEventListener('compositionstart', function (event) {
        composing = true;
        const field = event.target;
        if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) clearFieldTimer(field);
    });
    document.addEventListener('compositionend', function (event) {
        composing = false;
        const field = event.target;
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return;
        const form = field.form;
        if (!isManagedForm(form)) return;
        scheduleLiveSearch(form, field);
    });

    document.addEventListener('input', function (event) {
        const field = event.target;
        if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) return;
        const form = field.form;
        if (!isManagedForm(form)) return;
        if (composing) return;
        const isSearchLike = field.type === 'search' || ['q', 'search', 'query'].includes((field.name || '').toLowerCase()) || field.hasAttribute('data-instant-search');
        if (!isSearchLike) return;
        scheduleLiveSearch(form, field);
    });

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link) return;
        const panel = link.closest('[data-instant-panel]');
        if (!panel && !link.hasAttribute('data-instant-link')) return;
        const selectors = getSelectors(link.hasAttribute('data-instant-link') ? link : panel);
        const url = linkUrl(link);
        if (!url || !selectors.length) return;
        event.preventDefault();
        refresh(url, selectors, { pushHistory: true });
    });

    window.addEventListener('popstate', function () {
        const panels = Array.from(document.querySelectorAll('[data-instant-panel][id]'));
        if (!panels.length) return;
        const selectors = panels.map(panel => '#' + cssEscape(panel.id));
        refresh(new URL(window.location.href), selectors, { fallback: true, pushHistory: false });
    });

    injectStyles();
    window.InstantFilterNavigation = { refresh, submitManagedForm };
})();
