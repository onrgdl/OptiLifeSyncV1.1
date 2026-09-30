package com.optilifesync.app;

import android.Manifest;
import android.app.NotificationManager;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.provider.Settings;

import androidx.core.app.NotificationManagerCompat;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.HashSet;
import java.util.Set;

/**
 * Web tarafı ile Android alarm sistemi arasındaki köprü.
 * JS: window.Capacitor.Plugins.OptiAlarm (assets/js/native-bridge.js)
 */
@CapacitorPlugin(
        name = "OptiAlarm",
        permissions = {
                @Permission(alias = "notifications", strings = {Manifest.permission.POST_NOTIFICATIONS})
        }
)
public class OptiAlarmPlugin extends Plugin {

    @Override
    public void load() {
        AlarmNotifier.ensureChannel(getContext());
    }

    /** Sunucudan gelen alarm listesini kaydeder ve telefona kurar. */
    @PluginMethod
    public void schedule(PluginCall call) {
        JSArray alarms = call.getArray("alarms");
        if (alarms == null) {
            call.reject("alarms listesi gerekli");
            return;
        }
        Context ctx = getContext();
        String baseUrl = call.getString("baseUrl");
        if (baseUrl != null) AlarmStore.setBaseUrl(ctx, baseUrl);
        Integer snooze = call.getInt("snoozeMinutes");
        if (snooze != null) AlarmStore.setSnoozeMinutes(ctx, snooze);

        JSONArray clean = new JSONArray();
        for (int i = 0; i < alarms.length(); i++) {
            JSONObject o = alarms.optJSONObject(i);
            if (o != null && o.has("id") && o.has("hour") && o.has("minute")) clean.put(o);
        }
        AlarmStore.saveAlarms(ctx, clean);
        int count = AlarmScheduler.rescheduleAll(ctx);

        JSObject ret = buildStatus();
        ret.put("scheduled", count);
        call.resolve(ret);
    }

    @PluginMethod
    public void cancelAll(PluginCall call) {
        Context ctx = getContext();
        AlarmStore.saveAlarms(ctx, new JSONArray());
        AlarmScheduler.rescheduleAll(ctx);
        call.resolve(buildStatus());
    }

    @PluginMethod
    public void status(PluginCall call) {
        call.resolve(buildStatus());
    }

    /** Android 13+ bildirim iznini ister; daha eski sürümlerde izin zaten vardır. */
    @PluginMethod
    public void requestNotifications(PluginCall call) {
        if (Build.VERSION.SDK_INT >= 33 && getPermissionState("notifications") != PermissionState.GRANTED) {
            requestPermissionForAlias("notifications", call, "notificationPermissionCallback");
        } else {
            call.resolve(buildStatus());
        }
    }

    @PermissionCallback
    private void notificationPermissionCallback(PluginCall call) {
        call.resolve(buildStatus());
    }

    /** Test alarmı: birkaç saniye sonra tam ekran alarm çalar. */
    @PluginMethod
    public void testAlarm(PluginCall call) {
        Integer seconds = call.getInt("seconds", 5);
        AlarmScheduler.scheduleTest(getContext(), seconds == null ? 5 : seconds);
        call.resolve(buildStatus());
    }

    /** Bildirimden "Aldım / Atla" denmiş ama sunucuya henüz gönderilememiş dozlar. */
    @PluginMethod
    public void getPendingEvents(PluginCall call) {
        JSObject ret = new JSObject();
        try {
            ret.put("events", new JSArray(AlarmStore.getPendingEvents(getContext()).toString()));
        } catch (JSONException e) {
            ret.put("events", new JSArray());
        }
        call.resolve(ret);
    }

    @PluginMethod
    public void clearPendingEvents(PluginCall call) {
        JSArray uids = call.getArray("uids");
        Set<String> set = new HashSet<>();
        if (uids != null) {
            for (int i = 0; i < uids.length(); i++) {
                String s = uids.optString(i, null);
                if (s != null) set.add(s);
            }
        }
        AlarmStore.removePendingEvents(getContext(), set);
        call.resolve();
    }

    /** İlgili Android ayar ekranını açar (tam zamanlı alarm, pil, tam ekran, bildirim). */
    @PluginMethod
    public void openSettings(PluginCall call) {
        String target = call.getString("target", "app");
        Context ctx = getContext();
        String pkg = ctx.getPackageName();
        Uri pkgUri = Uri.parse("package:" + pkg);
        Intent intent = null;

        if ("exact_alarm".equals(target) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            intent = new Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, pkgUri);
        } else if ("battery".equals(target) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, pkgUri);
        } else if ("full_screen".equals(target) && Build.VERSION.SDK_INT >= 34) {
            intent = new Intent("android.settings.MANAGE_APP_USE_FULL_SCREEN_INTENT", pkgUri);
        } else if ("notifications".equals(target) && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            intent = new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS);
            intent.putExtra(Settings.EXTRA_APP_PACKAGE, pkg);
        }
        if (intent == null) {
            intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, pkgUri);
        }
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            ctx.startActivity(intent);
        } catch (Exception e) {
            try {
                Intent fallback = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, pkgUri);
                fallback.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                ctx.startActivity(fallback);
            } catch (Exception ignored) {
                call.reject("Ayar ekranı açılamadı");
                return;
            }
        }
        call.resolve();
    }

    private JSObject buildStatus() {
        Context ctx = getContext();
        JSObject s = new JSObject();
        s.put("platform", "android");
        s.put("sdk", Build.VERSION.SDK_INT);
        s.put("notifications", NotificationManagerCompat.from(ctx).areNotificationsEnabled());
        s.put("exactAlarm", AlarmScheduler.canScheduleExact(ctx));

        boolean fullScreen = true;
        if (Build.VERSION.SDK_INT >= 34) {
            NotificationManager nm = (NotificationManager) ctx.getSystemService(Context.NOTIFICATION_SERVICE);
            fullScreen = nm != null && nm.canUseFullScreenIntent();
        }
        s.put("fullScreen", fullScreen);

        boolean batteryOptimized = false;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            PowerManager pm = (PowerManager) ctx.getSystemService(Context.POWER_SERVICE);
            batteryOptimized = pm != null && !pm.isIgnoringBatteryOptimizations(ctx.getPackageName());
        }
        s.put("batteryOptimized", batteryOptimized);
        s.put("alarmCount", AlarmStore.getAlarms(ctx).length());
        s.put("nextTrigger", AlarmScheduler.nextTriggerOfAll(ctx));
        s.put("pendingEvents", AlarmStore.getPendingEvents(ctx).length());
        s.put("snoozeMinutes", AlarmStore.getSnoozeMinutes(ctx));
        return s;
    }
}
