// Turns each recording in an article into a player with a waveform to click or drag,
// the time, and a speed button. The browser's own player is underneath: without
// JavaScript it stays as it is, so this only adds comfort.
(() => {
    'use strict';

    const SVG = 'http://www.w3.org/2000/svg';
    const SPEEDS = [1, 1.25, 1.5, 2, 0.75];
    const STEP_SECONDS = 5;
    const BIG_STEP_SECONDS = 30;
    const QUIETEST_BAR = 0.04;

    const clock = (seconds) => {
        const whole = Math.max(0, Math.floor(Number.isFinite(seconds) ? seconds : 0));
        const hours = Math.floor(whole / 3600);
        const minutes = Math.floor((whole % 3600) / 60);
        const rest = String(whole % 60).padStart(2, '0');

        return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`;
    };

    const icon = (paths) => {
        const svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        for (const d of paths) {
            const path = document.createElementNS(SVG, 'path');
            path.setAttribute('d', d);
            svg.append(path);
        }

        return svg;
    };
    const PLAY = ['M8 5.5v13l11-6.5z'];
    const PAUSE = ['M7 5h3.5v14H7z', 'M13.5 5H17v14h-3.5z'];

    // The bars, drawn once; a second copy in the accent colour shows what has been played.
    const bars = (peaks, className) => {
        const svg = document.createElementNS(SVG, 'svg');
        svg.setAttribute('viewBox', `0 0 ${peaks.length * 2} 100`);
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('aria-hidden', 'true');
        svg.classList.add(className);
        peaks.forEach((peak, index) => {
            const height = Math.max(QUIETEST_BAR, Math.min(1, peak)) * 100;
            const bar = document.createElementNS(SVG, 'rect');
            bar.setAttribute('x', String(index * 2 + 0.3));
            bar.setAttribute('y', String((100 - height) / 2));
            bar.setAttribute('width', '1.4');
            bar.setAttribute('height', String(height));
            svg.append(bar);
        });

        return svg;
    };

    const enhance = (figure) => {
        const audio = figure.querySelector('audio');
        if (audio === null) {
            return;
        }
        // Percent of the loudest moment.
        const peaks = (figure.dataset.peaks ?? '').split(' ').filter((peak) => peak !== '').map(Number).filter(Number.isFinite).map((peak) => peak / 100);
        const knownSeconds = Number(figure.dataset.seconds);
        const duration = () => (Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : (knownSeconds || 0));

        const player = document.createElement('div');
        player.className = 'audio-player';

        const play = document.createElement('button');
        play.type = 'button';
        play.className = 'audio-play';
        play.setAttribute('aria-label', 'Play');
        play.append(icon(PLAY));

        const wave = document.createElement('div');
        wave.className = 'audio-wave';
        wave.tabIndex = 0;
        wave.setAttribute('role', 'slider');
        wave.setAttribute('aria-label', 'Position');
        wave.setAttribute('aria-valuemin', '0');
        const shape = peaks.length > 0 ? peaks : new Array(100).fill(QUIETEST_BAR);
        const played = bars(shape, 'audio-played');
        wave.append(bars(shape, 'audio-bars'), played);

        const time = document.createElement('span');
        time.className = 'audio-time';

        const speed = document.createElement('button');
        speed.type = 'button';
        speed.className = 'audio-speed';
        speed.setAttribute('aria-label', 'Playback speed');

        player.append(play, wave, time, speed);
        audio.after(player);
        audio.controls = false;
        audio.hidden = true;

        // While seeking, show where it's going rather than where the audio still is.
        let wanted = null;
        let frame = 0;

        const show = () => {
            const total = duration();
            const at = Math.min(total, wanted ?? audio.currentTime);
            const share = total > 0 ? at / total : 0;
            played.style.clipPath = `inset(0 ${(100 - share * 100).toFixed(3)}% 0 0)`;
            time.textContent = `${clock(at)} / ${clock(total)}`;
            wave.setAttribute('aria-valuemax', String(Math.round(total)));
            wave.setAttribute('aria-valuenow', String(Math.round(at)));
            wave.setAttribute('aria-valuetext', `${clock(at)} of ${clock(total)}`);
        };

        const tick = () => {
            show();
            frame = audio.paused ? 0 : requestAnimationFrame(tick);
        };

        const seek = (seconds) => {
            const total = duration();
            const to = Math.max(0, Math.min(total, seconds));
            wanted = to;
            audio.currentTime = to;
            show();
        };

        const showSpeed = () => {
            speed.textContent = `${audio.playbackRate}×`;
        };

        play.addEventListener('click', () => {
            if (audio.paused) {
                audio.play().catch(() => {});
            } else {
                audio.pause();
            }
        });

        speed.addEventListener('click', () => {
            const next = SPEEDS[(SPEEDS.indexOf(audio.playbackRate) + 1) % SPEEDS.length];
            audio.playbackRate = next;
            audio.defaultPlaybackRate = next;
            showSpeed();
        });

        const positionOf = (event) => {
            const box = wave.getBoundingClientRect();

            return box.width > 0 ? ((event.clientX - box.left) / box.width) * duration() : 0;
        };
        wave.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }
            wave.setPointerCapture(event.pointerId);
            wave.classList.add('is-dragging');
            seek(positionOf(event));
        });
        wave.addEventListener('pointermove', (event) => {
            if (wave.hasPointerCapture(event.pointerId)) {
                seek(positionOf(event));
            }
        });
        const letGo = (event) => {
            if (wave.hasPointerCapture(event.pointerId)) {
                wave.releasePointerCapture(event.pointerId);
            }
            wave.classList.remove('is-dragging');
        };
        wave.addEventListener('pointerup', letGo);
        wave.addEventListener('pointercancel', letGo);

        wave.addEventListener('keydown', (event) => {
            const now = wanted ?? audio.currentTime;
            const moves = {
                ArrowLeft: now - STEP_SECONDS,
                ArrowDown: now - STEP_SECONDS,
                ArrowRight: now + STEP_SECONDS,
                ArrowUp: now + STEP_SECONDS,
                PageDown: now - BIG_STEP_SECONDS,
                PageUp: now + BIG_STEP_SECONDS,
                Home: 0,
                End: duration(),
            };
            if (event.key in moves) {
                event.preventDefault();
                seek(moves[event.key]);
            } else if (event.key === ' ' || event.key === 'k') {
                event.preventDefault();
                play.click();
            }
        });

        audio.addEventListener('play', () => {
            // One recording or video at a time.
            for (const other of document.querySelectorAll('audio, video:not(.media-loop)')) {
                if (other !== audio) {
                    other.pause();
                }
            }
            play.replaceChildren(icon(PAUSE));
            play.setAttribute('aria-label', 'Pause');
            player.classList.add('is-playing');
            cancelAnimationFrame(frame);
            tick();
        });
        const stopped = () => {
            play.replaceChildren(icon(PLAY));
            play.setAttribute('aria-label', 'Play');
            player.classList.remove('is-playing');
            show();
        };
        audio.addEventListener('pause', stopped);
        audio.addEventListener('ended', stopped);
        audio.addEventListener('seeked', () => {
            wanted = null;
            show();
        });
        audio.addEventListener('loadedmetadata', show);
        audio.addEventListener('durationchange', show);
        audio.addEventListener('error', () => {
            // Say so, and offer the file, instead of a play button that does nothing.
            const link = document.createElement('a');
            link.href = audio.currentSrc || audio.src;
            link.textContent = 'Download';
            time.replaceChildren('This browser cannot play it. ', link);
            player.classList.add('is-broken');
        });

        showSpeed();
        show();
    };

    document.querySelectorAll('figure.media-audio').forEach(enhance);

    // Animated GIFs play as muted loops. Readers who asked for less motion get them
    // paused, and anyone can pause or play one with a tap, a click or the keyboard.
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('video.media-loop').forEach((loop) => {
        if (calm) {
            loop.pause();
        }
        const toggle = () => (loop.paused ? loop.play().catch(() => {}) : loop.pause());
        loop.tabIndex = 0;
        loop.addEventListener('click', toggle);
        loop.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });
    });
})();
