(function () {
    'use strict';

    const registry = new WeakMap();

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function createController(container) {
        const track = container.querySelector('[data-announcement-track]');
        if (!track) return null;

        let animation = null;
        let restartTimer = 0;
        let resizeObserver = null;
        let mutationObserver = null;
        let destroyed = false;

        const speed = clamp(Number(container.getAttribute('data-marquee-speed')) || 52, 24, 140);

        function cancelAnimation() {
            if (animation) {
                try { animation.cancel(); } catch (ignored) {}
                animation = null;
            }
            track.style.removeProperty('transform');
        }

        function start() {
            if (destroyed || document.visibilityState === 'hidden') return;

            cancelAnimation();

            const containerWidth = Math.ceil(container.getBoundingClientRect().width);
            const trackWidth = Math.ceil(track.getBoundingClientRect().width);
            if (containerWidth < 2 || trackWidth < 2) {
                container.dataset.marqueeState = 'waiting';
                return;
            }

            const distance = containerWidth + trackWidth;
            const duration = clamp((distance / speed) * 1000, 7000, 90000);

            if (typeof track.animate !== 'function') {
                // Old-browser fallback. Modern Android Chrome uses the Web
                // Animations path above, which avoids CSS keyframe collisions.
                track.style.setProperty('--marquee-start', containerWidth + 'px');
                track.style.setProperty('--marquee-end', (-trackWidth) + 'px');
                track.style.setProperty('--marquee-duration', duration + 'ms');
                track.classList.add('announcement-marquee-fallback');
                container.dataset.marqueeState = 'running';
                return;
            }

            track.classList.remove('announcement-marquee-fallback');
            animation = track.animate([
                { transform: 'translate3d(' + containerWidth + 'px,0,0)' },
                { transform: 'translate3d(' + (-trackWidth) + 'px,0,0)' }
            ], {
                duration: duration,
                iterations: Infinity,
                easing: 'linear',
                fill: 'both'
            });
            container.dataset.marqueeState = 'running';
        }

        function scheduleRestart(delay) {
            window.clearTimeout(restartTimer);
            restartTimer = window.setTimeout(start, typeof delay === 'number' ? delay : 80);
        }

        function pause() {
            if (animation && animation.playState === 'running') {
                animation.pause();
                container.dataset.marqueeState = 'paused';
            }
        }

        function resume() {
            if (animation && animation.playState === 'paused') {
                animation.play();
                container.dataset.marqueeState = 'running';
            }
        }

        container.addEventListener('pointerenter', pause);
        container.addEventListener('pointerleave', resume);
        container.addEventListener('focusin', pause);
        container.addEventListener('focusout', resume);

        if ('ResizeObserver' in window) {
            resizeObserver = new ResizeObserver(function () { scheduleRestart(120); });
            resizeObserver.observe(container);
            resizeObserver.observe(track);
        } else {
            window.addEventListener('resize', function () { scheduleRestart(160); }, { passive: true });
        }

        if ('MutationObserver' in window) {
            mutationObserver = new MutationObserver(function () { scheduleRestart(60); });
            mutationObserver.observe(track, { childList: true, subtree: true, characterData: true });
        }

        function handleVisibility() {
            if (document.visibilityState === 'hidden') {
                pause();
            } else if (animation) {
                resume();
            } else {
                scheduleRestart(20);
            }
        }
        document.addEventListener('visibilitychange', handleVisibility);

        const startWhenReady = function () {
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(function () { scheduleRestart(0); }).catch(function () { scheduleRestart(0); });
            } else {
                scheduleRestart(0);
            }
        };
        startWhenReady();

        return {
            restart: scheduleRestart,
            destroy: function () {
                destroyed = true;
                window.clearTimeout(restartTimer);
                cancelAnimation();
                if (resizeObserver) resizeObserver.disconnect();
                if (mutationObserver) mutationObserver.disconnect();
                document.removeEventListener('visibilitychange', handleVisibility);
            }
        };
    }

    function init(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-announcement-marquee]').forEach(function (container) {
            if (registry.has(container)) return;
            const controller = createController(container);
            if (controller) registry.set(container, controller);
        });
    }

    window.refreshAnnouncementMarquees = function () {
        document.querySelectorAll('[data-announcement-marquee]').forEach(function (container) {
            const controller = registry.get(container);
            if (controller) controller.restart(0);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    } else {
        init(document);
    }
})();
