/**
 * OptiLifeSync - Gelişmiş Sesli Alarm Motoru (Web Audio API Synthesizer)
 * ────────────────────────────────────────────────────────────────────────
 * Harici ses dosyasına (MP3/WAV) ihtiyaç duymadan, sıfır gecikmeyle tüm
 * tarayıcılarda ve mobil cihazlarda anında çalan çok melodili synthesizer motoru.
 * 
 * Özellikler:
 * - 5 farklı polifonik melodi seçeneği (Klasik, Melodik Çan, Marimba, Acil Siren, Modern Pulse)
 * - Canlı ses seviyesi kontrolü (Master Gain: %0 - %100)
 * - Otomatik tarayıcı ses kilidi çözümü (Autoplay Policy / User Interaction Unlock)
 * - Haptic titreşim desteği (Mobil)
 * - LocalStorage kalıcılığı
 */

class OptiAlarmEngine {
    constructor() {
        this.audioCtx = null;
        this.masterGain = null;
        this.isPlaying = false;
        this.alarmInterval = null;
        this.isUnlocked = false;

        // Ayarları LocalStorage'dan al veya varsayılan ata
        const savedVol = localStorage.getItem('opti_alarm_volume');
        this.volume = savedVol !== null ? Math.max(0, Math.min(1, parseFloat(savedVol))) : 0.7;
        this.soundType = localStorage.getItem('opti_alarm_sound') || 'classic';

        // Arka Plan Nöbetçisi (Ekran kapalıyken veya arka plandayken uyutmayan koruyucu)
        this.isSentinelEnabled = localStorage.getItem('opti_sentinel_enabled') !== 'false';
        this.sentinelAudio = null;
        this.wakeLock = null;
        this.isSentinelActive = false;

        // Melodi tanımları
        this.availableSounds = {
            'classic': { id: 'classic', name: '🔔 Klasik Dijital Bip', interval: 1600 },
            'chime':   { id: 'chime',   name: '🎵 Melodik Çan (Ding-Dong)', interval: 2000 },
            'marimba': { id: 'marimba', name: '🌿 Yumuşak Marimba', interval: 1800 },
            'urgent':  { id: 'urgent',  name: '🚨 Acil Uyarı Sireni', interval: 1400 },
            'pulse':   { id: 'pulse',   name: '⚡ Modern Elektronik Ritim', interval: 1600 }
        };

        // Tarayıcı otomatik ses engellemesini (Autoplay Policy) aşmak için ilk etkileşimde kilidi aç
        this.setupUnlockListener();
    }

    /**
     * Web Audio Context başlat ve Master Gain bağla
     */
    initContext() {
        if (!this.audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                this.audioCtx = new AudioContext();
            }
        }

