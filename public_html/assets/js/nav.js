/**
 * Shared responsive navigation behaviour.
 *
 * Keeps drawer/logout access stable even when more menu items are added.
 * The server remains responsible for authentication and CSRF validation.
 * This file also attaches the shared presentation layer without changing any
 * purchase, stock, account, API, or security business logic.
 */
(function () {
    'use strict';

    if (window.__appNavReady) return;
    window.__appNavReady = true;

    function resolveRole() {
        const path = String(window.location.pathname || '').toLowerCase();
        if (path.indexOf('/admin/') !== -1) return 'admin';
        if (path.indexOf('/reseller/') !== -1) return 'reseller';
        if (path.indexOf('/user/') !== -1) return 'user';
        return '';
    }

    function resolvePage() {
        const path = String(window.location.pathname || '');
        const piece = path.split('/').filter(Boolean).pop() || 'dashboard.php';
        return piece.replace(/\.php$/i, '').replace(/[^a-z0-9_-]+/gi, '_').toLowerCase() || 'dashboard';
    }

    const role = resolveRole();
    const page = resolvePage();
    const drawerBreakpoint = role === 'admin' ? 1536 : 1200;

    function ensureUiStyles() {
        if (!role || document.querySelector('link[data-sakazuki-ui]')) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/assets/css/sakazuki-ui.css?v=20260925-2';
        link.setAttribute('data-sakazuki-ui', '1');
        document.head.appendChild(link);
    }

    function applyPageContext() {
        if (!document.body || !role) return;
        document.body.classList.add('sk-app-shell', 'sk-role-' + role, 'sk-page-' + page);
        document.body.setAttribute('data-sk-role', role);
        document.body.setAttribute('data-sk-page', page);
    }

    function getDrawer() {
        return document.getElementById('drawer');
    }

    function getOverlay() {
        return document.getElementById('drawerOverlay');
    }

    function dropdownButtons() {
        return Array.from(document.querySelectorAll('[data-nav-dropdown-toggle]'));
    }

    function dropdownMenus() {
        return Array.from(document.querySelectorAll('[data-nav-dropdown-menu]'));
    }

    function setDropdownState(menu, open) {
        if (!menu) return;
        menu.classList.toggle('hidden', !open);
        menu.setAttribute('aria-hidden', open ? 'false' : 'true');
        const button = dropdownButtons().find(function (candidate) {
            return candidate.getAttribute('data-nav-dropdown-toggle') === menu.id;
        });
        if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeDropdowns(exceptId) {
        dropdownMenus().forEach(function (menu) {
            if (exceptId && menu.id === exceptId) return;
            setDropdownState(menu, false);
        });
    }

    function toggleDropdown(id, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        const menu = document.getElementById(id);
        if (!menu) return false;
        const shouldOpen = menu.classList.contains('hidden');
        closeDropdowns(shouldOpen ? id : '');
        setDropdownState(menu, shouldOpen);
        return false;
    }

    function setDrawerState(open) {
        const drawer = getDrawer();
        const overlay = getOverlay();
        if (!drawer || !overlay) return;

        drawer.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.documentElement.classList.toggle('nav-drawer-open', open);
        document.body.classList.toggle('nav-drawer-open', open);

        document.querySelectorAll('[data-nav-drawer-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (open) closeDropdowns();
    }

    function openDrawer(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        setDrawerState(true);
        return false;
    }

    function closeDrawer(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        setDrawerState(false);
        return false;
    }

    function resetTransientNavState() {
        setDrawerState(false);
        closeDropdowns();
    }

    function normalizedPathname(href) {
        try {
            const url = new URL(href, window.location.href);
            return url.pathname.replace(/\/+$/, '').toLowerCase();
        } catch (_) {
            return '';
        }
    }

    function markCurrentNavigation() {
        const currentPath = normalizedPathname(window.location.href);
        document.querySelectorAll('.nav-primary-cluster nav a[href], #drawer a[href]').forEach(function (link) {
            const linkPath = normalizedPathname(link.href);
            const active = !!linkPath && linkPath === currentPath;
            link.classList.toggle('is-current', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    function mobileNavItems() {
        if (role !== 'user' && role !== 'reseller') return [];
        const isEnglish = String(document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
        return [
            {
                key: 'home',
                href: 'dashboard.php',
                icon: 'bi-house-door-fill',
                label: isEnglish ? 'Home' : 'หน้าหลัก',
                pages: ['dashboard']
            },
            {
                key: 'store',
                href: 'buy.php',
                icon: 'bi-bag-check-fill',
                label: isEnglish ? 'Store' : 'ร้านค้า',
                pages: ['buy']
            },
            {
                key: 'keys',
                href: 'mykeys.php',
                icon: 'bi-key-fill',
                label: isEnglish ? 'Keys' : 'คีย์',
                pages: ['mykeys', 'key_resets', 'key_reset_status', 'reset_hwid']
            },
            {
                key: 'account',
                href: 'account.php',
                icon: 'bi-person-circle',
                label: isEnglish ? 'Account' : 'บัญชี',
                pages: [
                    'account', 'history', 'rankings', 'deposit', 'redeem_angpao',
                    'reseller_program', 'api_store', 'api_store_download'
                ]
            }
        ];
    }

    function buildMobileNavigation() {
        if (!document.body || document.querySelector('.sk-mobile-nav')) return;
        const items = mobileNavItems();
        if (!items.length) return;

        const isEnglish = String(document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
        const nav = document.createElement('nav');
        nav.className = 'sk-mobile-nav';
        nav.setAttribute('aria-label', isEnglish ? 'Primary mobile navigation' : 'เมนูหลักบนมือถือ');

        items.forEach(function (item) {
            const link = document.createElement('a');
            link.href = item.href;
            link.className = 'sk-mobile-nav__item';
            link.setAttribute('data-sk-mobile-nav', item.key);

            const active = item.pages.indexOf(page) !== -1;
            if (active) {
                link.classList.add('is-current');
                link.setAttribute('aria-current', 'page');
            }

            const icon = document.createElement('i');
            icon.className = 'bi ' + item.icon;
            icon.setAttribute('aria-hidden', 'true');

            const label = document.createElement('span');
            label.textContent = item.label;

            link.appendChild(icon);
            link.appendChild(label);
            nav.appendChild(link);
        });

        document.body.appendChild(nav);
    }

    function improveTouchLabels() {
        document.querySelectorAll('[data-nav-drawer-toggle]').forEach(function (button) {
            if (!button.getAttribute('aria-label')) {
                button.setAttribute('aria-label', 'Open navigation menu');
            }
        });

        const drawer = getDrawer();
        if (!drawer) return;
        drawer.querySelectorAll('button').forEach(function (button) {
            const icon = button.querySelector('.bi-x, .bi-x-lg');
            if (icon && !button.getAttribute('aria-label')) button.setAttribute('aria-label', 'Close navigation menu');
        });
    }

    window.openDrawer = openDrawer;
    window.closeDrawer = closeDrawer;
    window.toggleDropdown = toggleDropdown;

    ensureUiStyles();
    applyPageContext();

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest('[data-nav-dropdown-toggle]');
        if (toggle) {
            toggleDropdown(toggle.getAttribute('data-nav-dropdown-toggle') || '', event);
            return;
        }

        if (!event.target.closest('[data-nav-dropdown-root]')) closeDropdowns();

        const overlay = event.target.closest('#drawerOverlay');
        if (overlay) {
            closeDrawer(event);
            return;
        }

        const drawerAction = event.target.closest('#drawer a[href], #drawer button[type="submit"]');
        if (drawerAction) window.setTimeout(function () { setDrawerState(false); }, 0);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeDropdowns();
        setDrawerState(false);
    });

    // Do not close menus on every resize. Mobile browsers routinely resize the
    // visual viewport when their address/navigation bars expand or collapse,
    // which previously made a freshly opened menu close itself at random.
    // Only reset when the role's actual navigation layout breakpoint changes.
    if (typeof window.matchMedia === 'function') {
        const desktopNavQuery = window.matchMedia('(min-width: ' + drawerBreakpoint + 'px)');
        const handleLayoutChange = function () { resetTransientNavState(); };
        if (typeof desktopNavQuery.addEventListener === 'function') {
            desktopNavQuery.addEventListener('change', handleLayoutChange);
        } else if (typeof desktopNavQuery.addListener === 'function') {
            desktopNavQuery.addListener(handleLayoutChange);
        }
    }

    // A normal pageshow belongs to the current navigation and must not override
    // a tap that has already opened the drawer/dropdown. Only clear stale UI
    // when the document itself was restored from the back-forward cache.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) resetTransientNavState();
        markCurrentNavigation();
    });

    document.addEventListener('DOMContentLoaded', function () {
        applyPageContext();
        const drawer = getDrawer();
        const overlay = getOverlay();
        const drawerOpen = !!(drawer && drawer.classList.contains('open'));
        if (drawer) drawer.setAttribute('aria-hidden', drawerOpen ? 'false' : 'true');
        if (overlay) overlay.setAttribute('aria-hidden', drawerOpen ? 'false' : 'true');
        dropdownMenus().forEach(function (menu) {
            setDropdownState(menu, !menu.classList.contains('hidden'));
        });
        markCurrentNavigation();
        improveTouchLabels();
        buildMobileNavigation();
    }, { once: true });
})();
