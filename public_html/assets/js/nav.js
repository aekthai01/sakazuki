/**
 * Shared responsive navigation behaviour.
 *
 * Keeps drawer/logout access stable even when more menu items are added.
 * The server remains responsible for authentication and CSRF validation.
 */
(function () {
    'use strict';

    if (window.__appNavReady) return;
    window.__appNavReady = true;

    const drawerBreakpoint = 1536;

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

    window.openDrawer = openDrawer;
    window.closeDrawer = closeDrawer;
    window.toggleDropdown = toggleDropdown;

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
    // Only reset when the CSS navigation layout actually crosses the 2xl
    // desktop breakpoint.
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
    });

    document.addEventListener('DOMContentLoaded', function () {
        const drawer = getDrawer();
        const overlay = getOverlay();
        const drawerOpen = !!(drawer && drawer.classList.contains('open'));
        if (drawer) drawer.setAttribute('aria-hidden', drawerOpen ? 'false' : 'true');
        if (overlay) overlay.setAttribute('aria-hidden', drawerOpen ? 'false' : 'true');
        dropdownMenus().forEach(function (menu) {
            setDropdownState(menu, !menu.classList.contains('hidden'));
        });
    }, { once: true });
})();
