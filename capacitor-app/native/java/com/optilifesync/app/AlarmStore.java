package com.optilifesync.app;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.HashSet;
import java.util.Set;
import java.util.UUID;

/**
 * Alarm listesini, ayarları ve çevrimdışı bekleyen doz olaylarını
 * SharedPreferences içinde saklar. Telefon yeniden başladığında
 * alarmlar buradan tekrar kurulur.
 */
final class AlarmStore {

    private static final String PREFS = "opti_alarm_store";
    private static final String KEY_ALARMS = "alarms";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_SNOOZE = "snooze_minutes";
    private static final String KEY_SCHEDULED_IDS = "scheduled_ids";
    private static final String KEY_EVENTS = "pending_events";

    static final String DEFAULT_BASE_URL = "https://opti-life-sync-v1-1.vercel.app";

    private AlarmStore() {}

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    // ── Alarmlar ───────────────────────────────────────────────
    static synchronized void saveAlarms(Context ctx, JSONArray alarms) {
        prefs(ctx).edit().putString(KEY_ALARMS, alarms.toString()).apply();
    }

    static synchronized JSONArray getAlarms(Context ctx) {
        String raw = prefs(ctx).getString(KEY_ALARMS, "[]");
        try {
            return new JSONArray(raw);
        } catch (JSONException e) {
            return new JSONArray();
        }
    }

    static JSONObject findAlarm(Context ctx, int id) {
        JSONArray arr = getAlarms(ctx);
        for (int i = 0; i < arr.length(); i++) {
            JSONObject o = arr.optJSONObject(i);
            if (o != null && o.optInt("id", -1) == id) return o;
        }
        return null;
    }

    // ── Ayarlar ────────────────────────────────────────────────
    static void setBaseUrl(Context ctx, String url) {
        if (url == null || url.trim().isEmpty()) return;
        String clean = url.trim();
        while (clean.endsWith("/")) clean = clean.substring(0, clean.length() - 1);
        prefs(ctx).edit().putString(KEY_BASE_URL, clean).apply();
    }

    static String getBaseUrl(Context ctx) {
        return prefs(ctx).getString(KEY_BASE_URL, DEFAULT_BASE_URL);
    }

    static void setSnoozeMinutes(Context ctx, int minutes) {
        prefs(ctx).edit().putInt(KEY_SNOOZE, Math.max(1, Math.min(60, minutes))).apply();
    }

    static int getSnoozeMinutes(Context ctx) {
        return prefs(ctx).getInt(KEY_SNOOZE, 10);
    }

    // ── Kurulu alarm istek kodları (iptal için) ─────────────────
    static synchronized Set<String> getScheduledIds(Context ctx) {
        return new HashSet<>(prefs(ctx).getStringSet(KEY_SCHEDULED_IDS, new HashSet<String>()));
    }

    static synchronized void setScheduledIds(Context ctx, Set<String> ids) {
        prefs(ctx).edit().putStringSet(KEY_SCHEDULED_IDS, new HashSet<>(ids)).apply();
    }

    // ── Çevrimdışı doz olayları ("Aldım" / "Atla") ─────────────
    static synchronized void addPendingEvent(Context ctx, JSONObject ev) {
        try {
            if (!ev.has("uid")) ev.put("uid", UUID.randomUUID().toString());
            JSONArray arr = getPendingEvents(ctx);
            arr.put(ev);
            // En fazla 200 olay tut
            if (arr.length() > 200) {
                JSONArray trimmed = new JSONArray();
                for (int i = arr.length() - 200; i < arr.length(); i++) trimmed.put(arr.get(i));
                arr = trimmed;
            }
            prefs(ctx).edit().putString(KEY_EVENTS, arr.toString()).apply();
        } catch (JSONException ignored) {
        }
    }

    static synchronized JSONArray getPendingEvents(Context ctx) {
        try {
            return new JSONArray(prefs(ctx).getString(KEY_EVENTS, "[]"));
        } catch (JSONException e) {
            return new JSONArray();
        }
    }

    static synchronized void removePendingEvents(Context ctx, Set<String> uids) {
        JSONArray arr = getPendingEvents(ctx);
        JSONArray keep = new JSONArray();
        for (int i = 0; i < arr.length(); i++) {
            JSONObject o = arr.optJSONObject(i);
            if (o == null) continue;
            if (!uids.contains(o.optString("uid", ""))) keep.put(o);
        }
        prefs(ctx).edit().putString(KEY_EVENTS, keep.toString()).apply();
    }
}
