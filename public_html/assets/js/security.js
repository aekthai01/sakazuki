/**
 * Shared browser safety and motion helpers.
 *
 * Security remains server-side. This file keeps normal browser controls
 * available and adds lightweight visual feedback without disabling the site's
 * existing animations or blur effects.
 */
(function () {
    'use strict';

    if (window.__appMotionReady) return;
    window.__appMotionReady = true;

    const root = document.documentElement;

    root.classList.add('app-motion');

    const style = document.createElement('style');
    style.id = 'app-motion-style';
    style.textContent = [
        'html.app-motion { -webkit-text-size-adjust: 100%; }',
        'html.app-motion body { -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; }',
        'html.app-motion a, html.app-motion button, html.app-motion input, html.app-motion select, html.app-motion textarea { touch-action: manipulation; }',
        'html.app-motion body > main { animation: app-page-enter .15s cubic-bezier(.22,1,.36,1) both; }',
        'html.app-motion [data-instant-panel].app-panel-updated { animation: app-panel-refresh .15s cubic-bezier(.22,1,.36,1) both; }',
        'html.app-motion button, html.app-motion a { -webkit-tap-highlight-color: transparent; }',
        'html.app-motion button:not(:disabled):active, html.app-motion a:active { transform: scale(.985); }',
        'html.app-motion .drawer { backface-visibility: hidden; will-change: transform; }',
        'html.app-motion .variant-modal, html.app-motion [role="dialog"] { backface-visibility: hidden; }',
        'html.app-motion.page-hidden *, html.app-motion.page-hidden *::before, html.app-motion.page-hidden *::after { animation-play-state: paused !important; }',
        '@keyframes app-panel-refresh {',
        '  0% { opacity: .72; transform: translate3d(0, 6px, 0); }',
        '  100% { opacity: 1; transform: translate3d(0, 0, 0); }',
        '}',
        '@keyframes app-page-enter {',
        '  0% { opacity: 0; transform: translate3d(0, 10px, 0); }',
        '  100% { opacity: 1; transform: translate3d(0, 0, 0); }',
        '}',
        '@media (prefers-reduced-motion: reduce) {',
        '  html.app-motion body > main, html.app-motion [data-instant-panel].app-panel-updated { animation: none !important; }',
        '  html.app-motion .loader-text { animation: none !important; }',
        '  html.app-motion button:not(:disabled):active, html.app-motion a:active { transform: none; }',
        '  html.app-motion .purchase-activity-live-dot { animation: none !important; box-shadow: none !important; }',
        '  html.app-motion .purchase-activity-row, html.app-motion .purchase-activity-thumb img { transition: none !important; }',
        '}',
        '@media (max-width: 768px) {',
        '  html.app-motion .glass { backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }',
        '}',
        ''
    ].join('\n');
    if (!document.getElementById(style.id)) document.head.appendChild(style);


    const updateVisibility = function () {
        root.classList.toggle('page-hidden', document.hidden);
    };
    document.addEventListener('visibilitychange', updateVisibility, { passive: true });
    updateVisibility();

    const optimizeImages = function () {
        const viewportCutoff = Math.max(window.innerHeight || 0, 640) * 1.5;
        document.querySelectorAll('img').forEach(function (image) {
            if (!image.hasAttribute('decoding')) image.decoding = 'async';
            if (!image.hasAttribute('loading')) {
                const rect = image.getBoundingClientRect();
                image.loading = rect.top > viewportCutoff ? 'lazy' : 'eager';
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', optimizeImages, { once: true });
    } else {
        optimizeImages();
    }



    let navigationLoaderFallback = 0;
    let navigationLoaderHideTimer = 0;

    const buildNavigationLoader = function () {
        const loader = document.createElement('div');
        loader.id = 'site-loader';
        loader.setAttribute('aria-hidden', 'true');
        loader.classList.add('is-hiding');
        loader.innerHTML = '<div class="loader-content"><div class="loader-spinner"></div><span class="loader-text">Loading...</span></div>';
        document.body.appendChild(loader);
        return loader;
    };

    const ensureNavigationLoader = function () {
        return document.getElementById('site-loader') || buildNavigationLoader();
    };

    const hideNavigationLoader = function () {
        window.clearTimeout(navigationLoaderFallback);
        window.clearTimeout(navigationLoaderHideTimer);
        const loader = document.getElementById('site-loader');
        if (!loader) return;
        loader.classList.add('is-hiding');
        loader.setAttribute('aria-hidden', 'true');
        loader.style.setProperty('pointer-events', 'none', 'important');
        navigationLoaderHideTimer = window.setTimeout(function () {
            if (!loader.isConnected || !loader.classList.contains('is-hiding')) return;
            loader.style.setProperty('display', 'none', 'important');
        }, 180);
    };

    const showNavigationLoader = function () {
        const loader = ensureNavigationLoader();
        window.clearTimeout(navigationLoaderFallback);
        window.clearTimeout(navigationLoaderHideTimer);
        loader.style.removeProperty('display');
        loader.style.removeProperty('pointer-events');
        loader.classList.remove('is-hiding');
        loader.setAttribute('aria-hidden', 'false');
        navigationLoaderFallback = window.setTimeout(function () {
            // Always release the overlay. Download dialogs and mobile browsers can
            // temporarily hide the document without causing a real page navigation.
            // The old document.hidden guard could therefore leave the loader stuck.
            hideNavigationLoader();
        }, 8000);
    };

    window.addEventListener('pageshow', function () {
        // A completed download does not load a replacement document. Also clear any
        // stale loader when a page is restored or shown again.
        hideNavigationLoader();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) hideNavigationLoader();
    }, { passive: true });

    window.addEventListener('focus', function () {
        if (!document.hidden) window.setTimeout(hideNavigationLoader, 0);
    }, { passive: true });

    window.AppPageLoader = Object.freeze({
        show: showNavigationLoader,
        hide: hideNavigationLoader,
        ensure: ensureNavigationLoader
    });

    document.addEventListener('instantfilter:updated', function (event) {
        const selectors = event && event.detail && Array.isArray(event.detail.selectors)
            ? event.detail.selectors
            : [];
        const target = event && event.detail && event.detail.panel
            ? event.detail.panel
            : (selectors.length > 0 ? document.querySelector(selectors[0]) : document.querySelector('[data-instant-panel]'));
        if (!target) return;
        target.classList.remove('app-panel-updated');
        void target.offsetWidth;
        target.classList.add('app-panel-updated');
        window.setTimeout(function () {
            target.classList.remove('app-panel-updated');
        }, 180);
    });

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) return;
        if (link.hasAttribute('download') || link.getAttribute('target') === '_blank' || link.hasAttribute('data-no-page-loader')) {
            // File downloads do not navigate the iframe/page, so a navigation loader
            // has no future load event to dismiss it. Clear any stale one and skip it.
            hideNavigationLoader();
            return;
        }
        if (link.closest('[data-instant-panel]') || link.hasAttribute('data-instant-link')) return;
        const href = String(link.getAttribute('href') || '').trim();
        if (href === '' || href[0] === '#' || /^javascript:/i.test(href)) return;
        try {
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) return;
        } catch (ignored) {
            return;
        }
        showNavigationLoader();
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!form || event.defaultPrevented || form.hasAttribute('data-no-page-loader') || form.hasAttribute('data-instant-filter') || form.closest('[data-instant-panel]')) return;
        window.setTimeout(function () {
            if (!event.defaultPrevented) showNavigationLoader();
        }, 80);
    });

    window.addEventListener('pagehide', function () {
        window.clearTimeout(navigationLoaderFallback);
        window.clearTimeout(navigationLoaderHideTimer);
    }, { once: true });

    window.AppMotion = Object.freeze({
        nextFrame: function (callback) {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(callback);
            });
        },
        show: function (element, displayValue) {
            if (!element) return;
            element.style.display = displayValue || 'block';
            window.requestAnimationFrame(function () {
                element.classList.add('is-visible');
                element.classList.remove('is-closing');
            });
        },
        showNavigationLoader: showNavigationLoader,
        hide: function (element, delay) {
            if (!element) return;
            element.classList.remove('is-visible');
            element.classList.add('is-closing');
            window.setTimeout(function () {
                element.style.display = 'none';
                element.classList.remove('is-closing');
            }, Math.max(0, Number(delay) || 220));
        }
    });

    if (window.console && typeof window.console.info === 'function') {
        window.console.info('Security reminder: never paste commands or account secrets into the browser console.');
    }
})();
