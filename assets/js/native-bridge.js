/**
 * OptiLifeSync — Android Uygulama Köprüsü (OptiNative)
 * ─────────────────────────────────────────────────────
 * Site, OptiLifeSync Android uygulamasının (APK) içinde açıldığında
 * alarmları telefonun kendi alarm sistemine (AlarmManager) kaydeder.
 * Böylece uygulama kapalıyken, ekran kilitliyken ve telefon yeniden
 * başlatıldıktan sonra bile alarmlar çalar.
 *
 * Tarayıcıda (APK dışında) tüm fonksiyonlar sessizce hiçbir şey yapmaz.
 */
(function () {
    'use strict';

    const PLUGIN = 'OptiAlarm';
    const API = () => (window.API_BASE || '/api') + '/reminders.php';

    function cap() { return window.Capacitor || null; }

    function isApp() {
        const C = cap();
        try {
            if (!C) return false;
            if (typeof C.isNativePlatform === 'function') return !!C.isNativePlatform();
            return C.platform === 'android' || (typeof C.getPlatform === 'function' && C.getPlatform() === 'android');
        } catch (_) { return false; }
    }

    let pluginProxy = null;
    function plugin() {
        const C = cap();
        if (!C) return null;
        if (C.Plugins && C.Plugins[PLUGIN]) return C.Plugins[PLUGIN];
        if (!pluginProxy && typeof C.registerPlugin === 'function') {
            try { pluginProxy = C.registerPlugin(PLUGIN); } catch (_) {}
        }
        return pluginProxy;
    }

    /** Yerel eklenti metodunu çağırır (farklı Capacitor sürümlerine dayanıklı). */
    function call(method, options) {
        const opts = options || {};
        const C = cap();
        if (!isApp() || !C) return Promise.reject(new Error('not-native'));
        const p = plugin();
        if (p && typeof p[method] === 'function') return p[method](opts);
        if (typeof C.nativePromise === 'function') return C.nativePromise(PLUGIN, method, opts);
        return Promise.reject(new Error('plugin-unavailable'));
    }

    async function post(params) {
        const body = new URLSearchParams(params);
        const res = await fetch(API(), { method: 'POST', body, credentials: 'include' });
        return res.json();
    }

    let syncing = null;
    let lastSyncAt = 0;

    /** Sunucudaki tüm aktif alarmları çekip telefona kurar. */
    function syncFromServer(force) {
        if (!isApp()) return Promise.resolve(false);
        if (syncing) return syncing;
        if (!force && Date.now() - lastSyncAt < 20000) return Promise.resolve(true);
        syncing = (async () => {
            try {
                const data = await post({ action: 'native_list' });
                if (!data || !data.ok) return false;
                const settings = readSettings();
                await call('schedule', {
                    alarms: data.alarms || [],
                    baseUrl: window.location.origin,
                    snoozeMinutes: settings.snoozeMinutes,
                    replaceAll: true
                });
                lastSyncAt = Date.now();
                return true;
            } catch (e) {
                console.warn('[OptiNative] senkronizasyon hatası:', e);
                return false;
            } finally {
                syncing = null;
            }
        })();
        return syncing;
    }

    /** Uygulama kapalıyken bildirimden "Aldım / Atla" denen dozları sunucuya işler. */
    let flushing = null;
    function flushTakenQueue() {
        if (!isApp()) return Promise.resolve(0);
        if (!flushing) flushing = doFlush().finally(() => { flushing = null; });
        return flushing;
    }

    async function doFlush() {
        try {
            const res = await call('getPendingEvents');
            const events = (res && res.events) || [];
            let sent = 0;
            const done = [];
            for (const ev of events) {
                try {
                    const r = await post({
                        action: 'log_dose',
                        reminder_id: ev.reminderId || '',
                        supplement_id: ev.supplementId || '',
                        scheduled_time: ev.scheduledTime || '',
                        log_date: ev.date || '',
                        status: ev.status || 'taken',
                        source: 'native'
                    });
                    if (r && (r.ok || r.duplicate)) { done.push(ev.uid); sent++; }
                } catch (_) { /* sonraki açılışta tekrar denenir */ }
            }
            if (done.length) await call('clearPendingEvents', { uids: done });
            if (sent) document.dispatchEvent(new CustomEvent('opti:doses-synced', { detail: { count: sent } }));
            return sent;
        } catch (_) { return 0; }
    }

    function readSettings() {
        let snooze = 10;
        try { snooze = parseInt(localStorage.getItem('opti_snooze_minutes') || '10', 10) || 10; } catch (_) {}
        return { snoozeMinutes: Math.min(60, Math.max(1, snooze)) };
    }

    /** Durum çubuğu rengini açık/koyu temaya uydurur (APK). */
    function syncStatusBar() {
        if (!isApp()) return;
        const C = cap();
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        const color = dark ? '#080D19' : '#F3F5F9';
        const invoke = (m, o) => {
            try {
                const sb = C.Plugins && C.Plugins.StatusBar;
                if (sb && typeof sb[m] === 'function') return sb[m](o);
                if (typeof C.nativePromise === 'function') return C.nativePromise('StatusBar', m, o);
            } catch (_) {}
            return Promise.resolve();
        };
        Promise.resolve(invoke('setBackgroundColor', { color })).catch(() => {});
        Promise.resolve(invoke('setStyle', { style: dark ? 'DARK' : 'LIGHT' })).catch(() => {});
    }
    document.addEventListener('opti:theme', syncStatusBar);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncStatusBar); else syncStatusBar();

    window.OptiNative = {
        isApp,
        call,
        syncFromServer,
        flushTakenQueue,
        /** İzin ve güvenilirlik durumunu döner. */
        status: () => call('status'),
        /** Bildirim + tam ekran + tam zamanlı alarm izinlerini ister. */
        requestPermissions: () => call('requestNotifications'),
        openExactAlarmSettings: () => call('openSettings', { target: 'exact_alarm' }),
        openBatterySettings: () => call('openSettings', { target: 'battery' }),
        openFullScreenSettings: () => call('openSettings', { target: 'full_screen' }),
        openNotificationSettings: () => call('openSettings', { target: 'notifications' }),
        /** Anında test alarmı (5 sn sonra çalar). */
        testAlarm: (seconds) => call('testAlarm', { seconds: seconds || 5 }),
        setSnoozeMinutes: (m) => { try { localStorage.setItem('opti_snooze_minutes', String(m)); } catch (_) {} return syncFromServer(true); }
    };
})();
