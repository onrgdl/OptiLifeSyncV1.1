package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Telefon yeniden başladığında, uygulama güncellendiğinde veya saat/saat
 * dilimi değiştiğinde tüm alarmları (ve ertelemeleri) yeniden kurar.
 * Yalnızca bilinen sistem eylemleri işlenir; diğerleri yok sayılır.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) return;
        String action = intent.getAction();
        if (action == null) return;
        switch (action) {
            case "android.intent.action.BOOT_COMPLETED":
            case "android.intent.action.LOCKED_BOOT_COMPLETED":
            case "android.intent.action.QUICKBOOT_POWERON":
            case "com.htc.intent.action.QUICKBOOT_POWERON":
            case "android.intent.action.TIME_SET":
            case "android.intent.action.TIMEZONE_CHANGED":
            case "android.intent.action.MY_PACKAGE_REPLACED":
            case "android.app.action.SCHEDULE_EXACT_ALARM_PERMISSION_STATE_CHANGED":
                break;
            default:
                return;
        }
        try {
            Context app = context.getApplicationContext();
            AlarmNotifier.ensureChannel(app);
            AlarmScheduler.rescheduleAll(app);
        } catch (Throwable ignored) {
        }
    }
}
