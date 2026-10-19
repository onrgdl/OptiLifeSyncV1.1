package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Handler;
import android.os.Looper;
import android.util.Log;
import android.widget.Toast;

import org.json.JSONObject;

/**
 * Akıllı bildirim butonları: "+250 ml", "Aldım", "Atla", "Yaptım", "Yapmadım".
 * İşlem /api/notify.php?action=act ile sunucuya işlenir. İnternet yoksa
 * doz işlemleri çevrimdışı sıraya alınır (uygulama açılınca gönderilir).
 */
public class SmartActionReceiver extends BroadcastReceiver {

    private static final String TAG = "OptiSmart";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || !SmartNotifier.ACTION_SMART.equals(intent.getAction())) return;
        final Context app = context.getApplicationContext();
        final String what = nz(intent.getStringExtra(SmartNotifier.EXTRA_DO));
        final int notifId = intent.getIntExtra(SmartNotifier.EXTRA_NOTIF_ID, 0);
        final String ref = nz(intent.getStringExtra(AlarmScheduler.EXTRA_REF)).isEmpty() ? "{}" : intent.getStringExtra(AlarmScheduler.EXTRA_REF);
        final String date = nz(intent.getStringExtra(SmartNotifier.EXTRA_DATE));
        if (what.isEmpty()) return;

        // Butona basıldı: bildirimi hemen kaldır (çift basmayı önler)
        SmartNotifier.cancel(app, notifId);
        final PendingResult pending = goAsync();

        new Thread(() -> {
            boolean handedOff = false;
            try {
                JSONObject res = null;
                try {
                    res = SmartNotifier.post(app, "action=act&do=" + SmartNotifier.enc(what)
                            + "&ref=" + SmartNotifier.enc(ref) + "&date=" + SmartNotifier.enc(date));
                } catch (Throwable t) {
                    Log.w(TAG, "İşlem gönderilemedi: " + what, t);
                }
                if (res != null && res.optBoolean("ok", false)) {
                    toast(app, res.optString("message", "Kaydedildi"));
                } else if (what.startsWith("dose_")) {
                    // Çevrimdışı: dozu ilaç alarmlarıyla aynı kalıcı sıraya yaz (DoseReporter tekrar dener)
                    JSONObject r = new JSONObject(ref);
                    JSONObject ev = new JSONObject();
                    ev.put("reminderId", r.optInt("reminder_id", 0));
                    ev.put("supplementId", r.optInt("supplement_id", 0));
                    ev.put("scheduledTime", r.optString("time", ""));
                    ev.put("date", date.isEmpty() ? AlarmScheduler.formatDate(System.currentTimeMillis()) : date);
                    ev.put("status", "dose_taken".equals(what) ? "taken" : "skipped");
                    long now = System.currentTimeMillis();
                    ev.put("at", now);
                    ev.put("createdAt", now);
                    DoseReporter.report(app, ev, pending);
                    handedOff = true;
                    toast(app, "Bağlantı yok; uygulama açılınca kaydedilecek");
                } else {
                    toast(app, "Bağlantı yok. Lütfen uygulamadan işaretleyin.");
                }
            } catch (Throwable t) {
                Log.e(TAG, "Akıllı bildirim işlemi hatası", t);
            } finally {
                if (!handedOff) {
                    try { pending.finish(); } catch (Throwable ignored) {}
                }
            }
        }, "opti-smart-action").start();
    }

    private static void toast(Context ctx, String msg) {
        try {
            new Handler(Looper.getMainLooper()).post(() -> {
                try { Toast.makeText(ctx, msg, Toast.LENGTH_SHORT).show(); } catch (Exception ignored) {}
            });
        } catch (Throwable ignored) {
        }
    }

    private static String nz(String s) {
        return s == null || "null".equals(s) ? "" : s;
    }
}
