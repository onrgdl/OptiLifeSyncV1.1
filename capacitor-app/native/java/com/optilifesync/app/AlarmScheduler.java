package com.optilifesync.app;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;

/**
 * Alarmları Android AlarmManager'a kurar.
 *
 * setAlarmClock() kullanılır: Bu, telefonun kendi saat uygulamasının
 * kullandığı yöntemdir; Doze / pil tasarrufu modunda bile tam
 * zamanında tetiklenir ve durum çubuğunda alarm simgesi gösterir.
 */
final class AlarmScheduler {

    private static final String TAG = "OptiAlarm";
    static final String ACTION_FIRE = "com.optilifesync.app.ALARM_FIRE";

    static final String EXTRA_ID = "alarm_id";
    static final String EXTRA_TITLE = "title";
    static final String EXTRA_BODY = "body";
    static final String EXTRA_SUPPLEMENT_ID = "supplement_id";
    static final String EXTRA_TIME = "scheduled_time";
    static final String EXTRA_DATE = "scheduled_date";
    static final String EXTRA_TRACKABLE = "trackable";
    static final String EXTRA_IS_SNOOZE = "is_snooze";
    static final String EXTRA_IS_TEST = "is_test";

    /** Erteleme ve test alarmları için istek kodu ofsetleri (normal alarm id'leriyle çakışmasın). */
    private static final int SNOOZE_OFFSET = 700000000;
    private static final int TEST_REQUEST_CODE = 699999999;

    private AlarmScheduler() {}

