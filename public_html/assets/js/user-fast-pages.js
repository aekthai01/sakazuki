(function () {
    'use strict';

    if (window.__sakazukiUserFastPagesReady) return;
    window.__sakazukiUserFastPagesReady = true;

    function copyText(text) {
        if (window.Lang && typeof window.Lang.copy === 'function') {
            window.Lang.copy(String(text || ''));
            return;
        }
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(String(text || '')).catch(function () {});
    }

    const helperStyle = document.createElement('style');
    helperStyle.id = 'user-fast-pages-style';
    helperStyle.textContent = '.show-more-text{cursor:pointer}';
    if (!document.getElementById(helperStyle.id)) document.head.appendChild(helperStyle);

    window.copyToClipboard = copyText;

    window.openDetailModal = function (productName, date, keys, price, downloadUrl) {
        const overlay = document.getElementById('detailModalOverlay');
        const modal = document.getElementById('detailModal');
        if (!overlay || !modal) return;

        const productNode = document.getElementById('modalProductName');
        const dateNode = document.getElementById('modalDate');
        const keysNode = document.getElementById('modalKeys');
        const priceNode = document.getElementById('modalPrice');
        const downloadNode = document.getElementById('modalDownloadBtn');
        if (productNode) productNode.textContent = String(productName || '');
        if (dateNode) dateNode.textContent = String(date || '');
        if (priceNode) priceNode.textContent = String(price || '');

        window.currentModalKeys = String(keys || '');
        if (keysNode) {
            keysNode.replaceChildren();
            window.currentModalKeys.split('\n').forEach(function (keyText) {
                const row = document.createElement('div');
                row.className = 'mb-1';
                row.textContent = keyText;
                keysNode.appendChild(row);
            });
        }

        if (downloadNode) {
            const hasDownload = String(downloadUrl || '').trim() !== '';
            if (hasDownload) downloadNode.href = downloadUrl;
            downloadNode.classList.toggle('hidden', !hasDownload);
            downloadNode.classList.toggle('flex', hasDownload);
        }

        window.historyModalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        overlay.setAttribute('aria-hidden', 'false');
        const firstFocusable = modal.querySelector('button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])');
        (firstFocusable || modal).focus({ preventScroll: true });
    };

    window.closeDetailModal = function () {
        const overlay = document.getElementById('detailModalOverlay');
        if (!overlay) return;
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        overlay.setAttribute('aria-hidden', 'true');
        if (window.historyModalReturnFocus instanceof HTMLElement && window.historyModalReturnFocus.isConnected) {
            window.historyModalReturnFocus.focus({ preventScroll: true });
        }
    };

    function successState(button, originalHtml) {
        if (!button) return;
        const message = window.Lang && Lang.current === 'en' ? 'Copied!' : 'คัดลอกแล้ว';
        button.innerHTML = '<i class="bi bi-check2"></i> <span>' + message + '</span>';
        button.classList.replace('bg-[#22c55e]', 'bg-[#16a34a]');
        window.setTimeout(function () {
            if (!button.isConnected) return;
            button.innerHTML = originalHtml;
            button.classList.replace('bg-[#16a34a]', 'bg-[#22c55e]');
        }, 2000);
    }

    window.copyModalKeys = function () {
        const button = document.getElementById('modalCopyBtn');
        if (!button) return;
        const originalHtml = button.innerHTML;
        if (window.Lang && typeof window.Lang.copy === 'function') {
            window.Lang.copy(String(window.currentModalKeys || ''), function (success) {
                if (success) successState(button, originalHtml);
            });
        } else {
            copyText(window.currentModalKeys || '');
            successState(button, originalHtml);
        }
    };

    document.addEventListener('click', function (event) {
        const fieldButton = event.target.closest('[data-copy-value]');
        if (fieldButton) {
            copyText(fieldButton.dataset.copyValue || '');
            return;
        }

        const keyButton = event.target.closest('.copy-key-btn');
        if (keyButton) {
            copyText(keyButton.dataset.key || '');
            return;
        }

        const more = event.target.closest('.show-more-text');
        if (more) {
            const container = more.closest('.key-container');
            if (!container) return;
            container.classList.toggle('show-keys');
            if (container.classList.contains('show-keys')) more.style.display = 'none';
            return;
        }

        if (event.target && event.target.id === 'detailModalOverlay') window.closeDetailModal();
    });


    document.addEventListener('fastnav:before', function () {
        const overlay = document.getElementById('detailModalOverlay');
        if (overlay && !overlay.classList.contains('hidden')) window.closeDetailModal();
    });
    document.addEventListener('keydown', function (event) {
        const overlay = document.getElementById('detailModalOverlay');
        if (!overlay || overlay.classList.contains('hidden')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            window.closeDetailModal();
            return;
        }
        if (event.key !== 'Tab') return;
        const modal = document.getElementById('detailModal');
        if (!modal) return;
        const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) {
            return node.offsetParent !== null;
        });
        if (focusable.length === 0) {
            event.preventDefault();
            modal.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
