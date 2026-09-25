/**
 * Store V2 progressive presentation layer.
 *
 * Keeps the existing server-rendered purchase buttons in the DOM as the source
 * of truth for stock, pricing, CSRF/order flow, and inventory polling. The
 * visible mobile storefront is enhanced into compact cards with a package
 * picker without changing purchase business logic.
 */
(function () {
    'use strict';

    if (window.__sakazukiStoreV2Booted) {
        if (window.SakazukiStoreV2 && typeof window.SakazukiStoreV2.sync === 'function') {
            window.SakazukiStoreV2.sync();
        }
        return;
    }
    window.__sakazukiStoreV2Booted = true;

    const state = {
        activeCard: null,
        returnFocus: null,
        selectedVariantId: 0,
        wrapRetry: 0
    };
    const cardObservers = new WeakMap();
    const scrollStates = new WeakMap();

    function isStorePage() {
        return !!(document.body && document.body.classList.contains('sk-page-buy'));
    }

    function isEnglish() {
        return String(document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
    }

    function text(th, en) {
        return isEnglish() ? en : th;
    }

    function queryDirectVariantButtons(card) {
        if (!card) return [];
        return Array.from(card.querySelectorAll('button[data-inventory-source][data-inventory-variant-id]'));
    }

    function visibleVariantButtons(card) {
        return queryDirectVariantButtons(card).filter(function (button) {
            return !button.disabled;
        });
    }

    function productName(card) {
        const heading = card ? card.querySelector('h3') : null;
        return heading ? String(heading.textContent || '').trim() : '';
    }

    function packageLabelFromSource(source, card) {
        if (!source) return '';
        const normal = source.querySelector('[data-inventory-normal]');
        const labelNode = normal ? normal.querySelector('span:first-child') : null;
        let label = labelNode ? String(labelNode.textContent || '').replace(/\s+/g, ' ').trim() : '';
        const name = productName(card);
        if (name && label.toLowerCase().indexOf((name + ' - ').toLowerCase()) === 0) {
            label = label.slice(name.length + 3).trim();
        }
        return label || text('แพ็กเกจ', 'Package');
    }

    function currencyTokens(source) {
        const normal = source ? source.querySelector('[data-inventory-normal]') : null;
        const priceNode = normal ? normal.querySelector('.text-right') : null;
        const raw = priceNode ? String(priceNode.textContent || '') : '';
        return raw.match(/(?:฿|\$)\s*[\d,]+(?:\.\d{1,2})?/g) || [];
    }

    function priceInfo(source) {
        const tokens = currencyTokens(source);
        const token = tokens.length ? tokens[tokens.length - 1].replace(/\s+/g, '') : '';
        const numeric = token ? Number(token.replace(/[^\d.]/g, '')) : NaN;
        return { token: token, numeric: numeric };
    }

    function lowestAvailablePrice(card) {
        const candidates = visibleVariantButtons(card).map(function (button) {
            return priceInfo(button);
        }).filter(function (entry) {
            return entry.token && Number.isFinite(entry.numeric);
        });
        if (!candidates.length) return '';
        candidates.sort(function (a, b) { return a.numeric - b.numeric; });
        return candidates[0].token;
    }

    function sourceContainer(card) {
        const first = queryDirectVariantButtons(card)[0];
        return first ? first.parentElement : null;
    }

    function makeElement(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined && content !== null) node.textContent = String(content);
        return node;
    }

    function refreshCardState(card) {
        if (!card) return;
        const sourceButtons = queryDirectVariantButtons(card);
        const cta = card.querySelector('[data-sk-package-open]');
        if (!cta || !sourceButtons.length) return;

        const available = sourceButtons.filter(function (button) { return !button.disabled; });
        const title = cta.querySelector('[data-sk-package-title]');
        const meta = cta.querySelector('[data-sk-package-meta]');
        const price = cta.querySelector('[data-sk-package-price]');
        const icon = cta.querySelector('[data-sk-package-icon]');

        if (!available.length) {
            cta.disabled = true;
            cta.classList.add('is-sold-out');
            if (title) title.textContent = text('สินค้าหมดชั่วคราว', 'Temporarily sold out');
            if (meta) meta.textContent = text('ไม่มีแพ็กเกจพร้อมขาย', 'No package currently available');
            if (price) price.textContent = '';
            if (icon) icon.className = 'bi bi-slash-circle';
            return;
        }

        cta.disabled = false;
        cta.classList.remove('is-sold-out');
        if (title) title.textContent = text('เลือกแพ็กเกจ', 'Choose package');
        if (meta) meta.textContent = text(
            available.length + ' รูปแบบพร้อมขาย',
            available.length + (available.length === 1 ? ' package available' : ' packages available')
        );
        const lowest = lowestAvailablePrice(card);
        if (price) price.textContent = lowest ? text('เริ่ม ', 'From ') + lowest : '';
        if (icon) icon.className = 'bi bi-chevron-right';
    }

    function installCardObserver(card, container) {
        if (!card || !container || cardObservers.has(card)) return;
        const observer = new MutationObserver(function () {
            refreshCardState(card);
            if (state.activeCard === card) renderPackagePicker(card, state.selectedVariantId);
        });
        observer.observe(container, {
            subtree: true,
            attributes: true,
            attributeFilter: ['disabled', 'data-inventory-stock', 'hidden', 'class']
        });
        cardObservers.set(card, observer);
    }

    function openProduct(card, trigger) {
        const available = visibleVariantButtons(card);
        if (!available.length) return;

        state.activeCard = card;
        state.returnFocus = trigger || null;
        const first = available[0];
        first.click();
    }

    function prepareProductCard(card) {
        if (!card) return;
        const sourceButtons = queryDirectVariantButtons(card);
        if (!sourceButtons.length) {
            card.classList.add('sk-store-card-v2', 'sk-store-card-soldout');
            return;
        }

        const container = sourceContainer(card);
        if (!container) return;
        container.classList.add('sk-variant-source');
        container.setAttribute('aria-hidden', 'true');

        let cta = card.querySelector('[data-sk-package-open]');
        if (!cta) {
            cta = makeElement('button', 'sk-package-cta');
            cta.type = 'button';
            cta.setAttribute('data-sk-package-open', '1');

            const copy = makeElement('span', 'sk-package-cta__copy');
            const title = makeElement('strong', 'sk-package-cta__title');
            title.setAttribute('data-sk-package-title', '1');
            const meta = makeElement('span', 'sk-package-cta__meta');
            meta.setAttribute('data-sk-package-meta', '1');
            copy.appendChild(title);
            copy.appendChild(meta);

            const right = makeElement('span', 'sk-package-cta__right');
            const price = makeElement('span', 'sk-package-cta__price');
            price.setAttribute('data-sk-package-price', '1');
            const icon = makeElement('i', 'bi bi-chevron-right');
            icon.setAttribute('data-sk-package-icon', '1');
            icon.setAttribute('aria-hidden', 'true');
            right.appendChild(price);
            right.appendChild(icon);

            cta.appendChild(copy);
            cta.appendChild(right);
            cta.addEventListener('click', function () {
                openProduct(card, cta);
            });
            container.insertAdjacentElement('afterend', cta);
        }

        card.classList.add('sk-store-card-v2');
        installCardObserver(card, container);
        refreshCardState(card);
    }

    function installDragGuard(scroller) {
        if (!scroller || scrollStates.has(scroller)) return;
        const drag = { active: false, moved: false, x: 0, y: 0, pointerId: null };
        scrollStates.set(scroller, drag);

        scroller.addEventListener('pointerdown', function (event) {
            if (event.pointerType !== 'touch' && event.pointerType !== 'pen') return;
            drag.active = true;
            drag.moved = false;
            drag.x = event.clientX;
            drag.y = event.clientY;
            drag.pointerId = event.pointerId;
        }, { passive: true });

        scroller.addEventListener('pointermove', function (event) {
            if (!drag.active || event.pointerId !== drag.pointerId) return;
            if (Math.abs(event.clientX - drag.x) > 7 || Math.abs(event.clientY - drag.y) > 7) {
                drag.moved = true;
            }
        }, { passive: true });

        const finish = function (event) {
            if (drag.active && event.pointerId === drag.pointerId) drag.active = false;
        };
        scroller.addEventListener('pointerup', finish, { passive: true });
        scroller.addEventListener('pointercancel', finish, { passive: true });

        scroller.addEventListener('click', function (event) {
            if (!drag.moved) return;
            drag.moved = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);
    }

    function prepareScrollers() {
        document.querySelectorAll('.category-filter').forEach(function (scroller) {
            scroller.classList.add('sk-horizontal-scroller');
            installDragGuard(scroller);
        });
    }

    function decorateModuleOrder() {
        const main = document.querySelector('main');
        const storefront = document.getElementById('storefrontLiveCatalog');
        if (!main || !storefront) return;

        const filterPanel = storefront.querySelector(':scope > .mb-6');
        if (filterPanel) {
            filterPanel.classList.add('sk-store-filter-panel');
            if (filterPanel.parentElement === storefront) main.insertBefore(filterPanel, storefront);
        }

        const activity = main.querySelector('[data-purchase-activity]');
        const recentWrapper = activity ? (activity.closest('.mb-4') || activity) : null;
        if (recentWrapper && recentWrapper.parentElement === main) {
            recentWrapper.classList.add('sk-store-recent');
            main.insertBefore(recentWrapper, storefront);
        }

        const inventory = document.getElementById('inventoryRefreshBanner');
        if (inventory && inventory.parentElement === main) {
            inventory.classList.add('sk-store-inventory');
            main.insertBefore(inventory, storefront);
        }

        const marqueeTrack = main.querySelector('[data-announcement-marquee]');
        const marqueeCard = marqueeTrack ? marqueeTrack.closest('.glass') : null;
        if (marqueeCard) marqueeCard.classList.add('sk-store-announcement');
    }

    function decorateTopbar() {
        const titleNode = document.querySelector('.brand-title-text');
        if (!titleNode) return;
        if (!titleNode.hasAttribute('data-sk-original-title')) {
            titleNode.setAttribute('data-sk-original-title', String(titleNode.textContent || ''));
        }
        titleNode.textContent = text('ร้านค้า', 'Store');

        const balance = document.querySelector('.nav-actions [data-lang="nav.balance"]');
        const balanceBox = balance ? balance.closest('div') : null;
        if (balanceBox) balanceBox.classList.add('sk-store-balance-source');
    }

    function restoreTopbar() {
        const titleNode = document.querySelector('.brand-title-text[data-sk-original-title]');
        if (titleNode) titleNode.textContent = titleNode.getAttribute('data-sk-original-title') || 'Sakazuki';
        document.querySelectorAll('.sk-store-balance-source').forEach(function (node) {
            node.classList.remove('sk-store-balance-source');
        });
    }

    function ensurePickerSection() {
        const modal = document.getElementById('quantityModal');
        if (!modal) return null;

        let section = modal.querySelector('[data-sk-package-section]');
        if (section) return section;

        const body = modal.querySelector('.p-5.pt-3') || modal.lastElementChild;
        if (!body) return null;

        section = makeElement('section', 'sk-package-picker');
        section.setAttribute('data-sk-package-section', '1');

        const headingRow = makeElement('div', 'sk-package-picker__heading');
        const heading = makeElement('span', 'sk-package-picker__title', text('เลือกแพ็กเกจ', 'Choose package'));
        const count = makeElement('span', 'sk-package-picker__count');
        count.setAttribute('data-sk-package-count', '1');
        headingRow.appendChild(heading);
        headingRow.appendChild(count);

        const list = makeElement('div', 'sk-package-picker__list');
        list.setAttribute('data-sk-package-list', '1');
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', text('รูปแบบสินค้า', 'Product packages'));

        section.appendChild(headingRow);
        section.appendChild(list);
        body.insertBefore(section, body.firstChild);
        return section;
    }

    function renderPackagePicker(card, selectedVariantId) {
        if (!card || !card.isConnected) return;
        const section = ensurePickerSection();
        if (!section) return;
        const list = section.querySelector('[data-sk-package-list]');
        const countNode = section.querySelector('[data-sk-package-count]');
        if (!list) return;

        const sources = queryDirectVariantButtons(card);
        list.replaceChildren();
        if (countNode) {
            countNode.textContent = text(sources.length + ' รูปแบบ', sources.length + (sources.length === 1 ? ' option' : ' options'));
        }

        sources.forEach(function (source) {
            const variantId = parseInt(source.dataset.inventoryVariantId || '0', 10) || 0;
            const option = makeElement('button', 'sk-package-option');
            option.type = 'button';
            option.disabled = !!source.disabled;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', variantId === Number(selectedVariantId || 0) ? 'true' : 'false');
            if (variantId === Number(selectedVariantId || 0)) option.classList.add('is-selected');

            const copy = makeElement('span', 'sk-package-option__copy');
            const label = makeElement('strong', 'sk-package-option__label', packageLabelFromSource(source, card));
            const status = makeElement(
                'span',
                'sk-package-option__status',
                source.disabled ? text('สินค้าหมดชั่วคราว', 'Temporarily sold out') : text('พร้อมสั่งซื้อ', 'Available')
            );
            copy.appendChild(label);
            copy.appendChild(status);

            const price = makeElement('span', 'sk-package-option__price');
            const info = priceInfo(source);
            price.textContent = info.token || '';

            option.appendChild(copy);
            option.appendChild(price);
            option.addEventListener('click', function () {
                if (source.disabled) return;
                state.activeCard = card;
                source.click();
            });
            list.appendChild(option);
        });
    }

    function interceptQuantityAutofocus(original, args) {
        const input = document.getElementById('quantityInput');
        const modal = document.getElementById('quantityModal');
        let previousFocus = null;
        let intercepted = false;

        if (input && typeof input.focus === 'function') {
            try {
                previousFocus = input.focus;
                input.focus = function () {
                    if (modal && typeof HTMLElement !== 'undefined') {
                        HTMLElement.prototype.focus.call(modal, { preventScroll: true });
                    }
                };
                intercepted = true;
            } catch (_) {}
        }

        let result;
        try {
            result = original.apply(window, args);
        } finally {
            window.requestAnimationFrame(function () {
                if (intercepted && input && previousFocus) {
                    try { input.focus = previousFocus; } catch (_) {}
                }
                if (input && document.activeElement === input) input.blur();
                if (modal) {
                    try { modal.focus({ preventScroll: true }); } catch (_) {}
                }
            });
        }
        return result;
    }

    function wrapSelectVariant() {
        const original = window.selectVariant;
        if (typeof original !== 'function') {
            if (state.wrapRetry < 20) {
                state.wrapRetry += 1;
                window.setTimeout(wrapSelectVariant, 50);
            }
            return;
        }
        if (original.__skStoreV2Wrapped) return;

        function wrappedSelectVariant() {
            const args = Array.from(arguments);
            const trigger = args[6];
            const card = trigger && trigger.closest ? trigger.closest('.product-item') : state.activeCard;
            if (card) state.activeCard = card;
            state.selectedVariantId = Number(args[1] || 0);

            const result = interceptQuantityAutofocus(original, args);
            if (state.returnFocus && state.returnFocus.isConnected) {
                window.purchaseModalReturnFocus = state.returnFocus;
            }
            if (state.activeCard) renderPackagePicker(state.activeCard, state.selectedVariantId);
            return result;
        }

        wrappedSelectVariant.__skStoreV2Wrapped = true;
        wrappedSelectVariant.__skStoreV2Original = original;
        window.selectVariant = wrappedSelectVariant;
    }

    function syncCards() {
        document.querySelectorAll('.product-item').forEach(prepareProductCard);
    }

    function teardown() {
        if (document.body) document.body.classList.remove('sk-store-v2');
        restoreTopbar();
    }

    function sync() {
        if (!isStorePage()) {
            teardown();
            return;
        }
        document.body.classList.add('sk-store-v2');
        decorateTopbar();
        decorateModuleOrder();
        prepareScrollers();
        syncCards();
        ensurePickerSection();
        state.wrapRetry = 0;
        wrapSelectVariant();
    }

    window.SakazukiStoreV2 = Object.freeze({ sync: sync });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sync, { once: true });
    } else {
        sync();
    }

    document.addEventListener('fastnav:after', function () {
        window.requestAnimationFrame(sync);
    });
})();
