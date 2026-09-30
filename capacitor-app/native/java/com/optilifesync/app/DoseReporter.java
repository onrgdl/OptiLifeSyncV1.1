package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.util.Log;
import android.webkit.CookieManager;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;

/**
 * Bildirimden "Aldım / Atla" denildiğinde dozu hemen sunucuya bildirir.
 * Uygulamanın oturum çerezini (WebView CookieManager) kullanır.
 * İnternet yoksa olay sıraya alınır; uygulama bir sonraki açılışta gönderir.
 */
final class DoseReporter {

    private static final String TAG = "OptiAlarm";

    private DoseReporter() {}

    static void report(Context ctx, JSONObject ev, BroadcastReceiver.PendingResult pending) {
        final Context app = ctx.getApplicationContext();
        final String base = AlarmStore.getBaseUrl(app);
        String cookie = null;
        try {
            cookie = CookieManager.getInstance().getCookie(base);
        } catch (Throwable t) {
            Log.w(TAG, "Çerez okunamadı", t);
        }
        final String cookieHeader = cookie;

        new Thread(() -> {
            boolean ok = false;
            try {
                if (cookieHeader != null && !cookieHeader.isEmpty()) {
                    ok = post(base + "/api/reminders.php", cookieHeader, ev);
                }
            } catch (Throwable t) {
                Log.w(TAG, "Doz gönderilemedi, sıraya alındı", t);
            }
            if (!ok) {
                AlarmStore.addPendingEvent(app, ev);
            }
            if (pending != null) {
                try { pending.finish(); } catch (Throwable ignored) {}
            }
        }, "opti-dose-report").start();
    }

    private static String enc(String s) throws Exception {
        return URLEncoder.encode(s == null ? "" : s, "UTF-8");
    }

    private static boolean post(String url, String cookie, JSONObject ev) throws Exception {
        String body = "action=log_dose"
                + "&source=native"
                + "&reminder_id=" + enc(String.valueOf(ev.optInt("reminderId")))
                + "&supplement_id=" + enc(String.valueOf(ev.optInt("supplementId")))
                + "&scheduled_time=" + enc(ev.optString("scheduledTime"))
                + "&log_date=" + enc(ev.optString("date"))
                + "&status=" + enc(ev.optString("status", "taken"));

        HttpURLConnection con = (HttpURLConnection) new URL(url).openConnection();
        con.setRequestMethod("POST");
        con.setConnectTimeout(10000);
        con.setReadTimeout(15000);
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
            return false;
        }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader br = new BufferedReader(new InputStreamReader(con.getInputStream(), StandardCharsets.UTF_8))) {
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
        }
        con.disconnect();
        try {
            JSONObject res = new JSONObject(sb.toString());
            return res.optBoolean("ok", false);
        } catch (Exception e) {
            return false;
        }
    }
}