        if (this.audioCtx) {
            if (!this.masterGain) {
                this.masterGain = this.audioCtx.createGain();
                this.masterGain.gain.setValueAtTime(this.volume, this.audioCtx.currentTime);
                this.masterGain.connect(this.audioCtx.destination);
            }
            if (this.audioCtx.state === 'suspended') {
                this.audioCtx.resume();
            }
        }
    }

    setupUnlockListener() {
        const unlock = () => {
            this.initContext();
            this.startSentinel();
            if (this.audioCtx && this.audioCtx.state === 'running') {
                this.isUnlocked = true;
                window.removeEventListener('click', unlock);
                window.removeEventListener('touchstart', unlock);
                window.removeEventListener('keydown', unlock);
            }
        };

        window.addEventListener('click', unlock, { once: false, passive: true });
        window.addEventListener('touchstart', unlock, { once: false, passive: true });
        window.addEventListener('keydown', unlock, { once: false, passive: true });
    }

    /**
     * Ses Seviyesini Ayarla (0.0 - 1.0 veya 0 - 100)
     */
    setVolume(val) {
        let num = parseFloat(val);
        if (isNaN(num)) num = 0.7;
        if (num > 1) num = num / 100; // 70 girildiyse 0.7 yap
        this.volume = Math.max(0, Math.min(1, num));
        localStorage.setItem('opti_alarm_volume', this.volume.toString());

        if (this.audioCtx && this.masterGain) {
            try {
                this.masterGain.gain.cancelScheduledValues(this.audioCtx.currentTime);
                this.masterGain.gain.setValueAtTime(this.volume, this.audioCtx.currentTime);
            } catch (_) {}
        }
    }

    getVolume() {
        return this.volume;
    }

    getVolumePercent() {
        return Math.round(this.volume * 100);
    }

    /**
     * Alarm Melodisini Ayarla
     */
    setSound(soundKey) {
        if (this.availableSounds[soundKey]) {
            this.soundType = soundKey;
            localStorage.setItem('opti_alarm_sound', soundKey);
        }
    }

    getSound() {
        return this.soundType;
    }

    /**
     * Tekil bir osilatör tonu oluştur ve master gain'e bağla
     */
    playTone(freq, startTime, duration, peakGain = 0.4, type = 'sine') {
        try {
            this.initContext();
            if (!this.audioCtx || !this.masterGain) return;

            const osc = this.audioCtx.createOscillator();
            const gain = this.audioCtx.createGain();

            osc.type = type;
            osc.frequency.setValueAtTime(freq, startTime);

            // Yumuşak Attack ve Decay eğrisi (Tık/patlama seslerini engeller)
            gain.gain.setValueAtTime(0.0001, startTime);
            gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, peakGain), startTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

            osc.connect(gain);
            gain.connect(this.masterGain);

            osc.start(startTime);
            osc.stop(startTime + duration);
        } catch (e) {
            console.warn('Ton çalınamadı:', e);
        }
    }

    /**
     * Melodi 1: Klasik Dijital Bip
     */
    playClassicSequence(t0) {
        this.playTone(880,  t0,        0.12, 0.40, 'sine');
        this.playTone(1175, t0 + 0.14, 0.14, 0.45, 'sine');
        this.playTone(1760, t0 + 0.30, 0.22, 0.50, 'sine');
    }

    /**
     * Melodi 2: Melodik Çan (Ding-Dong / Harmonic Chime)
     */
    playChimeSequence(t0) {
        this.playTone(523.25, t0,        0.45, 0.40, 'sine');     // C5
        this.playTone(659.25, t0 + 0.14, 0.50, 0.40, 'sine');     // E5
        this.playTone(783.99, t0 + 0.28, 0.55, 0.45, 'sine');     // G5
        this.playTone(1046.50,t0 + 0.42, 0.85, 0.50, 'triangle'); // C6
    }

    /**
     * Melodi 3: Yumuşak Marimba (Akustik Doğal Ritim)
     */
    playMarimbaSequence(t0) {
        this.playTone(440.00, t0,        0.20, 0.45, 'triangle'); // A4
        this.playTone(554.37, t0 + 0.12, 0.20, 0.45, 'triangle'); // C#5
        this.playTone(659.25, t0 + 0.24, 0.22, 0.45, 'triangle'); // E5
        this.playTone(880.00, t0 + 0.36, 0.35, 0.50, 'triangle'); // A5
    }

    /**
     * Melodi 4: Acil Uyarı Sireni (Urgent Alert)
     */
    playUrgentSequence(t0) {
        this.playTone(987.77,  t0,        0.10, 0.35, 'sawtooth'); // B5
        this.playTone(1318.51, t0 + 0.12, 0.12, 0.35, 'sawtooth'); // E6
        this.playTone(987.77,  t0 + 0.24, 0.10, 0.35, 'sawtooth'); // B5
        this.playTone(1318.51, t0 + 0.36, 0.16, 0.40, 'sawtooth'); // E6
    }

    /**
     * Melodi 5: Modern Elektronik Ritim (Pulse)
     */
    playPulseSequence(t0) {
        this.playTone(587.33, t0,        0.09, 0.42, 'sine');     // D5
        this.playTone(587.33, t0 + 0.12, 0.09, 0.42, 'sine');     // D5
        this.playTone(880.00, t0 + 0.26, 0.30, 0.48, 'triangle'); // A5
    }

    /**
     * Belirtilen melodiyi bir kez çal
     */
    playMelodyOnce(soundKey = null) {
        this.initContext();
        if (!this.audioCtx) return;

        const key = soundKey || this.soundType;
        const now = this.audioCtx.currentTime + 0.02;

        switch (key) {
            case 'chime':   this.playChimeSequence(now); break;
            case 'marimba': this.playMarimbaSequence(now); break;
            case 'urgent':  this.playUrgentSequence(now); break;
            case 'pulse':   this.playPulseSequence(now); break;
            case 'classic':
            default:
                this.playClassicSequence(now);
                break;
        }

        // Mobil Titreşim
        if ('vibrate' in navigator) {
            try { navigator.vibrate([150, 80, 150, 80, 250]); } catch (_) {}
        }
    }

    /**
     * Seçili sesi tek sefer test et
     */
    testSound(soundKey = null, vol = null) {
        if (vol !== null) this.setVolume(vol);
        if (soundKey) this.setSound(soundKey);
        this.playMelodyOnce(soundKey);
    }

    /**
     * Alarmı Sürekli Döngüde Çalmaya Başla
     */
    start() {
        if (this.isPlaying) return;
        this.isPlaying = true;
        this.initContext();

        const soundConf = this.availableSounds[this.soundType] || this.availableSounds['classic'];
        const intervalMs = soundConf.interval || 1600;

        // İlk vuruş hemen
        this.playMelodyOnce();

        // Ritmik tekrar
        this.alarmInterval = setInterval(() => {
            if (this.isPlaying) {
                this.playMelodyOnce();
            } else {
                clearInterval(this.alarmInterval);
            }
        }, intervalMs);
    }

    /**
     * Alarmı Durdur
     */
    stop() {
        this.isPlaying = false;
        if (this.alarmInterval) {
            clearInterval(this.alarmInterval);
            this.alarmInterval = null;
        }
        if ('vibrate' in navigator) {
            try { navigator.vibrate(0); } catch (_) {}
        }
        // Alarm durdurulduğunda medya oturumu başlığını nöbetçi moduna döndür
        if (this.isSentinelActive) {
            this.updateMediaSessionMetadata();
        }
    }

    /**
     * ─── ALARM TETİKLEYİCİ & SİSTEM BİLDİRİMİ ───
     * Alarm vakti geldiğinde hem melodiyi çalar, hem de Android / PWA / Kilit Ekranı
     * üzerinde görünen sistem bildirimini ateşler.
     */
    triggerAlarm(data = {}) {
        const title = data.title || (data.type === 'medication' ? '💊 İlaç Zamanı!' : '💪 Takviye Zamanı!');
        const label = data.label || 'İlaç/Takviye';
        const dose  = data.dose ? ` (${data.dose})` : '';
        const body  = data.body || `${label}${dose} alma vaktiniz geldi!`;

        // 1. Sesli alarmı döngüsel başlat
        this.start();

        // 2. Kilit ekranı medya bildirimini hemen kırmızı alarm durumuna geçir
        if ('mediaSession' in navigator) {
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: `🚨 ${title}`,
                    artist: body,
                    album: 'OptiLifeSync Alarmı'
                });
                navigator.mediaSession.playbackState = 'playing';
            } catch (_) {}
        }

        // 3. Android Doze / Kilit ekranı sistem bildirimini göster
        this.showSystemNotification(title, {
            body: body,
            tag: 'opti-alarm-' + (data.id || 'now'),
            data: { id: data.id, url: window.location.origin + '/reminders.php' }
        });
    }

    /**
     * Mobil Chrome'da "Illegal constructor" hatası vermeyen,
     * Service Worker üzerinden telefon ekranına bildirim basan fonksiyon.
     */
    async showSystemNotification(title, options = {}) {
        const iconUrl = window.location.origin + '/assets/icons/icon-192.png';
        const finalOpts = {
            body: options.body || 'OptiLifeSync Alarmı',
            icon: options.icon || iconUrl,
            badge: options.badge || iconUrl,
            tag: options.tag || 'opti-alarm',
            renotify: true,
            requireInteraction: true,
            vibrate: [500, 250, 500, 250, 500, 250, 500],
            silent: false,
            timestamp: Date.now(),
            data: Object.assign({ url: window.location.origin + '/reminders.php' }, options.data || {})
        };

        // 1. Titreşim desteği
        if ('vibrate' in navigator) {
            try { navigator.vibrate(finalOpts.vibrate); } catch (_) {}
        }

        // 2. Capacitor LocalNotifications (Mobil APK)
        if (window.Capacitor?.Plugins?.LocalNotifications) {
            try {
                await window.Capacitor.Plugins.LocalNotifications.schedule({
                    notifications: [{
                        id: Math.floor(Math.random() * 900000) + 100000,
                        title: title,
                        body: finalOpts.body,
                        channelId: 'opti_alarms_channel',
                        extra: finalOpts.data
                    }]
                });
            } catch (e) {
                console.warn('Capacitor anlık bildirim hatası:', e);
            }
        }

        // 3. Service Worker showNotification (Android Chrome & PWA için ZORUNLU!)
        if ('serviceWorker' in navigator) {
            try {
                const reg = await navigator.serviceWorker.ready;
                if (reg && typeof reg.showNotification === 'function') {
                    await reg.showNotification(title, finalOpts);
                    return true;
                }
            } catch (err) {
                console.warn('ServiceWorker showNotification hatası:', err);
            }
        }

        // 4. Masaüstü Tarayıcı Fallback
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                const notif = new Notification(title, finalOpts);
                notif.onclick = function () {
                    window.focus();
                    notif.close();
                };
                return true;
            } catch (err) {
                console.warn('Notification constructor fallback hatası:', err);
            }
        }

        return false;
    }

    /**
     * ─── ARKA PLAN NÖBETÇİSİ (Background Audio Sentinel & WakeLock) ───
     * Mobil cihazlarda ekran kilitlendiğinde veya tarayıcı arka plana atıldığında
     * işletim sisteminin (Android Doze / iOS) JavaScript motorunu uyutmasını engeller.
     * Sessiz bir ses akışı ve MediaSession API ile arka plan nöbeti tutar.
     */
    startSentinel() {
        if (!this.isSentinelEnabled) return;

        try {
            if (!this.sentinelAudio) {
                // 48 baytlık saf sessiz PCM WAV akışı
                this.sentinelAudio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA');
                this.sentinelAudio.loop = true;
                this.sentinelAudio.volume = 0.001;
            }

            const playPromise = this.sentinelAudio.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    this.isSentinelActive = true;
                    this.updateMediaSessionMetadata();
                }).catch(() => {
                    // Kullanıcı etkileşimi beklenir
                });
            }

            // Destekleyen tarayıcılarda ekran uyanık tutma desteği
            if ('wakeLock' in navigator && !this.wakeLock) {
                navigator.wakeLock.request('screen').then(wl => {
                    this.wakeLock = wl;
                }).catch(() => {});
            }
        } catch (_) {}
    }

    stopSentinel() {
        if (this.sentinelAudio) {
            try { this.sentinelAudio.pause(); } catch (_) {}
        }
        if (this.wakeLock) {
            try { this.wakeLock.release(); this.wakeLock = null; } catch (_) {}
        }
        this.isSentinelActive = false;
        if ('mediaSession' in navigator) {
            try { navigator.mediaSession.playbackState = 'none'; } catch (_) {}
        }
    }

    toggleSentinel(enabled) {
        this.isSentinelEnabled = !!enabled;
        localStorage.setItem('opti_sentinel_enabled', this.isSentinelEnabled ? 'true' : 'false');
        if (this.isSentinelEnabled) {
            this.startSentinel();
        } else {
            this.stopSentinel();
        }
    }

    updateMediaSessionMetadata() {
        if ('mediaSession' in navigator) {
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: 'OptiLifeSync Alarm Nöbetçisi',
                    artist: 'İlaç & Takviye Alarmları İzleniyor',
                    album: 'OptiLifeSync'
                });
                navigator.mediaSession.playbackState = 'playing';
            } catch (_) {}
        }
    }

    /**
     * ─── TELEFONUN DAHİLİ SAATİNE (ANDROID CLOCK INTENT) ALARM KUR ───
     * Doğrudan telefonun kendi dahili "Saat / Alarm" uygulamasına (Google Saat / Samsung Saat)
     * alarm kurar. Telefon kapalı olsa veya tüm uygulamalar kapatılsa dahi %100 kesin çalar!
     */
    setNativeClockAlarm(timeStr, label) {
        if (!timeStr || !timeStr.includes(':')) return false;
        const [hStr, mStr] = timeStr.split(':');
        const h = parseInt(hStr, 10);
        const m = parseInt(mStr, 10);
        if (isNaN(h) || isNaN(m)) return false;

        const isAndroid = /android/i.test(navigator.userAgent);
        const cleanLabel = encodeURIComponent(label ? `💊 ${label}` : 'OptiLifeSync İlaç/Takviye');

        if (isAndroid) {
            const intentUrl = `intent:#Intent;action=android.intent.action.SET_ALARM;i.android.intent.extra.HOUR=${h};i.android.intent.extra.MINUTES=${m};S.android.intent.extra.MESSAGE=${cleanLabel};B.android.intent.extra.SKIP_UI=false;end`;
            window.location.href = intentUrl;
            return true;
        } else {
            if (window.Swal) {
                Swal.fire({
                    icon: 'info',
                    title: '📱 Telefon Alarmı',
                    html: `Telefonunuzun dahili saatine saat <strong>${timeStr}</strong> için <strong>${label}</strong> alarmı kurmak üzeresiniz.<br><br><small class="text-secondary">Android cihazlarda bu buton doğrudan telefonun kendi Saat / Alarm uygulamasını açıp alarmı kurar.</small>`,
                    confirmButtonText: 'Anladım',
                    confirmButtonColor: '#0284c7',
                    background: '#ffffff',
                    color: '#1e293b'
                });
            }
            return false;
        }
    }

    /**
     * ─── CAPACITOR NATIVE LOCAL NOTIFICATIONS SENKRONİZASYONU ───
     * Eğer uygulama Capacitor APK olarak çalışıyorsa, alarmları Android AlarmManager'a kaydeder.
     */
    async syncCapacitorNotifications(alarms) {
        const LN = window.Capacitor?.Plugins?.LocalNotifications;
        if (!LN || !alarms || !alarms.length) return false;

        try {
            const perm = await LN.requestPermissions();
            if (perm && perm.display !== 'granted') return false;

            await LN.createChannel({
                id: 'opti_alarms_channel',
                name: 'OptiLifeSync İlaç & Takviye Alarmları',
                description: 'Uygulama kapalıyken çalan yüksek öncelikli sesli alarm',
                importance: 5,
                visibility: 1,
                vibration: true,
                sound: 'beep.wav'
            });

            const pending = await LN.getPending();
            if (pending && pending.notifications && pending.notifications.length > 0) {
                await LN.cancel({ notifications: pending.notifications });
            }

            const list = [];
            let idx = 2000;
            for (const a of alarms) {
                if (!a.remind_at || !a.remind_at.includes(':')) continue;
                const [hStr, mStr] = a.remind_at.split(':');
                const hour = parseInt(hStr, 10);
                const minute = parseInt(mStr, 10);
                if (isNaN(hour) || isNaN(minute)) continue;

                idx++;
                list.push({
                    id: idx,
                    title: a.type === 'medication' ? '💊 İlaç Zamanı!' : '💪 Takviye Zamanı!',
                    body: `${a.label} — ${a.dose || ''} alma vaktiniz geldi!`.trim(),
                    channelId: 'opti_alarms_channel',
                    schedule: {
                        on: { hour, minute },
                        allowWhileIdle: true
                    },
                    extra: { id: a.id, remind_at: a.remind_at },
                    smallIcon: 'ic_stat_icon_config_sample'
                });
            }

            if (list.length > 0) {
                await LN.schedule({ notifications: list });
                console.log(`📱 ${list.length} adet sistem alarmı Capacitor ile zamanlandı.`);
            }
            return true;
        } catch (err) {
            console.warn('Capacitor LocalNotifications hatası:', err);
            return false;
        }
    }
}

// Global Singleton Instance
window.optiAlarmEngine = new OptiAlarmEngine();
