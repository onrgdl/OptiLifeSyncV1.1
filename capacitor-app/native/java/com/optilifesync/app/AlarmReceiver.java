package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.PowerManager;

import org.json.JSONObject;

/**
 * AlarmManager alarm zamanı geldiğinde bu alıcıyı tetikler.
 * Alarm bildirimini gösterir ve tekrarlayan alarmın bir sonraki
 * çalma zamanını kurar.
 */
public class AlarmReceiver extends BroadcastReceiver {

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

            AlarmNotifier.show(app, intent);

            boolean isSnooze = intent.getBooleanExtra(AlarmScheduler.EXTRA_IS_SNOOZE, false);
            boolean isTest = intent.getBooleanExtra(AlarmScheduler.EXTRA_IS_TEST, false);
            if (!isSnooze && !isTest) {
                int id = intent.getIntExtra(AlarmScheduler.EXTRA_ID, -1);
                JSONObject alarm = AlarmStore.findAlarm(app, id);
                if (alarm != null) AlarmScheduler.scheduleNext(app, alarm);
            }
        } finally {
            if (wl != null && wl.isHeld()) {
                try { wl.release(); } catch (Exception ignored) {}
            }
        }
    }
}
