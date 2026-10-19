package com.optilifesync.app;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.util.Log;
import android.webkit.CookieManager;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

/**
 * Akıllı bildirimler (Aşama 2): sabah özeti, su, makro durumu, kaçırılan doz,
 * antrenman öncesi / başlangıç / onay ve gün sonu kontrolü.
 *
 * Alarm çaldığında içerik o anki gerçek verilerle sunucudan alınır
 * (/api/notify.php?action=compose). Sunucu "gösterme" derse (ör. doz zaten
 * alınmış) bildirim çıkmaz. İnternet yoksa, yedek metni olan türler için
 * kısa bir hatırlatma gösterilir.
 *
 * Bu bildirimler normal öncelikli kanaldan gelir (alarm sesi çalmaz,
 * tam ekran açılmaz); butonları SmartActionReceiver işler.
 */
final class SmartNotifier {

    private static final String TAG = "OptiSmart";
    static final String CHANNEL_ID = "opti_smart_v1";
    static final String ACTION_SMART = "com.optilifesync.app.SMART_ACTION";

    static final String EXTRA_DO = "smart_do";
    static final String EXTRA_NOTIF_ID = "smart_notif_id";
    static final String EXTRA_DATE = "smart_date";
    static final String EXTRA_OPEN_PATH = "open_path";

    private SmartNotifier() {}

    /** İlaç alarmı bildirim id'leriyle (5000+) çakışmayan aralık. */
    static int notificationId(int alarmId) {
        return 2000000 + (Math.abs(alarmId) % 1000000);
    }

