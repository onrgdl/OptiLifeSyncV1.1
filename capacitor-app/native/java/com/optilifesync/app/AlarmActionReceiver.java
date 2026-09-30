package com.optilifesync.app;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Alarm bildirimindeki butonlar ("Aldım", "Ertele", "Atla", "Kapat"). */
public class AlarmActionReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;
        PendingResult pending = goAsync();
        try {
            AlarmActions.handle(context, intent.getAction(), intent, pending);
        } catch (Throwable t) {
            try { pending.finish(); } catch (Throwable ignored) {}
        }
    }
}
