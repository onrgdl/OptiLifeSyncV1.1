package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.PowerManager;
import android.util.Log;

import org.json.JSONObject;

/**
 * AlarmManager alarm zamanı geldiğinde bu alıcıyı tetikler.
 * Alarm bildirimini gösterir ve tekrarlayan alarmın bir sonraki
 * çalma zamanını kurar.
 */
public class AlarmReceiver extends BroadcastReceiver {

    private static final String TAG = "OptiAlarm";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || !AlarmScheduler.ACTION_FIRE.equals(intent.getAction())) return;
        Context app = context.getApplicationContext();

        PowerManager.WakeLock wl = null;
        try {
            PowerManager pm = (PowerManager) app.getSystemService(Context.POWER_SERVICE);
            if (pm != null) {
                wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "OptiLifeSync:alarm");
                wl.acquire(10_000L);
            }

            boolean isSnooze = intent.getBooleanExtra(AlarmScheduler.EXTRA_IS_SNOOZE, false);
            boolean isTest = intent.getBooleanExtra(AlarmScheduler.EXTRA_IS_TEST, false);
            int id = intent.getIntExtra(AlarmScheduler.EXTRA_ID, -1);

            // ÖNCE sonraki çalmayı kur: bildirim gösterimi hata verse bile zincir kopmasın
            try {
                if (isSnooze) {
                    // Ertelemesi çaldı: kalıcı kaydı sil
                    if (!isTest) AlarmStore.removeSnooze(app, id);
                } else if (!isTest) {
                    JSONObject alarm = AlarmStore.findAlarm(app, id);
                    if (alarm != null) AlarmScheduler.scheduleNext(app, alarm);
                }
            } catch (Throwable t) {
                Log.e(TAG, "Sonraki alarm kurulamadı", t);
            }

            String kind = intent.getStringExtra(AlarmScheduler.EXTRA_KIND);
            if (kind != null && !kind.isEmpty() && !isSnooze && !isTest) {
                // Akıllı bildirim: içerik o anki verilerle sunucudan alınır (arka planda)
                try {
                    SmartNotifier.handle(app, intent, goAsync());
                } catch (Throwable t) {
                    Log.e(TAG, "Akıllı bildirim gösterilemedi", t);
                }
                return;
            }

            try {
                AlarmNotifier.show(app, intent);
            } catch (Throwable t) {
                Log.e(TAG, "Alarm bildirimi gösterilemedi", t);
            }
        } finally {
            if (wl != null && wl.isHeld()) {
                try { wl.release(); } catch (Exception ignored) {}
            }
        }
    }
}
