package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Telefon yeniden başladığında, uygulama güncellendiğinde veya saat/saat
 * dilimi değiştiğinde tüm alarmları yeniden kurar.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        try {
            AlarmNotifier.ensureChannel(context.getApplicationContext());
            AlarmScheduler.rescheduleAll(context.getApplicationContext());
        } catch (Throwable ignored) {
        }
    }
}
