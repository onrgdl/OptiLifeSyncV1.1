package com.optilifesync.app;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.media.AudioAttributes;
import android.media.AudioManager;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.util.Log;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;

/**
 * Alarm bildirimini gösterir.
 *
 *  - "Alarm" ses kanalı (USAGE_ALARM) kullanılır: telefon sessizde olsa bile
 *    alarm ses seviyesinde çalar.
 *  - FLAG_INSISTENT: kullanıcı bir işlem yapana kadar ses tekrar eder.
 *  - Tam ekran intent: ekran kilitliyken alarm ekranı (AlarmActivity) açılır.
 */
final class AlarmNotifier {

    private static final String TAG = "OptiAlarm";
    static final String CHANNEL_ID = "opti_alarm_v2";
    private static final long AUTO_TIMEOUT_MS = 10 * 60 * 1000L; // 10 dk sonra kendiliğinden susar
    private static final long[] VIBRATION = {0, 700, 500, 700, 500, 700, 1200};

    private AlarmNotifier() {}

    static int notificationId(int alarmId) {
        return 5000 + (alarmId % 1000000);
    }

    static Uri alarmSound() {
        Uri uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM);
        if (uri == null) uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
        if (uri == null) uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
        return uri;
    }

    static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || nm.getNotificationChannel(CHANNEL_ID) != null) return;

        NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "İlaç & hatırlatıcı alarmları",
                NotificationManager.IMPORTANCE_HIGH);
        ch.setDescription("Uygulama kapalıyken de çalan, susturana kadar devam eden alarmlar");
        AudioAttributes attrs = new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build();
        ch.setSound(alarmSound(), attrs);
        ch.enableVibration(true);
        ch.setVibrationPattern(VIBRATION);
        ch.enableLights(true);
        ch.setLightColor(0xFF10B981);
        ch.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        ch.setBypassDnd(true);
        nm.createNotificationChannel(ch);
    }

    private static int immutable() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0;
    }

    private static PendingIntent actionIntent(Context ctx, Intent src, String action, int requestCode) {
        Intent i = new Intent(ctx, AlarmActionReceiver.class);
        i.setAction(action);
        i.setData(Uri.parse("opti://action/" + action + "/" + requestCode));
        if (src.getExtras() != null) i.putExtras(src.getExtras());
        return PendingIntent.getBroadcast(ctx, requestCode, i, PendingIntent.FLAG_UPDATE_CURRENT | immutable());
    }

    /** Alarm bildirimini (ve tam ekran alarm ekranını) gösterir. */
    static void show(Context ctx, Intent fire) {
        ensureChannel(ctx);

        int alarmId = fire.getIntExtra(AlarmScheduler.EXTRA_ID, 0);
        int notifId = notificationId(alarmId);
        String title = fire.getStringExtra(AlarmScheduler.EXTRA_TITLE);
        String body = fire.getStringExtra(AlarmScheduler.EXTRA_BODY);
        boolean trackable = fire.getBooleanExtra(AlarmScheduler.EXTRA_TRACKABLE, false);
        int snoozeMin = AlarmStore.getSnoozeMinutes(ctx);
        if (title == null || title.isEmpty()) title = "⏰ Hatırlatıcı";
        if (body == null) body = "";

        Intent full = new Intent(ctx, AlarmActivity.class);
        if (fire.getExtras() != null) full.putExtras(fire.getExtras());
        full.putExtra(AlarmActivity.EXTRA_NOTIF_ID, notifId);
        full.setData(Uri.parse("opti://screen/" + alarmId + "/" + System.currentTimeMillis()));
        full.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_NO_USER_ACTION | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent fullPi = PendingIntent.getActivity(ctx, notifId, full, PendingIntent.FLAG_UPDATE_CURRENT | immutable());

        Intent withNotif = new Intent(fire);
        withNotif.putExtra(AlarmActivity.EXTRA_NOTIF_ID, notifId);

        NotificationCompat.Builder b = new NotificationCompat.Builder(ctx, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_opti_alarm)
                .setColor(0xFF10B981)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setCategory(NotificationCompat.CATEGORY_ALARM)
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
                .setOngoing(true)
                .setAutoCancel(false)
                .setShowWhen(true)
                .setWhen(System.currentTimeMillis())
                .setTimeoutAfter(AUTO_TIMEOUT_MS)
                .setContentIntent(fullPi)
                .setFullScreenIntent(fullPi, true)
                .setDeleteIntent(actionIntent(ctx, withNotif, AlarmActions.ACTION_DISMISS, notifId * 10 + 4));

        if (trackable) {
            b.addAction(0, "✅ Aldım", actionIntent(ctx, withNotif, AlarmActions.ACTION_TAKEN, notifId * 10 + 1));
        }
        b.addAction(0, "⏰ " + snoozeMin + " dk ertele", actionIntent(ctx, withNotif, AlarmActions.ACTION_SNOOZE, notifId * 10 + 2));
        b.addAction(0, trackable ? "Atla" : "Kapat",
                actionIntent(ctx, withNotif, trackable ? AlarmActions.ACTION_SKIP : AlarmActions.ACTION_DISMISS, notifId * 10 + 3));

        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            b.setSound(alarmSound(), AudioManager.STREAM_ALARM);
            b.setVibrate(VIBRATION);
        }

        Notification n = b.build();
        n.flags |= Notification.FLAG_INSISTENT | Notification.FLAG_NO_CLEAR;

        NotificationManagerCompat nmc = NotificationManagerCompat.from(ctx);
        boolean enabled = nmc.areNotificationsEnabled();
        try {
            if (enabled) nmc.notify(notifId, n);
        } catch (SecurityException se) {
            enabled = false;
            Log.w(TAG, "Bildirim izni yok", se);
        }

        if (!enabled) {
            // Bildirim izni kapalıysa: alarm ekranını doğrudan açmayı dene (ses AlarmActivity içinde çalar)
            try {
                full.putExtra(AlarmActivity.EXTRA_PLAY_SOUND, true);
                ctx.startActivity(full);
            } catch (Exception e) {
                Log.w(TAG, "Alarm ekranı açılamadı", e);
            }
        }
    }

    static void cancel(Context ctx, int notifId) {
        NotificationManagerCompat.from(ctx).cancel(notifId);
    }
}
