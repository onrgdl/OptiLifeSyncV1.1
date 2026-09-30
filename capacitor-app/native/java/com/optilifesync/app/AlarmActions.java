package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.widget.Toast;

import org.json.JSONException;
import org.json.JSONObject;

/**
 * Alarm üzerindeki "Aldım", "Ertele", "Atla" ve "Kapat" işlemlerinin ortak mantığı.
 * Hem bildirim butonları (AlarmActionReceiver) hem de tam ekran alarm ekranı
 * (AlarmActivity) bu sınıfı kullanır.
 */
final class AlarmActions {

    static final String ACTION_TAKEN = "com.optilifesync.app.ALARM_TAKEN";
    static final String ACTION_SNOOZE = "com.optilifesync.app.ALARM_SNOOZE";
    static final String ACTION_SKIP = "com.optilifesync.app.ALARM_SKIP";
    static final String ACTION_DISMISS = "com.optilifesync.app.ALARM_DISMISS";

    private AlarmActions() {}

    /**
     * @param pending BroadcastReceiver.goAsync() sonucu (Activity'den çağrılıyorsa null)
     */
    static void handle(Context ctx, String action, Intent data, BroadcastReceiver.PendingResult pending) {
        Context app = ctx.getApplicationContext();
        int alarmId = data.getIntExtra(AlarmScheduler.EXTRA_ID, 0);
        int notifId = data.getIntExtra(AlarmActivity.EXTRA_NOTIF_ID, AlarmNotifier.notificationId(alarmId));

        // Sesi durdur: bildirimi kaldır ve açık alarm ekranını kapat
        AlarmNotifier.cancel(app, notifId);
        AlarmActivity.finishIfShowing();

        boolean willFinishAsync = false;

        if (ACTION_SNOOZE.equals(action)) {
            int min = AlarmStore.getSnoozeMinutes(app);
            AlarmScheduler.snooze(app, data, min);
            toast(app, min + " dakika sonra tekrar çalacak");
        } else if (ACTION_TAKEN.equals(action) || ACTION_SKIP.equals(action)) {
            boolean trackable = data.getBooleanExtra(AlarmScheduler.EXTRA_TRACKABLE, false);
            if (trackable) {
                String status = ACTION_TAKEN.equals(action) ? "taken" : "skipped";
                JSONObject ev = buildEvent(data, status);
                if (ev != null) {
                    willFinishAsync = pending != null;
                    DoseReporter.report(app, ev, pending);
                }
                toast(app, ACTION_TAKEN.equals(action) ? "Kaydedildi: alındı ✅" : "Bu doz atlandı olarak kaydedildi");
            }
        }

        if (pending != null && !willFinishAsync) {
            pending.finish();
        }
    }

    private static JSONObject buildEvent(Intent data, String status) {
        try {
            JSONObject ev = new JSONObject();
            ev.put("reminderId", data.getIntExtra(AlarmScheduler.EXTRA_ID, 0));
            ev.put("supplementId", data.getIntExtra(AlarmScheduler.EXTRA_SUPPLEMENT_ID, 0));
            String time = data.getStringExtra(AlarmScheduler.EXTRA_TIME);
            String date = data.getStringExtra(AlarmScheduler.EXTRA_DATE);
            ev.put("scheduledTime", time == null ? "" : time);
            ev.put("date", date == null ? AlarmScheduler.formatDate(System.currentTimeMillis()) : date);
            ev.put("status", status);
            ev.put("at", System.currentTimeMillis());
            return ev;
        } catch (JSONException e) {
            return null;
        }
    }

    private static void toast(Context ctx, String msg) {
        try {
            Toast.makeText(ctx, msg, Toast.LENGTH_SHORT).show();
        } catch (Exception ignored) {
        }
    }
}
