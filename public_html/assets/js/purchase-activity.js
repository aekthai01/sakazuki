(function () {
    'use strict';

    if (window.__purchaseActivityBooted) return;
    window.__purchaseActivityBooted = true;

    const roots = Array.from(document.querySelectorAll('[data-purchase-activity]'));
    if (roots.length === 0) return;

    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
    const constrainedConnection = Boolean(connection && connection.saveData);

    const formatTime = function (value) {
        const match = String(value || '').match(/(?:^|[ T])(\d{2}):(\d{2})(?::\d{2})?$/);
        return match ? match[1] + ':' + match[2] : '--:--';
    };

    const prepareImage = function (image) {
        if (!(image instanceof HTMLImageElement) || image.dataset.activityImageReady === '1') return;
        image.dataset.activityImageReady = '1';
        image.addEventListener('error', function () {
            image.classList.add('is-error');
            image.removeAttribute('src');
        }, { once: true });
    };

    roots.forEach(function (root) {
        if (root.dataset.activityReady === '1') return;
        root.dataset.activityReady = '1';

        const rowsHost = root.querySelector('[data-purchase-activity-rows]');
        const counter = root.querySelector('[data-purchase-activity-count]');
        const endpoint = String(root.dataset.purchaseActivityEndpoint || '').trim();
        const purchaseLabel = String(root.dataset.purchaseLabel || 'purchased');
        const emptyProduct = String(root.dataset.emptyProduct || 'Product');
        const rotationDelay = 4600;
        const normalPollDelay = constrainedConnection ? 60000 : 30000;

        let rows = Array.from(root.querySelectorAll('[data-purchase-activity-row]'));
        let activeIndex = 0;
        let rotationTimer = 0;
        let cleanupTimer = 0;
        let pollTimer = 0;
        let pollFailures = 0;
        let stopped = false;
        let activeController = null;

        root.querySelectorAll('[data-activity-image]').forEach(prepareImage);

        const updateCounter = function () {
            if (!counter) return;
            if (rows.length < 2) {
                counter.classList.add('hidden');
                counter.textContent = rows.length === 1 ? '1/1' : '0/0';
                return;
            }
            counter.classList.remove('hidden');
            counter.textContent = (activeIndex + 1) + '/' + rows.length;
        };

        const scheduleRotation = function () {
            window.clearTimeout(rotationTimer);
            if (!stopped && !document.hidden && rows.length > 1 && root.isConnected) {
                rotationTimer = window.setTimeout(showNext, rotationDelay);
            }
        };

        const showNext = function () {
            if (stopped || document.hidden || !root.isConnected || rows.length < 2) {
                scheduleRotation();
                return;
            }

            const previousRow = rows[activeIndex] || rows[0];
            activeIndex = (activeIndex + 1) % rows.length;
            const nextRow = rows[activeIndex];
            if (!previousRow || !nextRow) return;

            window.requestAnimationFrame(function () {
                previousRow.classList.remove('is-active');
                previousRow.classList.add('is-leaving');
                previousRow.setAttribute('aria-hidden', 'true');

                nextRow.classList.remove('is-leaving');
                nextRow.classList.add('is-active');
                nextRow.setAttribute('aria-hidden', 'false');
                updateCounter();

                window.clearTimeout(cleanupTimer);
                cleanupTimer = window.setTimeout(function () {
                    previousRow.classList.remove('is-leaving');
                }, 180);
            });
            scheduleRotation();
        };

        const createThumbnail = function (item) {
            const thumb = document.createElement('span');
            thumb.className = 'purchase-activity-thumb';
            thumb.setAttribute('aria-hidden', 'true');

            const fallback = document.createElement('span');
            fallback.className = 'purchase-activity-thumb-fallback';
            const fallbackIcon = document.createElement('i');
            fallbackIcon.className = 'bi bi-controller';
            fallback.appendChild(fallbackIcon);
            thumb.appendChild(fallback);

            const imageUrl = String(item.image_url || '').trim();
            if (imageUrl !== '') {
                const image = document.createElement('img');
                image.src = imageUrl;
                image.alt = '';
                image.width = 44;
                image.height = 44;
                image.decoding = 'async';
                image.loading = 'eager';
                image.referrerPolicy = 'no-referrer';
                image.dataset.activityImage = '';
                try { image.fetchPriority = 'low'; } catch (ignored) {}
                prepareImage(image);
                thumb.appendChild(image);
            }
            return thumb;
        };

        const createRow = function (item, index) {
            const row = document.createElement('div');
            row.className = 'purchase-activity-row';
            row.dataset.purchaseActivityRow = '';
            row.setAttribute('aria-hidden', index === 0 ? 'false' : 'true');
            if (index === 0) row.classList.add('is-active');

            const body = document.createElement('span');
            body.className = 'purchase-activity-body';

            const meta = document.createElement('span');
            meta.className = 'purchase-activity-meta';

            const account = document.createElement('span');
            account.className = 'purchase-activity-account';
            account.dataset.activityAccount = '';
            account.textContent = String(item.account_name || 'User');
            account.title = account.textContent;

            const action = document.createElement('span');
            action.className = 'purchase-activity-action';
            action.textContent = purchaseLabel;

            const time = document.createElement('span');
            time.className = 'purchase-activity-time';
            time.dataset.activityTime = '';
            const clock = document.createElement('i');
            clock.className = 'bi bi-clock mr-0.5';
            time.appendChild(clock);
            time.appendChild(document.createTextNode(formatTime(item.purchased_at)));

            meta.append(account, action, time);

            const productLine = document.createElement('span');
            productLine.className = 'purchase-activity-product-line';

            const product = document.createElement('span');
            product.className = 'purchase-activity-product';
            product.dataset.activityProduct = '';
            product.textContent = String(item.product_name || emptyProduct);
            product.title = product.textContent;

            const quantityValue = Math.max(1, Number.parseInt(item.quantity, 10) || 1);
            const quantity = document.createElement('span');
            quantity.className = 'purchase-activity-quantity';
            quantity.dataset.activityQuantity = '';
            quantity.textContent = '×' + quantityValue;
            if (quantityValue <= 1) quantity.classList.add('hidden');

            productLine.append(product, quantity);
            body.append(meta, productLine);
            row.append(createThumbnail(item), body);
            return row;
        };

        const renderRows = function (items, signature, announceUpdate) {
            if (!rowsHost || !Array.isArray(items)) return;
            const safeItems = items.slice(0, 10).filter(function (item) {
                return item && typeof item === 'object';
            });

            window.clearTimeout(rotationTimer);
            window.clearTimeout(cleanupTimer);
            activeIndex = 0;
            rowsHost.replaceChildren();
            safeItems.forEach(function (item, index) {
                rowsHost.appendChild(createRow(item, index));
            });
            rows = Array.from(rowsHost.querySelectorAll('[data-purchase-activity-row]'));
            root.dataset.purchaseActivitySignature = String(signature || '');

            if (rows.length === 0) {
                root.classList.add('hidden');
            } else {
                root.classList.remove('hidden');
                if (announceUpdate) {
                    root.classList.remove('activity-updated');
                    void root.offsetWidth;
                    root.classList.add('activity-updated');
                    window.setTimeout(function () {
                        root.classList.remove('activity-updated');
                    }, 760);
                }
            }
            updateCounter();
            scheduleRotation();
        };

        const schedulePoll = function (delay) {
            window.clearTimeout(pollTimer);
            if (!stopped && endpoint !== '' && root.isConnected) {
                pollTimer = window.setTimeout(poll, Math.max(1000, delay));
            }
        };

        const poll = async function () {
            if (stopped || endpoint === '' || !root.isConnected) return;
            if (document.hidden) {
                schedulePoll(normalPollDelay);
                return;
            }

            if (activeController) activeController.abort();
            activeController = typeof AbortController === 'function' ? new AbortController() : null;
            const timeoutId = window.setTimeout(function () {
                if (activeController) activeController.abort();
            }, 9000);

            try {
                const currentSignature = String(root.dataset.purchaseActivitySignature || '');
                const requestHeaders = { 'Accept': 'application/json' };
                if (currentSignature !== '') requestHeaders['If-None-Match'] = '"' + currentSignature + '"';

                const response = await fetch(endpoint, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: requestHeaders,
                    signal: activeController ? activeController.signal : undefined
                });
                if (response.status === 304) {
                    pollFailures = 0;
                    schedulePoll(normalPollDelay);
                    return;
                }
                if (response.status === 401 || response.status === 403) {
                    stopped = true;
                    return;
                }
                if (!response.ok) throw new Error('HTTP ' + response.status);

                const contentType = String(response.headers.get('content-type') || '').toLowerCase();
                if (!contentType.includes('application/json')) throw new Error('Unexpected response type');
                const payload = await response.json();
                if (!payload || payload.success !== true || !Array.isArray(payload.items)) {
                    throw new Error('Invalid activity payload');
                }

                pollFailures = 0;
                const nextSignature = String(payload.signature || '');
                if (nextSignature !== currentSignature) {
                    renderRows(payload.items, nextSignature, currentSignature !== '');
                }
                schedulePoll(normalPollDelay);
            } catch (error) {
                if (stopped) return;
                pollFailures = Math.min(pollFailures + 1, 4);
                schedulePoll(Math.min(120000, normalPollDelay * Math.pow(2, pollFailures)));
            } finally {
                window.clearTimeout(timeoutId);
                activeController = null;
            }
        };

        const onVisibilityChange = function () {
            if (document.hidden) {
                window.clearTimeout(rotationTimer);
                window.clearTimeout(pollTimer);
                return;
            }
            scheduleRotation();
            schedulePoll(750);
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('pagehide', function () {
            stopped = true;
            window.clearTimeout(rotationTimer);
            window.clearTimeout(cleanupTimer);
            window.clearTimeout(pollTimer);
            if (activeController) activeController.abort();
            document.removeEventListener('visibilitychange', onVisibilityChange);
        }, { once: true });

        updateCounter();
        scheduleRotation();
        schedulePoll(normalPollDelay);
    });
})();
