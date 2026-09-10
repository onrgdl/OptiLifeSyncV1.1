/**
 * OptiLifeSync - Sesli Alarm & Bildirim Motoru (Web Audio API Synthesizer)
 * ────────────────────────────────────────────────────────────────────────
 * Harici ses dosyasına (MP3/WAV) ihtiyaç duymadan, tüm tarayıcılarda ve
 * mobil cihazlarda anında çalan, modern ve ritmik alarm ses motoru.
 */

class OptiAlarmEngine {
    constructor() {
        this.audioCtx = null;
        this.isPlaying = false;
        this.alarmInterval = null;
        this.isUnlocked = false;

        // Tarayıcı otomatik ses engellemesini (Autoplay Policy) aşmak için ilk tıklamada kilidi aç
        this.setupUnlockListener();
    }

    /**
     * Web Audio Context başlat ve hazırla
     */
    initContext() {
        if (!this.audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (AudioContext) {
                this.audioCtx = new AudioContext();
            }
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    }

    setupUnlockListener() {
        const unlock = () => {
            this.initContext();
            if (this.audioCtx && this.audioCtx.state === 'running') {
                this.isUnlocked = true;
                window.removeEventListener('click', unlock);
                window.removeEventListener('touchstart', unlock);
                window.removeEventListener('keydown', unlock);
                console.log('🔊 OptiLifeSync Ses Motoru Aktif Edildi.');
            }
        };

        window.addEventListener('click', unlock, { once: false });
        window.addEventListener('touchstart', unlock, { once: false });
        window.addEventListener('keydown', unlock, { once: false });
    }

    /**
     * Tek bir çift-tonlu bildirim "bip"i çal (Frekanslar: 880Hz -> 1320Hz)
     */
    playChime(freq = 880, duration = 0.15, gainVal = 0.25) {
        try {
            this.initContext();
            if (!this.audioCtx) return;

            const now = this.audioCtx.currentTime;
            const osc = this.audioCtx.createOscillator();
            const gain = this.audioCtx.createGain();

            osc.type = 'sine'; // Yumuşak ama net sinüs dalgası
            osc.frequency.setValueAtTime(freq, now);

            // Yumuşak giriş ve hızlı sönümleme (Attack & Decay)
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.exponentialRampToValueAtTime(gainVal, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

            osc.connect(gain);
            gain.connect(this.audioCtx.destination);

            osc.start(now);
            osc.stop(now + duration);
        } catch (e) {
            console.warn('Alarm sesi çalınamadı:', e);
        }
    }

    /**
     * Ritmik Alarm Sinyali: İki hızlı bip + kısa bekleme
     */
    playBeepSequence() {
        if (!this.isPlaying) return;

        this.playChime(880, 0.12, 0.35);
        setTimeout(() => {
            if (this.isPlaying) this.playChime(1175, 0.15, 0.40);
        }, 140);
        setTimeout(() => {
            if (this.isPlaying) this.playChime(1760, 0.22, 0.45);
        }, 300);

        // Mobil Titreşim Desteği (Haptic Feedback)
        if ('vibrate' in navigator) {
            try {
                navigator.vibrate([200, 100, 200, 100, 300]);
            } catch (_) {}
        }
    }

    /**
     * Alarmı Sürekli Döngüde Çalmaya Başla
     */
    start() {
        if (this.isPlaying) return;
        this.isPlaying = true;
        this.initContext();

        // İlk vuruş
        this.playBeepSequence();

        // Her 1.5 saniyede bir ritmik tekrarla
        this.alarmInterval = setInterval(() => {
            if (this.isPlaying) {
                this.playBeepSequence();
            } else {
                clearInterval(this.alarmInterval);
            }
        }, 1600);
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
    }
}

// Global Singleton Instance
window.optiAlarmEngine = new OptiAlarmEngine();