    private static int immutable() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0;
    }

    static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
        if (nm == null || nm.getNotificationChannel(CHANNEL_ID) != null) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "Günlük hatırlatmalar",
                NotificationManager.IMPORTANCE_DEFAULT);
        ch.setDescription("Sabah özeti, su, beslenme, antrenman ve gün sonu bildirimleri");
        ch.enableVibration(true);
        ch.enableLights(true);
        ch.setLightColor(0xFF10B981);
        nm.createNotificationChannel(ch);
    }

    /** AlarmReceiver'dan çağrılır; ağ işi arka planda yapılır, bitince pending.finish(). */
    static void handle(Context ctx, Intent fire, BroadcastReceiver.PendingResult pending) {
        final Context app = ctx.getApplicationContext();
        final int alarmId = fire.getIntExtra(AlarmScheduler.EXTRA_ID, 0);
        final String kind = nz(fire.getStringExtra(AlarmScheduler.EXTRA_KIND));
        final String ref = nz(fire.getStringExtra(AlarmScheduler.EXTRA_REF)).isEmpty() ? "{}" : fire.getStringExtra(AlarmScheduler.EXTRA_REF);
        final String date = nz(fire.getStringExtra(AlarmScheduler.EXTRA_DATE));
        final boolean fallback = fire.getBooleanExtra(AlarmScheduler.EXTRA_FALLBACK, false);
        final String fbTitle = nz(fire.getStringExtra(AlarmScheduler.EXTRA_TITLE));
        final String fbBody = nz(fire.getStringExtra(AlarmScheduler.EXTRA_BODY));

        new Thread(() -> {
            PowerManager.WakeLock wl = null;
            try {
                PowerManager pm = (PowerManager) app.getSystemService(Context.POWER_SERVICE);
                if (pm != null) {
                    wl = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "OptiLifeSync:smart");
                    wl.acquire(25_000L);
                }
                JSONObject res = null;
                try {
                    res = post(app, "action=compose&kind=" + enc(kind) + "&ref=" + enc(ref));
                } catch (Throwable t) {
                    Log.w(TAG, "İçerik alınamadı (" + kind + ")", t);
                }
                if (res != null && res.optBoolean("ok", false)) {
                    if (res.optBoolean("show", false)) {
                        show(app, alarmId, kind, ref, date, res.optString("title", fbTitle), res.optString("body", fbBody),
                                res.optJSONArray("actions"), res.optString("url", "/dashboard.php"));
                    }
                } else if (fallback && !fbTitle.isEmpty()) {
                    // Çevrimdışı / oturum yok: yalnızca yedek metni olan türler için kısa hatırlatma
                    show(app, alarmId, kind, ref, date, fbTitle, fbBody, null, "/dashboard.php");
                }
            } catch (Throwable t) {
                Log.e(TAG, "Akıllı bildirim hatası", t);
            } finally {
                if (wl != null && wl.isHeld()) {
                    try { wl.release(); } catch (Exception ignored) {}
                }
                if (pending != null) {
                    try { pending.finish(); } catch (Throwable ignored) {}
                }
            }
        }, "opti-smart").start();
    }

    static void show(Context ctx, int alarmId, String kind, String ref, String date, String title, String body,
                     JSONArray actions, String path) {
        ensureChannel(ctx);
        int notifId = notificationId(alarmId);

        PendingIntent open = openIntent(ctx, path, notifId);
        NotificationCompat.Builder b = new NotificationCompat.Builder(ctx, CHANNEL_ID)
                .setSmallIcon(R.drawable.ic_stat_opti_alarm)
                .setColor(0xFF10B981)
                .setContentTitle(title)
                .setContentText(firstLine(body))
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body))
                .setPriority(NotificationCompat.PRIORITY_DEFAULT)
                .setCategory(NotificationCompat.CATEGORY_REMINDER)
                .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
                .setAutoCancel(true)
                .setShowWhen(true)
                .setWhen(System.currentTimeMillis())
                .setTimeoutAfter(6 * 60 * 60 * 1000L)
                .setContentIntent(open);

        if (actions != null) {
            for (int i = 0; i < actions.length() && i < 3; i++) {
                JSONObject a = actions.optJSONObject(i);
                if (a == null) continue;
                String id = a.optString("id", "");
                String label = a.optString("label", id);
                if (id.isEmpty()) continue;
                if ("open".equals(id)) {
                    b.addAction(0, label, open);
                    continue;
                }
                Intent act = new Intent(ctx, SmartActionReceiver.class);
                act.setAction(ACTION_SMART);
                act.setData(Uri.parse("opti://smart/" + notifId + "/" + id));
                act.putExtra(EXTRA_DO, id);
                act.putExtra(EXTRA_NOTIF_ID, notifId);
                act.putExtra(EXTRA_DATE, date);
                act.putExtra(AlarmScheduler.EXTRA_KIND, kind);
                act.putExtra(AlarmScheduler.EXTRA_REF, ref);
                PendingIntent pi = PendingIntent.getBroadcast(ctx, notifId * 10 + i, act,
                        PendingIntent.FLAG_UPDATE_CURRENT | immutable());
                b.addAction(0, label, pi);
            }
        }

        try {
            NotificationManagerCompat nmc = NotificationManagerCompat.from(ctx);
            if (nmc.areNotificationsEnabled()) nmc.notify(notifId, b.build());
        } catch (SecurityException se) {
            Log.w(TAG, "Bildirim izni yok", se);
        }
    }

    /** Bildirime dokununca uygulamayı ilgili sayfada açar. */
    static PendingIntent openIntent(Context ctx, String path, int requestCode) {
        Intent i = new Intent(ctx, MainActivity.class);
        i.setAction(Intent.ACTION_VIEW);
        i.setData(Uri.parse("opti://open/" + requestCode));
        i.putExtra(EXTRA_OPEN_PATH, safePath(path));
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        return PendingIntent.getActivity(ctx, requestCode, i, PendingIntent.FLAG_UPDATE_CURRENT | immutable());
    }

    /** Yalnızca site içi yol ("/xxx.php") kabul edilir. */
    static String safePath(String p) {
        if (p == null || !p.startsWith("/") || p.startsWith("//") || p.contains("\\") || p.contains("://")) return "/dashboard.php";
        return p;
    }

    static void cancel(Context ctx, int notifId) {
        try { NotificationManagerCompat.from(ctx).cancel(notifId); } catch (Throwable ignored) {}
    }

    /** /api/notify.php'ye uygulamanın oturum çereziyle POST atar. Hata/oturum yoksa null. */
    static JSONObject post(Context ctx, String body) throws Exception {
        String base = AlarmStore.getBaseUrl(ctx);
        String cookie = null;
        try {
            cookie = CookieManager.getInstance().getCookie(base);
        } catch (Throwable t) {
            Log.w(TAG, "Çerez okunamadı", t);
        }
        if (cookie == null || cookie.isEmpty()) return null;

        HttpURLConnection con = (HttpURLConnection) new URL(base + "/api/notify.php").openConnection();
        con.setRequestMethod("POST");
        con.setConnectTimeout(7000);
        con.setReadTimeout(10000);
        con.setDoOutput(true);
        con.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8");
        con.setRequestProperty("Cookie", cookie);
        con.setRequestProperty("Accept", "application/json");
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        con.setFixedLengthStreamingMode(bytes.length);
        try (OutputStream os = con.getOutputStream()) {
            os.write(bytes);
        }
        int code = con.getResponseCode();
        if (code < 200 || code >= 300) {
            con.disconnect();
            return null;
        }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader br = new BufferedReader(new InputStreamReader(con.getInputStream(), StandardCharsets.UTF_8))) {
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
        }
        con.disconnect();
        try {
            return new JSONObject(sb.toString());
        } catch (Exception e) {
            return null;
        }
    }

    static String enc(String s) throws Exception {
        return URLEncoder.encode(s == null ? "" : s, "UTF-8");
    }

    private static String nz(String s) {
        return s == null || "null".equals(s) ? "" : s;
    }

    private static String firstLine(String s) {
        if (s == null) return "";
        int i = s.indexOf('\n');
        return i < 0 ? s : s.substring(0, i);
    }
}