    private static int immutableFlag() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0;
    }

    static boolean canScheduleExact(Context ctx) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return true;
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        return am != null && am.canScheduleExactAlarms();
    }

    // ── Tümünü yeniden kur ──────────────────────────────────────
    static synchronized int rescheduleAll(Context ctx) {
        cancelAllScheduled(ctx);
        JSONArray alarms = AlarmStore.getAlarms(ctx);
        Set<String> ids = new HashSet<>();
        int count = 0;
        for (int i = 0; i < alarms.length(); i++) {
            JSONObject a = alarms.optJSONObject(i);
            if (a == null) continue;
            if (scheduleNext(ctx, a)) {
                ids.add(String.valueOf(a.optInt("id")));
                count++;
            }
        }
        AlarmStore.setScheduledIds(ctx, ids);
        Log.i(TAG, "Kurulan alarm sayısı: " + count);
        return count;
    }

    /** Tek bir alarmın bir sonraki çalma zamanını kurar. */
    static boolean scheduleNext(Context ctx, JSONObject alarm) {
        long trigger = computeNextTrigger(alarm, System.currentTimeMillis());
        if (trigger <= 0) return false;
        int id = alarm.optInt("id", -1);
        if (id < 0) return false;

        Intent intent = buildFireIntent(ctx, alarm, id, formatDate(trigger), false, false);
        PendingIntent pi = PendingIntent.getBroadcast(ctx, id, intent, PendingIntent.FLAG_UPDATE_CURRENT | immutableFlag());
        setExact(ctx, trigger, pi);
        return true;
    }

    /** "Ertele": aynı alarmı N dakika sonra tek seferlik tekrar çaldırır. */
    static void snooze(Context ctx, Intent original, int minutes) {
        int id = original.getIntExtra(EXTRA_ID, 0);
        Intent intent = new Intent(ctx, AlarmReceiver.class);
        intent.setAction(ACTION_FIRE);
        intent.setData(Uri.parse("opti://alarm/snooze/" + id));
        if (original.getExtras() != null) intent.putExtras(original.getExtras());
        intent.putExtra(EXTRA_IS_SNOOZE, true);
        long trigger = System.currentTimeMillis() + minutes * 60_000L;
        PendingIntent pi = PendingIntent.getBroadcast(ctx, SNOOZE_OFFSET + (id % 100000000), intent,
                PendingIntent.FLAG_UPDATE_CURRENT | immutableFlag());
        setExact(ctx, trigger, pi);
    }

    /** Test alarmı: birkaç saniye sonra çalar. */
    static void scheduleTest(Context ctx, int seconds) {
        Intent intent = new Intent(ctx, AlarmReceiver.class);
        intent.setAction(ACTION_FIRE);
        intent.setData(Uri.parse("opti://alarm/test"));
        intent.putExtra(EXTRA_ID, 0);
        intent.putExtra(EXTRA_TITLE, "⏰ Test alarmı");
        intent.putExtra(EXTRA_BODY, "Alarm sistemi çalışıyor. Uygulama kapalıyken de böyle çalacak.");
        intent.putExtra(EXTRA_TRACKABLE, false);
        intent.putExtra(EXTRA_IS_TEST, true);
        long trigger = System.currentTimeMillis() + Math.max(2, seconds) * 1000L;
        PendingIntent pi = PendingIntent.getBroadcast(ctx, TEST_REQUEST_CODE, intent,
                PendingIntent.FLAG_UPDATE_CURRENT | immutableFlag());
        setExact(ctx, trigger, pi);
    }

    static Intent buildFireIntent(Context ctx, JSONObject a, int id, String date, boolean snooze, boolean test) {
        Intent intent = new Intent(ctx, AlarmReceiver.class);
        intent.setAction(ACTION_FIRE);
        intent.setData(Uri.parse("opti://alarm/" + id));
        intent.putExtra(EXTRA_ID, id);
        intent.putExtra(EXTRA_TITLE, a.optString("title", "⏰ Hatırlatıcı"));
        intent.putExtra(EXTRA_BODY, a.optString("body", ""));
        intent.putExtra(EXTRA_SUPPLEMENT_ID, a.optInt("supplementId", 0));
        intent.putExtra(EXTRA_TIME, a.optString("time", String.format(Locale.US, "%02d:%02d", a.optInt("hour"), a.optInt("minute"))));
        intent.putExtra(EXTRA_DATE, date);
        intent.putExtra(EXTRA_TRACKABLE, a.optBoolean("trackable", false));
        intent.putExtra(EXTRA_IS_SNOOZE, snooze);
        intent.putExtra(EXTRA_IS_TEST, test);
        return intent;
    }

    private static void setExact(Context ctx, long triggerAt, PendingIntent pi) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        try {
            if (canScheduleExact(ctx)) {
                Intent show = new Intent(ctx, MainActivity.class);
                show.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
                PendingIntent showPi = PendingIntent.getActivity(ctx, 1, show,
                        PendingIntent.FLAG_UPDATE_CURRENT | immutableFlag());
                am.setAlarmClock(new AlarmManager.AlarmClockInfo(triggerAt, showPi), pi);
            } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                // Tam zamanlı alarm izni yoksa: yine de Doze'da çalışan en iyi yaklaşık yöntem
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, pi);
            } else {
                am.setExact(AlarmManager.RTC_WAKEUP, triggerAt, pi);
            }
        } catch (SecurityException se) {
            Log.w(TAG, "Tam zamanlı alarm izni yok, yaklaşık alarm kuruluyor", se);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, pi);
            } else {
                am.set(AlarmManager.RTC_WAKEUP, triggerAt, pi);
            }
        }
    }

    private static void cancelAllScheduled(Context ctx) {
        AlarmManager am = (AlarmManager) ctx.getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        for (String s : AlarmStore.getScheduledIds(ctx)) {
            try {
                int id = Integer.parseInt(s);
                Intent intent = new Intent(ctx, AlarmReceiver.class);
                intent.setAction(ACTION_FIRE);
                intent.setData(Uri.parse("opti://alarm/" + id));
                PendingIntent pi = PendingIntent.getBroadcast(ctx, id, intent,
                        PendingIntent.FLAG_NO_CREATE | immutableFlag());
                if (pi != null) {
                    am.cancel(pi);
                    pi.cancel();
                }
            } catch (NumberFormatException ignored) {
            }
        }
        AlarmStore.setScheduledIds(ctx, new HashSet<String>());
    }

    static long nextTriggerOfAll(Context ctx) {
        JSONArray alarms = AlarmStore.getAlarms(ctx);
        long best = -1;
        long now = System.currentTimeMillis();
        for (int i = 0; i < alarms.length(); i++) {
            JSONObject a = alarms.optJSONObject(i);
            if (a == null) continue;
            long t = computeNextTrigger(a, now);
            if (t > 0 && (best < 0 || t < best)) best = t;
        }
        return best;
    }

    /**
     * Alarmın "now" sonrasındaki ilk çalma zamanını hesaplar.
     * days: 0=Pazar … 6=Cumartesi. startDate / endDate: yyyy-MM-dd (isteğe bağlı).
     */
    static long computeNextTrigger(JSONObject a, long now) {
        int hour = a.optInt("hour", -1);
        int minute = a.optInt("minute", -1);
        if (hour < 0 || hour > 23 || minute < 0 || minute > 59) return -1;

        boolean[] allowed = new boolean[7];
        JSONArray days = a.optJSONArray("days");
        boolean any = false;
        if (days != null) {
            for (int i = 0; i < days.length(); i++) {
                int d = days.optInt(i, -1);
                if (d >= 0 && d <= 6) {
                    allowed[d] = true;
                    any = true;
                }
            }
        }
        if (!any) for (int i = 0; i < 7; i++) allowed[i] = true;

        String start = emptyToNull(a.optString("startDate", ""));
        String end = emptyToNull(a.optString("endDate", ""));

        Calendar c = Calendar.getInstance();
        c.setTimeInMillis(now);
        c.set(Calendar.HOUR_OF_DAY, hour);
        c.set(Calendar.MINUTE, minute);
        c.set(Calendar.SECOND, 0);
        c.set(Calendar.MILLISECOND, 0);

        for (int i = 0; i < 400; i++) {
            if (c.getTimeInMillis() > now + 1000) {
                String ds = formatDate(c.getTimeInMillis());
                int dow = c.get(Calendar.DAY_OF_WEEK) - 1; // Calendar: 1=Pazar
                boolean inRange = (start == null || ds.compareTo(start) >= 0) && (end == null || ds.compareTo(end) <= 0);
                if (end != null && ds.compareTo(end) > 0) return -1;
                if (inRange && allowed[dow]) return c.getTimeInMillis();
            }
            c.add(Calendar.DAY_OF_MONTH, 1);
            c.set(Calendar.HOUR_OF_DAY, hour);
            c.set(Calendar.MINUTE, minute);
        }
        return -1;
    }

    private static String emptyToNull(String s) {
        if (s == null) return null;
        String t = s.trim();
        return (t.isEmpty() || "null".equals(t)) ? null : (t.length() >= 10 ? t.substring(0, 10) : t);
    }

    static String formatDate(long millis) {
        return new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date(millis));
    }
}
