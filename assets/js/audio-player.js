/**
 * پلیر استریم فایل‌های صوتی خریداری‌شده (ووکامرس)
 *
 * لیست پخش از دکمه‌های .ct-audio-play موجود در صفحه ساخته می‌شود؛ پلیر چسبان
 * پایین صفحه همه‌ی ترک‌ها را کنترل می‌کند. بدون وابستگی به jQuery.
 */
(function () {
    'use strict';

    var config = window.cloudtartAudioPlayer || {};
    var i18n = config.i18n || {};

    function t(key, fallback) {
        return typeof i18n[key] === 'string' ? i18n[key] : fallback;
    }

    function sprintf(template, value) {
        return String(template).replace(/%[sd]/, value);
    }

    // اسکریپت در فوتر چاپ می‌شود، ولی ممکن است قالب یا افزونه‌های بهینه‌سازی آن را جابه‌جا
    // (به head) منتقل کنند؛ پس همیشه منتظر آماده شدن DOM می‌ماند.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {

    // منوی راست‌کلیک روی ترک‌ها و پلیر بسته می‌شود تا "ذخیره‌ی صدا" در دسترس نباشد.
    Array.prototype.forEach.call(document.querySelectorAll('.ct-audio-track, .ct-audio-player'), function (el) {
        el.addEventListener('contextmenu', function (event) {
            event.preventDefault();
        });
    });

    var root = document.getElementById('ct-audio-player');
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.ct-audio-play'));

    if (!root || buttons.length === 0 || root.getAttribute('data-ready') === '1') {
        return;
    }
    root.setAttribute('data-ready', '1');

    var audio = root.querySelector('audio');
    var mini = document.getElementById('ct-audio-mini');
    var els = {
        title: root.querySelector('.ct-ap-title'),
        product: root.querySelector('.ct-ap-product'),
        toggle: root.querySelector('.ct-ap-toggle'),
        prev: root.querySelector('.ct-ap-prev'),
        next: root.querySelector('.ct-ap-next'),
        seek: root.querySelector('.ct-ap-seek'),
        fill: root.querySelector('.ct-ap-fill'),
        buffer: root.querySelector('.ct-ap-buffer'),
        timeCurrent: root.querySelector('.ct-ap-time-current'),
        timeDuration: root.querySelector('.ct-ap-time-duration'),
        mute: root.querySelector('.ct-ap-mute'),
        volume: root.querySelector('.ct-ap-volume-slider'),
        listToggle: root.querySelector('.ct-ap-list-toggle'),
        count: root.querySelector('.ct-ap-count'),
        playlist: root.querySelector('.ct-ap-playlist'),
        message: root.querySelector('.ct-ap-message'),
        close: root.querySelector('.ct-ap-close')
    };

    // یک فایل که در چند سفارش خریده شده چند دکمه دارد ولی فقط یک ترک در لیست پخش است.
    var tracks = [];
    var trackByKey = {};
    var buttonTrack = [];

    buttons.forEach(function (button) {
        var key = button.getAttribute('data-key') || button.getAttribute('data-src');
        var track = trackByKey[key];
        if (!track) {
            track = {
                index: tracks.length,
                src: button.getAttribute('data-src'),
                title: button.getAttribute('data-title') || '',
                product: button.getAttribute('data-product') || '',
                buttons: [],
                item: null
            };
            trackByKey[key] = track;
            tracks.push(track);
        }
        track.buttons.push(button);
        buttonTrack.push(track.index);
    });

    var current = -1;
    var isSeeking = false;
    var storageKey = 'cloudtartAudioVolume';

    /* ---------------------------------------------------------------- */

    function formatTime(seconds) {
        if (!isFinite(seconds) || seconds < 0) {
            return '0:00';
        }
        var total = Math.floor(seconds);
        var h = Math.floor(total / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        var mm = h > 0 && m < 10 ? '0' + m : String(m);
        var ss = s < 10 ? '0' + s : String(s);
        return (h > 0 ? h + ':' : '') + mm + ':' + ss;
    }

    function showMessage(text) {
        if (!els.message) {
            return;
        }
        els.message.textContent = text;
        els.message.hidden = !text;
    }

    function openPlayer() {
        root.hidden = false;
        root.classList.add('is-open');
        document.body.classList.add('ct-audio-player-open');
        if (mini) {
            mini.hidden = true;
        }
    }

    /**
     * «بستن» فقط پلیر را کوچک می‌کند: پخش ادامه دارد و پلیر به دکمه‌ی شناور پایین صفحه
     * تبدیل می‌شود (برای توقف، کاربر دکمه‌ی توقف را می‌زند).
     */
    function minimizePlayer() {
        root.classList.remove('is-open');
        root.hidden = true;
        document.body.classList.remove('ct-audio-player-open');
        if (mini) {
            mini.hidden = false;
            mini.focus({ preventScroll: true });
        }
    }

    function restorePlayer() {
        openPlayer();
        if (els.toggle) {
            els.toggle.focus({ preventScroll: true });
        }
    }

    function setPlayingState(playing) {
        root.classList.toggle('is-playing', playing);
        if (mini) {
            mini.classList.toggle('is-playing', playing);
        }
        if (els.toggle) {
            els.toggle.setAttribute('aria-label', playing ? t('pause', 'Pause') : t('play', 'Play'));
        }

        tracks.forEach(function (track) {
            var active = track.index === current;
            var isPlaying = active && playing;

            track.buttons.forEach(function (button) {
                button.classList.toggle('is-active', active);
                button.classList.toggle('is-playing', isPlaying);
                button.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
                button.setAttribute(
                    'aria-label',
                    sprintf(isPlaying ? t('pauseTrack', 'Pause %s') : t('playTrack', 'Play %s'), track.title)
                );
            });

            if (track.item) {
                track.item.classList.toggle('is-active', active);
                track.item.classList.toggle('is-playing', isPlaying);
                track.item.setAttribute('aria-current', active ? 'true' : 'false');
            }
        });
    }

    function load(index, autoplay) {
        if (index < 0 || index >= tracks.length) {
            return;
        }

        var track = tracks[index];
        var changed = index !== current;
        current = index;

        if (changed) {
            audio.src = track.src;
            audio.load();
            els.title.textContent = track.title;
            els.product.textContent = track.product;
            els.fill.style.width = '0%';
            els.buffer.style.width = '0%';
            els.seek.value = 0;
            els.timeCurrent.textContent = '0:00';
            els.timeDuration.textContent = '0:00';
            showMessage('');
            updateMediaSession(track);
            if (mini) {
                mini.setAttribute('title', t('openPlayer', 'Open audio player') + ' — ' + track.title);
            }
        }

        openPlayer();
        setPlayingState(!audio.paused && !changed);

        if (autoplay) {
            play();
        }
    }

    function play() {
        var promise = audio.play();
        if (promise && typeof promise.catch === 'function') {
            promise.catch(function (error) {
                setPlayingState(false);
                // AbortError یعنی پخش با انتخاب ترک دیگر قطع شد؛ خطا نیست.
                if (error && error.name !== 'AbortError' && error.name !== 'NotAllowedError') {
                    showMessage(t('error', 'This track could not be played.'));
                }
            });
        }
    }

    function togglePlayback() {
        if (current === -1) {
            load(0, true);
            return;
        }
        if (audio.paused) {
            play();
        } else {
            audio.pause();
        }
    }

    function step(delta) {
        if (tracks.length === 0) {
            return;
        }
        var next = current === -1 ? 0 : (current + delta + tracks.length) % tracks.length;
        load(next, true);
    }

    function updateMediaSession(track) {
        if (!('mediaSession' in navigator) || typeof window.MediaMetadata !== 'function') {
            return;
        }
        try {
            navigator.mediaSession.metadata = new window.MediaMetadata({
                title: track.title,
                artist: track.product
            });
            navigator.mediaSession.setActionHandler('play', function () { play(); });
            navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
            navigator.mediaSession.setActionHandler('previoustrack', function () { step(-1); });
            navigator.mediaSession.setActionHandler('nexttrack', function () { step(1); });
        } catch (error) {
            // Media Session اختیاری است.
        }
    }

    /* ---------------------------------------------------------------- */

    function renderPlaylist() {
        els.playlist.innerHTML = '';

        tracks.forEach(function (track) {
            var item = document.createElement('li');
            item.className = 'ct-ap-item';
            item.setAttribute('role', 'button');
            item.setAttribute('tabindex', '0');

            var number = document.createElement('span');
            number.className = 'ct-ap-item__num';
            number.textContent = String(track.index + 1);

            var text = document.createElement('span');
            text.className = 'ct-ap-item__text';

            var title = document.createElement('span');
            title.className = 'ct-ap-item__title';
            title.textContent = track.title;

            var product = document.createElement('span');
            product.className = 'ct-ap-item__product';
            product.textContent = track.product;

            text.appendChild(title);
            if (track.product) {
                text.appendChild(product);
            }

            var eq = document.createElement('span');
            eq.className = 'ct-ap-item__eq';
            eq.innerHTML = '<i></i><i></i><i></i>';

            item.appendChild(number);
            item.appendChild(text);
            item.appendChild(eq);

            var activate = function () {
                if (track.index === current) {
                    togglePlayback();
                } else {
                    load(track.index, true);
                }
            };

            item.addEventListener('click', activate);
            item.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activate();
                }
            });

            track.item = item;
            els.playlist.appendChild(item);
        });

        if (els.count) {
            els.count.textContent = String(tracks.length);
        }
        els.listToggle.setAttribute('title', sprintf(t('trackCount', '%d tracks'), tracks.length));
    }

    /* ---------------------------------------------------------------- */

    buttons.forEach(function (button, position) {
        var index = buttonTrack[position];
        button.addEventListener('click', function (event) {
            event.preventDefault();
            if (index === current) {
                // پلیر کوچک‌شده با هر کلیک روی دکمه‌ی یک ترک دوباره باز می‌شود.
                openPlayer();
                togglePlayback();
            } else {
                load(index, true);
            }
        });
    });

    els.toggle.addEventListener('click', togglePlayback);
    els.prev.addEventListener('click', function () { step(-1); });
    els.next.addEventListener('click', function () { step(1); });
    els.close.addEventListener('click', minimizePlayer);
    if (mini) {
        mini.addEventListener('click', restorePlayer);
        mini.addEventListener('contextmenu', function (event) {
            event.preventDefault();
        });
    }

    els.listToggle.addEventListener('click', function () {
        var expanded = els.listToggle.getAttribute('aria-expanded') === 'true';
        els.listToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        els.playlist.hidden = expanded;
        root.classList.toggle('has-playlist-open', !expanded);
    });

    els.seek.addEventListener('input', function () {
        isSeeking = true;
        var ratio = els.seek.value / 1000;
        els.fill.style.width = (ratio * 100) + '%';
        els.timeCurrent.textContent = formatTime(ratio * (audio.duration || 0));
    });

    els.seek.addEventListener('change', function () {
        if (isFinite(audio.duration)) {
            audio.currentTime = (els.seek.value / 1000) * audio.duration;
        }
        isSeeking = false;
    });

    function applyVolume(value) {
        audio.volume = Math.min(1, Math.max(0, value));
        audio.muted = audio.volume === 0;
        els.volume.value = Math.round(audio.volume * 100);
        root.classList.toggle('is-muted', audio.muted);
        try {
            window.localStorage.setItem(storageKey, String(audio.volume));
        } catch (error) {
            // حافظه‌ی مرورگر ممکن است در دسترس نباشد.
        }
    }

    els.volume.addEventListener('input', function () {
        applyVolume(els.volume.value / 100);
    });

    els.mute.addEventListener('click', function () {
        if (audio.muted || audio.volume === 0) {
            applyVolume(audio.volume > 0 ? audio.volume : 1);
            audio.muted = false;
            root.classList.remove('is-muted');
        } else {
            audio.muted = true;
            root.classList.add('is-muted');
        }
    });

    root.addEventListener('keydown', function (event) {
        var target = event.target;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'LI')) {
            return;
        }
        if (event.key === 'Escape') {
            minimizePlayer();
        } else if (event.key === ' ' || event.key === 'k') {
            event.preventDefault();
            togglePlayback();
        } else if (event.key === 'ArrowRight' && isFinite(audio.duration)) {
            audio.currentTime = Math.min(audio.duration, audio.currentTime + 5);
        } else if (event.key === 'ArrowLeft') {
            audio.currentTime = Math.max(0, audio.currentTime - 5);
        }
    });

    audio.addEventListener('play', function () { setPlayingState(true); });
    audio.addEventListener('pause', function () { setPlayingState(false); });
    audio.addEventListener('ended', function () {
        if (current < tracks.length - 1) {
            step(1);
        } else {
            setPlayingState(false);
        }
    });

    audio.addEventListener('loadedmetadata', function () {
        els.timeDuration.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('durationchange', function () {
        els.timeDuration.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('timeupdate', function () {
        if (isSeeking || !isFinite(audio.duration) || audio.duration === 0) {
            return;
        }
        var ratio = audio.currentTime / audio.duration;
        els.seek.value = Math.round(ratio * 1000);
        els.fill.style.width = (ratio * 100) + '%';
        els.timeCurrent.textContent = formatTime(audio.currentTime);
        if (mini) {
            mini.style.setProperty('--ct-am-progress', (ratio * 360).toFixed(1) + 'deg');
        }
    });

    audio.addEventListener('progress', function () {
        if (!isFinite(audio.duration) || audio.duration === 0 || audio.buffered.length === 0) {
            return;
        }
        var end = audio.buffered.end(audio.buffered.length - 1);
        els.buffer.style.width = Math.min(100, (end / audio.duration) * 100) + '%';
    });

    audio.addEventListener('error', function () {
        setPlayingState(false);
        showMessage(t('error', 'This track could not be played.'));
    });

    /* ---------------------------------------------------------------- */

    var storedVolume = 1;
    try {
        var raw = window.localStorage.getItem(storageKey);
        if (raw !== null && !isNaN(parseFloat(raw))) {
            storedVolume = parseFloat(raw);
        }
    } catch (error) {
        // حافظه‌ی مرورگر ممکن است در دسترس نباشد.
    }
    applyVolume(storedVolume);

    renderPlaylist();

    // پلیر (که بدون JS پنهان است) از همان ابتدا با اولین ترک و بدون پخش خودکار نمایش داده می‌شود.
    load(0, false);

    } // init
})();
