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

    const SNOOZE_STORE = 'opti_web_snoozes';
    const SNOOZE_LATE_MS = 30 * 60000;   // zamanı bundan fazla geçmiş ertelemeler atılır
    const MIN_POLL_GAP = 10000;          // iki sorgu arası en az 10 sn

    /** Swal yoksa da görünen küçük bildirim. */
    function toast(icon, title) {
        if (typeof Swal !== 'undefined') {
            try { Swal.fire({ toast: true, position: 'top', icon, title, showConfirmButton: false, timer: 2500 }); return; } catch (_) {}
        }
        try {
            const el = document.createElement('div');
            el.setAttribute('role', 'status');
            el.textContent = title;
            el.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483647;max-width:90vw;padding:10px 16px;border-radius:12px;background:#0f172a;color:#fff;font:600 14px system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.35)';
            document.body.appendChild(el);
            setTimeout(() => { try { el.remove(); } catch (_) {} }, 3500);
        } catch (_) {}
    }

    const Watcher = {
        started: false,
        queue: [],
        showing: false,
        lastPollAt: 0,
        polling: false,
        snoozeTimers: {},

        shownKey(r) { return `opti_shown_${todayStr()}_${r.id}_${r.remind_at}`; },
        wasShown(r) { try { return !!sessionStorage.getItem(this.shownKey(r)); } catch (_) { return false; } },
        markShown(r) { try { sessionStorage.setItem(this.shownKey(r), '1'); } catch (_) {} },
        unmarkShown(r) { try { sessionStorage.removeItem(this.shownKey(r)); } catch (_) {} },

        /* ── Erteleme kalıcılığı: localStorage { anahtar: { until, r } } ── */
        snoozeKey(r) { return `${todayStr()}_${r.id}_${r.remind_at}`; },
        readSnoozes() {
            try {
                const o = JSON.parse(LS.get(SNOOZE_STORE, '{}'));
                return (o && typeof o === 'object') ? o : {};
            } catch (_) { return {}; }
        },
        writeSnoozes(o) { LS.set(SNOOZE_STORE, JSON.stringify(o)); },

        scheduleSnooze(r, minutes) {
            const until = Date.now() + minutes * 60000;
            const key = this.snoozeKey(r);
            const all = this.readSnoozes();
            all[key] = { until, r };
            this.writeSnoozes(all);
            this.armSnooze(key, r, until);
        },

        armSnooze(key, r, until) {
            if (this.snoozeTimers[key]) clearTimeout(this.snoozeTimers[key]);
            this.snoozeTimers[key] = setTimeout(() => {
                delete this.snoozeTimers[key];
                const all = this.readSnoozes();
                delete all[key];
                this.writeSnoozes(all);
                this.queue.push(r);
                this.next();
            }, Math.max(0, until - Date.now()));
        },

        /** Sayfa açılırken bekleyen ertelemeleri geri yükler, eskileri temizler. */
        restoreSnoozes() {
            const all = this.readSnoozes();
            const now = Date.now();
            let changed = false;
            Object.keys(all).forEach(key => {
                const e = all[key];
                const until = typeof e === 'number' ? e : (e && Number(e.until));
                if (!e || typeof e !== 'object' || !e.r || !until || until < now - SNOOZE_LATE_MS) {
                    delete all[key]; changed = true; return;
                }
                if (!this.snoozeTimers[key]) this.armSnooze(key, e.r, until);
            });
            if (changed) this.writeSnoozes(all);
        },

        start() {
            if (this.started) return;
            if (window.OptiNative && OptiNative.isApp()) return; // APK: telefon alarmı çalar
            this.started = true;
            this.restoreSnoozes();
            this.poll();
            // Sekme gizliyken sorgulama yapma (pil/ağ); görünür olunca hemen sorgula
            setInterval(() => { if (!document.hidden) this.poll(); }, 30000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && Date.now() - this.lastPollAt >= MIN_POLL_GAP) this.poll();
            });
        },

        async poll() {
            if (this.polling) return;
            this.polling = true;
            this.lastPollAt = Date.now();
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
            } catch (_) {
            } finally {
                this.polling = false;
            }
        },

        next() {
            if (this.showing || !this.queue.length) return;
            this.show(this.queue.shift());
        },

        /** SweetAlert yüklenemediğinde kullanılan, bağımlılıksız basit alarm penceresi. */
        showFallback(r, title, name, trackable, snooze, done) {
            let overlay = null;
            try {
                overlay = document.createElement('div');
                overlay.setAttribute('role', 'alertdialog');
                overlay.setAttribute('aria-modal', 'true');
                overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(2,6,23,.75)';
                const box = document.createElement('div');
                box.style.cssText = 'width:100%;max-width:360px;border-radius:20px;padding:22px;background:#fff;color:#0f172a;text-align:center;font-family:system-ui,sans-serif;box-shadow:0 20px 60px rgba(0,0,0,.5)';
                box.innerHTML = `<div style="font-size:20px;font-weight:800;margin-bottom:6px">${esc(title)}</div>
                    <div style="font-size:28px;font-weight:800;color:#059669">${esc(r.remind_at)}</div>
                    <div style="font-size:16px;margin:6px 0 16px">${esc(name)}${trackable && r.dose ? ' · ' + esc(String(r.dose).trim()) : ''}</div>`;
                const mk = (label, bg, fg, action) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.style.cssText = `display:block;width:100%;margin:8px 0 0;padding:13px;border:0;border-radius:12px;font-size:16px;font-weight:700;background:${bg};color:${fg};cursor:pointer`;
                    b.addEventListener('click', () => {
                        try { overlay.remove(); } catch (_) {}
                        done(action);
                    });
                    box.appendChild(b);
                };
                mk(trackable ? '✅ Aldım' : 'Tamam', '#10b981', '#fff', 'taken');
                if (trackable) mk('Atla', '#e2e8f0', '#0f172a', 'skipped');
                mk(`⏰ ${snooze} dk ertele`, '#1e293b', '#fff', 'snooze');
                overlay.appendChild(box);
                document.body.appendChild(overlay);
            } catch (_) {
                // DOM'a eklenemediyse kuyruk takılı kalmasın
                done('close');
            }
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
            const done = (action) => {
                try {
                    if (action === 'taken' && trackable) this.log(r, 'taken');
                    else if (action === 'skipped') this.log(r, 'skipped');
                    else if (action === 'snooze') {
                        this.scheduleSnooze(r, snooze);
                        toast('info', `${snooze} dakika sonra tekrar hatırlatılacak`);
                    }
                } finally {
                    finish();
                }
            };

            if (typeof Swal === 'undefined') {
                const recentlyFailed = this._swalFailedAt && (Date.now() - this._swalFailedAt < 5 * 60000);
                if (recentlyFailed) { this.showFallback(r, title, name, trackable, snooze, done); return; }
                // SweetAlert bu sayfada yüklü değilse yükle, sonra pencereyi göster
                if (!this._swalLoading) {
                    this._swalLoading = true;
                    let settled = false;
                    const fail = () => {
                        if (settled) return;
                        settled = true;
                        this._swalLoading = false;       // sonraki alarm yeniden deneyebilsin
                        this._swalFailedAt = Date.now();
                        this.showFallback(r, title, name, trackable, snooze, done);
                    };
                    const css = document.createElement('link');
                    css.rel = 'stylesheet'; css.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
                    document.head.appendChild(css);
                    const sc = document.createElement('script');
                    sc.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
                    sc.onload = () => {
                        if (settled) return;
                        settled = true;
                        this._swalLoading = false;
                        if (engine) engine.stop();
                        this.showing = false;
                        this.show(r);
                    };
                    sc.onerror = fail;
                    document.head.appendChild(sc);
                    setTimeout(() => { if (typeof Swal === 'undefined') fail(); }, 8000);
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
                if (res.isConfirmed) done('taken');
                else if (res.isDenied) done('skipped');
                else if (res.dismiss === Swal.DismissReason.cancel) done('snooze');
                else done('close');
            }, () => finish());
        },

        async log(r, status) {
            try {
                const res = await fetch(api(), {
                    method: 'POST', credentials: 'include',
                    body: new URLSearchParams({ action: 'log_dose', reminder_id: r.id, supplement_id: r.supplement_id || '', scheduled_time: r.remind_at, status })
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (!data || !data.ok) throw new Error('Sunucu kaydı reddetti');
                toast('success', status === 'taken' ? 'Kaydedildi: alındı' : 'Atlandı olarak kaydedildi');
                document.dispatchEvent(new CustomEvent('opti:dose-logged', { detail: { reminder: r, status } }));
            } catch (_) {
                // Sessizce kaybetme: kullanıcıyı uyar ve alarmı tekrar çalmaya uygun bırak
                this.unmarkShown(r);
                toast('error', 'Doz kaydedilemedi. Bağlantı gelince alarm tekrar gösterilecek.');
            }
        }
    };

    window.OptiAlarmEngine = OptiAlarmEngine;
    window.optiAlarmEngine = new OptiAlarmEngine();
    window.OptiAlarmWatcher = Watcher;
})();
