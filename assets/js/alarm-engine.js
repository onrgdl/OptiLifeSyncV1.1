/**
 * OptiLifeSync — Tarayıcı Alarm Motoru & Alarm İzleyici (v2)
 * ────────────────────────────────────────────────────────────
 * 1. OptiAlarmEngine : Web Audio ile harici dosya gerektirmeyen alarm sesi,
 *                      ses seviyesi, sistem bildirimi, arka plan nöbetçisi.
 * 2. OptiAlarmWatcher: Sayfa açıkken zamanı gelen alarmları yakalar ve
 *                      "Aldım / Ertele / Atla" penceresini gösterir.
 *
 * Android uygulamasında (APK) alarmlar telefonun kendi alarm sistemiyle
 * çaldığı için izleyici otomatik olarak devre dışı kalır (çift çalma olmaz).
 *
 * Birden fazla kez yüklense de güvenlidir.
 */
(function () {
    'use strict';
    if (window.OptiAlarmEngine) return;

    const LS = {
        get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (_) { return d; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (_) {} }
    };

    class OptiAlarmEngine {
        constructor() {
            this.audioCtx = null;
            this.masterGain = null;
            this.isPlaying = false;
            this.alarmInterval = null;
            this.isUnlocked = false;

            const savedVol = LS.get('opti_alarm_volume', null);
            this.volume = savedVol !== null ? Math.max(0, Math.min(1, parseFloat(savedVol))) : 0.7;
            this.soundType = LS.get('opti_alarm_sound', 'classic');

            this.isSentinelEnabled = LS.get('opti_sentinel_enabled', 'true') !== 'false';
            this.sentinelAudio = null;
            this.wakeLock = null;
            this.isSentinelActive = false;

            this.availableSounds = {
                classic: { id: 'classic', name: 'Klasik dijital bip', interval: 1600 },
                chime:   { id: 'chime',   name: 'Melodik çan',        interval: 2000 },
                marimba: { id: 'marimba', name: 'Yumuşak marimba',    interval: 1800 },
                urgent:  { id: 'urgent',  name: 'Acil uyarı',         interval: 1400 },
                pulse:   { id: 'pulse',   name: 'Modern ritim',       interval: 1600 }
            };

            this.setupUnlockListener();
        }

        initContext() {
            if (!this.audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (AC) this.audioCtx = new AC();
            }
            if (this.audioCtx) {
                if (!this.masterGain) {
                    this.masterGain = this.audioCtx.createGain();
                    this.masterGain.gain.setValueAtTime(this.volume, this.audioCtx.currentTime);
                    this.masterGain.connect(this.audioCtx.destination);
                }
                if (this.audioCtx.state === 'suspended') this.audioCtx.resume();
            }
        }

        setupUnlockListener() {
            const unlock = () => {
                this.initContext();
                this.startSentinel();
                if (this.audioCtx && this.audioCtx.state === 'running') {
                    this.isUnlocked = true;
                    ['click', 'touchstart', 'keydown'].forEach(ev => window.removeEventListener(ev, unlock));
                }
            };
            ['click', 'touchstart', 'keydown'].forEach(ev => window.addEventListener(ev, unlock, { passive: true }));
        }

        setVolume(val) {
            let num = parseFloat(val);
            if (isNaN(num)) num = 0.7;
            if (num > 1) num = num / 100;
            this.volume = Math.max(0, Math.min(1, num));
            LS.set('opti_alarm_volume', String(this.volume));
            if (this.audioCtx && this.masterGain) {
                try {
                    this.masterGain.gain.cancelScheduledValues(this.audioCtx.currentTime);
                    this.masterGain.gain.setValueAtTime(this.volume, this.audioCtx.currentTime);
                } catch (_) {}
            }
        }
        getVolume() { return this.volume; }
        getVolumePercent() { return Math.round(this.volume * 100); }

        setSound(key) {
            if (this.availableSounds[key]) {
                this.soundType = key;
                LS.set('opti_alarm_sound', key);
            }
        }
        getSound() { return this.soundType; }

        playTone(freq, startTime, duration, peakGain = 0.4, type = 'sine') {
            try {
                this.initContext();
                if (!this.audioCtx || !this.masterGain) return;
                const osc = this.audioCtx.createOscillator();
                const gain = this.audioCtx.createGain();
                osc.type = type;
                osc.frequency.setValueAtTime(freq, startTime);
                gain.gain.setValueAtTime(0.0001, startTime);
                gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, peakGain), startTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);
                osc.connect(gain);
                gain.connect(this.masterGain);
                osc.start(startTime);
                osc.stop(startTime + duration);
            } catch (_) {}
        }

        playMelodyOnce(soundKey = null) {
            this.initContext();
            if (!this.audioCtx) return;
            const t0 = this.audioCtx.currentTime + 0.02;
            const T = (f, dt, d, g, w) => this.playTone(f, t0 + dt, d, g, w);
            switch (soundKey || this.soundType) {
                case 'chime':
                    T(523.25, 0, .45, .40, 'sine'); T(659.25, .14, .50, .40, 'sine'); T(783.99, .28, .55, .45, 'sine'); T(1046.5, .42, .85, .50, 'triangle');
                    break;
                case 'marimba':
                    T(440, 0, .20, .45, 'triangle'); T(554.37, .12, .20, .45, 'triangle'); T(659.25, .24, .22, .45, 'triangle'); T(880, .36, .35, .50, 'triangle');
                    break;
                case 'urgent':
                    T(987.77, 0, .10, .35, 'sawtooth'); T(1318.51, .12, .12, .35, 'sawtooth'); T(987.77, .24, .10, .35, 'sawtooth'); T(1318.51, .36, .16, .40, 'sawtooth');
                    break;
                case 'pulse':
                    T(587.33, 0, .09, .42, 'sine'); T(587.33, .12, .09, .42, 'sine'); T(880, .26, .30, .48, 'triangle');
                    break;
                default:
                    T(880, 0, .12, .40, 'sine'); T(1175, .14, .14, .45, 'sine'); T(1760, .30, .22, .50, 'sine');
            }
            if ('vibrate' in navigator) { try { navigator.vibrate([150, 80, 150, 80, 250]); } catch (_) {} }
        }

        testSound(soundKey = null, vol = null) {
            if (vol !== null) this.setVolume(vol);
            if (soundKey) this.setSound(soundKey);
            this.playMelodyOnce(soundKey);
        }

        start() {
            if (this.isPlaying) return;
            this.isPlaying = true;
            this.initContext();
            const conf = this.availableSounds[this.soundType] || this.availableSounds.classic;
            this.playMelodyOnce();
            this.alarmInterval = setInterval(() => {
                if (this.isPlaying) this.playMelodyOnce(); else clearInterval(this.alarmInterval);
            }, conf.interval || 1600);
        }

        stop() {
            this.isPlaying = false;
            if (this.alarmInterval) { clearInterval(this.alarmInterval); this.alarmInterval = null; }
            if ('vibrate' in navigator) { try { navigator.vibrate(0); } catch (_) {} }
            if (this.isSentinelActive) this.updateMediaSessionMetadata();
        }

        triggerAlarm(data = {}) {
            const title = data.title || (data.type === 'medication' ? '💊 İlaç zamanı' : '⏰ Hatırlatıcı');
            const body = data.body || `${data.label || ''}${data.dose ? ' · ' + data.dose : ''}`;
            this.start();
            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.metadata = new MediaMetadata({ title, artist: body, album: 'OptiLifeSync' });
                    navigator.mediaSession.playbackState = 'playing';
                } catch (_) {}
            }
            this.showSystemNotification(title, {
                body,
                tag: 'opti-alarm-' + (data.id || 'now'),
                data: { id: data.id, url: window.location.origin + '/reminders.php' }
            });
        }

        async showSystemNotification(title, options = {}) {
            const iconUrl = window.location.origin + '/assets/icons/icon-192.png';
            const opts = {
                body: options.body || 'OptiLifeSync',
                icon: options.icon || iconUrl,
                badge: options.badge || iconUrl,
                tag: options.tag || 'opti-alarm',
                renotify: true,
                requireInteraction: true,
                vibrate: [500, 250, 500, 250, 500],
                silent: false,
                timestamp: Date.now(),
                data: Object.assign({ url: window.location.origin + '/reminders.php' }, options.data || {})
            };
            if ('serviceWorker' in navigator) {
                try {
                    const reg = await navigator.serviceWorker.getRegistration();
                    if (reg && typeof reg.showNotification === 'function' && 'Notification' in window && Notification.permission === 'granted') {
                        await reg.showNotification(title, opts);
                        return true;
                    }
                } catch (_) {}
            }
            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    const n = new Notification(title, opts);
                    n.onclick = () => { window.focus(); n.close(); };
                    return true;
                } catch (_) {}
            }
            return false;
        }

        /* ── Arka plan nöbetçisi (tarayıcı sekmesinin uyutulmasını geciktirir) ── */
        startSentinel() {
            if (!this.isSentinelEnabled) return;
            try {
                if (!this.sentinelAudio) {
                    this.sentinelAudio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA');
                    this.sentinelAudio.loop = true;
                    this.sentinelAudio.volume = 0.001;
                }
                const p = this.sentinelAudio.play();
                if (p && p.then) p.then(() => { this.isSentinelActive = true; this.updateMediaSessionMetadata(); }).catch(() => {});
            } catch (_) {}
        }
        stopSentinel() {
            if (this.sentinelAudio) { try { this.sentinelAudio.pause(); } catch (_) {} }
            if (this.wakeLock) { try { this.wakeLock.release(); } catch (_) {} this.wakeLock = null; }
            this.isSentinelActive = false;
            if ('mediaSession' in navigator) { try { navigator.mediaSession.playbackState = 'none'; } catch (_) {} }
        }
        toggleSentinel(enabled) {
            this.isSentinelEnabled = !!enabled;
            LS.set('opti_sentinel_enabled', this.isSentinelEnabled ? 'true' : 'false');
            if (this.isSentinelEnabled) this.startSentinel(); else this.stopSentinel();
        }
        updateMediaSessionMetadata() {
            if ('mediaSession' in navigator) {
                try {
                    navigator.mediaSession.metadata = new MediaMetadata({ title: 'OptiLifeSync', artist: 'Alarmlar izleniyor', album: 'OptiLifeSync' });
                    navigator.mediaSession.playbackState = 'playing';
                } catch (_) {}
            }
        }

        /** Android tarayıcısında telefonun Saat uygulamasına alarm kurma ekranını açar. */
        setNativeClockAlarm(timeStr, label) {
            if (!timeStr || !timeStr.includes(':')) return false;
            const [h, m] = timeStr.split(':').map(n => parseInt(n, 10));
            if (isNaN(h) || isNaN(m)) return false;
            if (/android/i.test(navigator.userAgent)) {
                const msg = encodeURIComponent(label ? `💊 ${label}` : 'OptiLifeSync');
                window.location.href = `intent:#Intent;action=android.intent.action.SET_ALARM;i.android.intent.extra.HOUR=${h};i.android.intent.extra.MINUTES=${m};S.android.intent.extra.MESSAGE=${msg};B.android.intent.extra.SKIP_UI=false;end`;
                return true;
            }
            return false;
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       ALARM İZLEYİCİ — sayfa açıkken alarmı yakalar
       ═══════════════════════════════════════════════════════════════ */
    const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const api = () => (window.API_BASE || '/api') + '/reminders.php';
    const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

    const Watcher = {
        started: false,
        queue: [],
        showing: false,

        shownKey(r) { return `opti_shown_${todayStr()}_${r.id}_${r.remind_at}`; },
        wasShown(r) { try { return !!sessionStorage.getItem(this.shownKey(r)); } catch (_) { return false; } },
        markShown(r) { try { sessionStorage.setItem(this.shownKey(r), '1'); } catch (_) {} },

        start() {
            if (this.started) return;
            if (window.OptiNative && OptiNative.isApp()) return; // APK: telefon alarmı çalar
            this.started = true;
            this.poll();
            setInterval(() => this.poll(), 30000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
        },

        async poll() {
            try {
                const res = await fetch(api(), { method: 'POST', body: new URLSearchParams({ action: 'check_due' }), credentials: 'include' });
                if (!res.ok) return;
                const data = await res.json();
                for (const r of (data.due || [])) {
                    if (this.wasShown(r)) continue;
                    this.markShown(r);
                    this.queue.push(r);
                }
                this.next();
            } catch (_) {}
        },

        next() {
            if (this.showing || !this.queue.length) return;
            this.show(this.queue.shift());
        },

        /** Alarm penceresi. r: {id, supplement_id, type, label, remind_at, dose, form} */
        show(r) {
            this.showing = true;
            const engine = window.optiAlarmEngine;
            const trackable = !!r.supplement_id;
            const isMed = r.type === 'medication';
            const name = String(r.label || '').replace(/\s+—\s+\d{2}:\d{2}$/, '');
            const title = isMed ? '💊 İlaç zamanı' : (r.type === 'water' ? '💧 Su zamanı' : trackable ? '🧪 Takviye zamanı' : '⏰ Hatırlatıcı');
            let snooze = 10;
            try { snooze = parseInt(localStorage.getItem('opti_snooze_minutes') || '10', 10) || 10; } catch (_) {}

            if (engine) engine.triggerAlarm({ id: r.id, type: r.type, title, body: `${name}${r.dose && trackable ? ' · ' + r.dose.trim() : ''}` });

            const finish = () => { if (engine) engine.stop(); this.showing = false; setTimeout(() => this.next(), 400); };

            if (typeof Swal === 'undefined') {
                // SweetAlert bu sayfada yüklü değilse yükle, sonra pencereyi göster
                if (!this._swalLoading) {
                    this._swalLoading = true;
                    const css = document.createElement('link');
                    css.rel = 'stylesheet'; css.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
                    document.head.appendChild(css);
                    const sc = document.createElement('script');
                    sc.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
                    sc.onload = () => { if (engine) engine.stop(); this.showing = false; this.show(r); };
                    sc.onerror = () => {
                        const ok = window.confirm(`${title}\n${name}\n\nTamam = ${trackable ? 'Aldım' : 'Kapat'}`);
                        if (ok && trackable) this.log(r, 'taken');
                        finish();
                    };
                    document.head.appendChild(sc);
                }
                return;
            }

            Swal.fire({
                title,
                html: `<div class="alarm-pop">
                        <div class="alarm-pop-time">${esc(r.remind_at)}</div>
                        <div class="alarm-pop-name">${esc(name)}</div>
                        ${trackable && r.dose ? `<div class="alarm-pop-dose">${esc(r.dose)} ${r.form ? '· ' + esc(r.form) : ''}</div>` : ''}
                       </div>`,
                showConfirmButton: true,
                confirmButtonText: trackable ? '✅ Aldım' : 'Tamam',
                showDenyButton: trackable,
                denyButtonText: 'Atla',
                showCancelButton: true,
                cancelButtonText: `⏰ ${snooze} dk ertele`,
                reverseButtons: false,
                allowOutsideClick: false,
                customClass: { popup: 'alarm-swal' }
            }).then(res => {
                if (res.isConfirmed && trackable) this.log(r, 'taken');
                else if (res.isDenied) this.log(r, 'skipped');
                else if (res.dismiss === Swal.DismissReason.cancel) {
                    setTimeout(() => { this.queue.push(r); this.next(); }, snooze * 60000);
                    Swal.fire({ toast: true, position: 'top', icon: 'info', title: `${snooze} dakika sonra tekrar hatırlatılacak`, showConfirmButton: false, timer: 2500 });
                }
                finish();
            });
        },

        async log(r, status) {
            try {
                const res = await fetch(api(), {
                    method: 'POST', credentials: 'include',
                    body: new URLSearchParams({ action: 'log_dose', reminder_id: r.id, supplement_id: r.supplement_id || '', scheduled_time: r.remind_at, status })
                });
                const data = await res.json();
                if (data.ok && typeof Swal !== 'undefined') {
                    Swal.fire({ toast: true, position: 'top', icon: 'success', title: status === 'taken' ? 'Kaydedildi: alındı' : 'Atlandı olarak kaydedildi', showConfirmButton: false, timer: 2000 });
                }
                document.dispatchEvent(new CustomEvent('opti:dose-logged', { detail: { reminder: r, status } }));
            } catch (_) {}
        }
    };

    window.OptiAlarmEngine = OptiAlarmEngine;
    window.optiAlarmEngine = new OptiAlarmEngine();
    window.OptiAlarmWatcher = Watcher;
})();
