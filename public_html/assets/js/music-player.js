(function () {
  'use strict';

  var root = document.getElementById('sakazuki-music-player');
  var config = window.SAKAZUKI_MUSIC_CONFIG || null;
  if (!root || !config || !config.enabled || !Array.isArray(config.playlist) || config.playlist.length === 0) return;

  if (window.SAKAZUKI_SHELL_REDIRECTING === true) {
    try { root.remove(); } catch (_) { root.style.display = 'none'; }
    return;
  }

  // In persistent-shell mode the child page is a same-origin iframe. Keep one
  // player in the parent only; otherwise every child navigation would create a
  // duplicate UI/player and defeat the purpose of the shell.
  var embeddedSameOrigin = false;
  if (window.self !== window.top) {
    try { embeddedSameOrigin = window.parent.location.origin === window.location.origin; } catch (_) {}
  }
  if (embeddedSameOrigin) {
    try { root.remove(); } catch (_) { root.style.display = 'none'; }
    return;
  }

  var STATE_KEY = 'sakazukiMusicPlayer:v2';
  var playlist = config.playlist.filter(function (track) {
    return track && typeof track.id === 'string' && /^[A-Za-z0-9_-]{11}$/.test(track.id);
  });
  if (!playlist.length) return;

  var panel = root.querySelector('[data-music-panel]');
  var titleEl = root.querySelector('[data-music-title]');
  var statusEl = root.querySelector('[data-music-status]');
  var mainBtn = root.querySelector('[data-music-main]');
  var fabIcon = root.querySelector('[data-music-fab-icon]');
  var playBtn = root.querySelector('[data-music-play]');
  var playIcon = root.querySelector('[data-music-play-icon]');
  var prevBtn = root.querySelector('[data-music-prev]');
  var nextBtn = root.querySelector('[data-music-next]');
  var expandBtn = root.querySelector('[data-music-expand]');
  var collapseBtn = root.querySelector('[data-music-collapse]');
  var volumeInput = root.querySelector('[data-music-volume]');
  var volumeLabel = root.querySelector('[data-music-volume-label]');

  var player = null;
  var ready = false;
  var apiRequested = false;
  var apiReadyResolve = null;
  var apiReadyPromise = new Promise(function (resolve) { apiReadyResolve = resolve; });
  var lastKnownState = -1;
  var errorSkipCount = 0;
  var explicitPause = false;
  var pageLeaving = false;
  var playerStartSeconds = 0;

  function clamp(n, min, max) { return Math.max(min, Math.min(max, n)); }

  function readState() {
    var fallback = {
      index: 0,
      videoId: playlist[0].id,
      time: 0,
      volume: clamp(parseInt(config.defaultVolume, 10) || 30, 0, 100),
      desiredPlaying: false,
      interacted: false
    };
    try {
      var parsed = JSON.parse(localStorage.getItem(STATE_KEY) || 'null');
      if (!parsed || typeof parsed !== 'object') return fallback;
      var index = parseInt(parsed.index, 10);
      if (typeof parsed.videoId === 'string') {
        var found = playlist.findIndex(function (t) { return t.id === parsed.videoId; });
        if (found >= 0) index = found;
      }
      if (!Number.isInteger(index) || index < 0 || index >= playlist.length) index = 0;
      return {
        index: index,
        videoId: playlist[index].id,
        time: clamp(Number(parsed.time) || 0, 0, 86400),
        volume: Number.isFinite(Number(parsed.volume)) ? clamp(parseInt(parsed.volume, 10), 0, 100) : fallback.volume,
        desiredPlaying: parsed.desiredPlaying === true,
        interacted: parsed.interacted === true
      };
    } catch (_) {
      return fallback;
    }
  }

  var state = readState();

  function writeState(includeCurrentTime) {
    try {
      if (includeCurrentTime && ready && player && typeof player.getCurrentTime === 'function') {
        var current = Number(player.getCurrentTime());
        if (Number.isFinite(current) && current >= 0) state.time = current;
      }
      state.index = clamp(state.index, 0, playlist.length - 1);
      state.videoId = playlist[state.index].id;
      localStorage.setItem(STATE_KEY, JSON.stringify({
        index: state.index,
        videoId: state.videoId,
        time: Math.max(0, Number(state.time) || 0),
        volume: clamp(parseInt(state.volume, 10) || 0, 0, 100),
        desiredPlaying: state.desiredPlaying === true,
        interacted: state.interacted === true
      }));
    } catch (_) {}
  }

  function setStatus(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.classList.remove('is-warning', 'is-error');
    if (kind === 'warning') statusEl.classList.add('is-warning');
    if (kind === 'error') statusEl.classList.add('is-error');
  }

  function currentTrack() { return playlist[state.index] || playlist[0]; }

  function updateTrackUi() {
    var track = currentTrack();
    if (titleEl) titleEl.textContent = track.title || ('YouTube ' + track.id);
  }

  function updateVolumeUi() {
    if (volumeInput) volumeInput.value = String(state.volume);
    if (volumeLabel) volumeLabel.textContent = state.volume + '%';
  }

  function updatePlayUi(isPlaying, isLoading) {
    root.classList.toggle('is-playing', !!isPlaying);
    root.classList.toggle('is-loading', !!isLoading);
    if (playIcon) playIcon.className = isPlaying ? 'bi bi-pause-fill' : 'bi bi-play-fill';
    if (fabIcon) fabIcon.className = isLoading ? 'bi bi-arrow-repeat' : (isPlaying ? 'bi bi-pause-fill' : 'bi bi-music-note-beamed');
    if (mainBtn) mainBtn.setAttribute('aria-label', isPlaying ? 'หยุดเพลงชั่วคราว' : 'เล่นเพลง');
    if (playBtn) playBtn.setAttribute('aria-label', isPlaying ? 'หยุดเพลงชั่วคราว' : 'เล่นเพลง');
  }

  function expand(open) {
    root.classList.toggle('is-expanded', !!open);
    if (panel) panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (expandBtn) expandBtn.innerHTML = open ? '<i class="bi bi-chevron-right"></i>' : '<i class="bi bi-chevron-left"></i>';
  }

  function installYoutubeApi() {
    if (window.YT && window.YT.Player) {
      apiReadyResolve();
      return;
    }
    if (apiRequested) return;
    apiRequested = true;

    var previous = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = function () {
      if (typeof previous === 'function') {
        try { previous(); } catch (_) {}
      }
      apiReadyResolve();
    };

    var tag = document.createElement('script');
    tag.src = 'https://www.youtube.com/iframe_api';
    tag.async = true;
    tag.onerror = function () {
      apiRequested = false;
      setStatus('โหลด YouTube Player ไม่สำเร็จ', 'error');
      updatePlayUi(false, false);
    };
    document.head.appendChild(tag);
  }

  function createPlayer() {
    if (player || !window.YT || !window.YT.Player) return;

    updatePlayUi(false, true);
    var track = currentTrack();
    playerStartSeconds = Math.max(0, Math.floor(Number(state.time) || 0));

    player = new window.YT.Player('sakazuki-youtube-player', {
      width: '300',
      height: '200',
      videoId: track.id,
      playerVars: {
        autoplay: 0,
        controls: 0,
        disablekb: 1,
        fs: 0,
        playsinline: 1,
        rel: 0,
        enablejsapi: 1,
        origin: window.location.origin,
        start: playerStartSeconds
      },
      events: {
        onReady: onPlayerReady,
        onStateChange: onPlayerStateChange,
        onError: onPlayerError,
        onAutoplayBlocked: onAutoplayBlocked
      }
    });
  }

  function ensurePlayer() {
    installYoutubeApi();
    apiReadyPromise.then(createPlayer);
  }

  function onPlayerReady() {
    ready = true;
    root.classList.add('has-player');

    try {
      if (player && typeof player.setVolume === 'function') player.setVolume(state.volume);
    } catch (_) {}

    // The iframe is created with playerVars.start, so we no longer seek here.
    // Seeking after onReady/CUED was the source of the occasional audible jump.
    if (state.desiredPlaying && state.interacted) {
      explicitPause = false;
      updatePlayUi(false, true);
      setStatus('กำลังเล่นเพลงต่อ...');
      try { player.playVideo(); } catch (_) {}
    } else {
      updatePlayUi(false, false);
      setStatus(state.interacted ? 'พร้อมเล่นต่อ' : 'แตะปุ่มเล่นเพื่อเริ่มเพลง');
    }
  }

  function onPlayerStateChange(event) {
    lastKnownState = event.data;
    if (!window.YT || !window.YT.PlayerState) return;
    var S = window.YT.PlayerState;

    if (event.data === S.PLAYING) {
      errorSkipCount = 0;
      state.desiredPlaying = true;
      explicitPause = false;
      updatePlayUi(true, false);
      setStatus('กำลังเล่น');
      writeState(true);
      return;
    }

    if (event.data === S.PAUSED) {
      // Some browsers briefly pause/destroy media while leaving a page.
      // Do not overwrite the saved "keep playing" intent during navigation.
      if (pageLeaving) return;
      state.desiredPlaying = false;
      updatePlayUi(false, false);
      setStatus('หยุดชั่วคราว');
      writeState(true);
      return;
    }

    if (event.data === S.BUFFERING) {
      updatePlayUi(false, true);
      setStatus('กำลังโหลดเพลง...');
      return;
    }

    if (event.data === S.CUED) {
      updatePlayUi(false, false);
      return;
    }

    if (event.data === S.ENDED) {
      state.time = 0;
      nextTrack(true);
    }
  }

  function onAutoplayBlocked() {
    state.desiredPlaying = false;
    updatePlayUi(false, false);
    setStatus('เบราว์เซอร์บล็อกการเล่นอัตโนมัติ แตะเล่นอีกครั้ง', 'warning');
    writeState(false);
  }

  function onPlayerError() {
    errorSkipCount += 1;
    state.time = 0;
    if (errorSkipCount >= playlist.length) {
      state.desiredPlaying = false;
      updatePlayUi(false, false);
      setStatus('เพลงในรายการนี้เล่นไม่ได้ในขณะนี้', 'error');
      writeState(false);
      return;
    }
    setStatus('เพลงนี้เล่นไม่ได้ กำลังข้ามไปเพลงถัดไป', 'warning');
    nextTrack(true, true);
  }

  function loadIndex(index, shouldPlay) {
    index = clamp(index, 0, playlist.length - 1);
    state.index = index;
    state.videoId = playlist[index].id;
    state.time = 0;
    updateTrackUi();
    writeState(false);
    ensurePlayer();
    if (!ready || !player) return;

    try {
      var request = { videoId: state.videoId, startSeconds: 0 };
      if (shouldPlay) {
        state.desiredPlaying = true;
        updatePlayUi(false, true);
        player.loadVideoById(request);
      } else {
        state.desiredPlaying = false;
        player.cueVideoById(request);
      }
    } catch (_) {
      setStatus('ไม่สามารถเปลี่ยนเพลงได้', 'error');
    }
  }

  function nextTrack(autoAdvance, fromError) {
    var atEnd = state.index >= playlist.length - 1;
    if (atEnd && config.loop === false) {
      state.desiredPlaying = false;
      state.time = 0;
      updatePlayUi(false, false);
      setStatus('เล่นครบรายการแล้ว');
      writeState(false);
      return;
    }
    var next = atEnd ? 0 : state.index + 1;
    loadIndex(next, autoAdvance !== false || state.desiredPlaying === true);
    if (!fromError) errorSkipCount = 0;
  }

  function prevTrack() {
    var prev = state.index <= 0 ? playlist.length - 1 : state.index - 1;
    var shouldPlay = state.desiredPlaying || (window.YT && lastKnownState === window.YT.PlayerState.PLAYING);
    loadIndex(prev, shouldPlay);
  }

  function togglePlay() {
    state.interacted = true;
    ensurePlayer();

    if (!ready || !player) {
      state.desiredPlaying = true;
      updatePlayUi(false, true);
      setStatus('กำลังเตรียม YouTube Player...');
      writeState(false);
      return;
    }

    var isPlaying = window.YT && lastKnownState === window.YT.PlayerState.PLAYING;
    try {
      if (isPlaying) {
        explicitPause = true;
        state.desiredPlaying = false;
        player.pauseVideo();
      } else {
        explicitPause = false;
        state.desiredPlaying = true;
        updatePlayUi(false, true);
        player.playVideo();
      }
      writeState(true);
    } catch (_) {
      setStatus('ควบคุมเพลงไม่สำเร็จ กรุณาลองอีกครั้ง', 'error');
    }
  }

  function saveForNavigation() {
    if (ready && player && window.YT && lastKnownState === window.YT.PlayerState.PLAYING) {
      state.desiredPlaying = true;
    }
    writeState(true);
  }

  if (mainBtn) mainBtn.addEventListener('click', togglePlay);
  if (playBtn) playBtn.addEventListener('click', togglePlay);
  if (expandBtn) expandBtn.addEventListener('click', function () { expand(!root.classList.contains('is-expanded')); });
  if (collapseBtn) collapseBtn.addEventListener('click', function () { expand(false); });
  if (prevBtn) prevBtn.addEventListener('click', function () { state.interacted = true; prevTrack(); });
  if (nextBtn) nextBtn.addEventListener('click', function () { state.interacted = true; nextTrack(false); });

  if (volumeInput) volumeInput.addEventListener('input', function () {
    state.volume = clamp(parseInt(volumeInput.value, 10) || 0, 0, 100);
    updateVolumeUi();
    if (ready && player && typeof player.setVolume === 'function') {
      try { player.setVolume(state.volume); } catch (_) {}
    }
    writeState(false);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && root.classList.contains('is-expanded')) expand(false);
  });

  // Save slightly earlier than pagehide where possible. Android browsers are
  // not perfectly consistent about how long they keep an iframe alive while
  // navigating, so recording on navigation intent gives us a cleaner handoff.
  document.addEventListener('pointerdown', function (event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') return;
    var link = target.closest('a[href]');
    if (!link) return;
    saveForNavigation();
  }, true);

  document.addEventListener('submit', function () {
    saveForNavigation();
  }, true);

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') saveForNavigation();
  });

  window.addEventListener('pagehide', function () {
    pageLeaving = true;
    saveForNavigation();
  });

  window.addEventListener('beforeunload', function () {
    pageLeaving = true;
    saveForNavigation();
  });

  window.setInterval(function () {
    if (ready && player && state.desiredPlaying) writeState(true);
  }, 1500);

  window.SakazukiMusic = {
    playIndex: function (index) {
      var n = parseInt(index, 10);
      if (!Number.isInteger(n) || n < 0 || n >= playlist.length) return false;
      state.interacted = true;
      state.desiredPlaying = true;
      loadIndex(n, true);
      expand(true);
      return true;
    },
    toggle: togglePlay,
    next: function () { nextTrack(false); },
    previous: prevTrack,
    expand: function () { expand(true); },
    collapse: function () { expand(false); }
  };

  updateTrackUi();
  updateVolumeUi();
  updatePlayUi(false, false);

  // Start fetching the small YouTube API immediately instead of waiting up to
  // ~1.8s for requestIdleCallback. After this browser has used the music once,
  // also create/cue the iframe immediately on every next page so resume/play is
  // usually ready before the user reaches for the button.
  installYoutubeApi();
  if (state.interacted || state.desiredPlaying) {
    ensurePlayer();
  }
})();
