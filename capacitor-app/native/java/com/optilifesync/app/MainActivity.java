package com.optilifesync.app;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Yerel alarm eklentisi (web tarafında window.Capacitor.Plugins.OptiAlarm)
        registerPlugin(OptiAlarmPlugin.class);
        super.onCreate(savedInstanceState);

        try {
            AlarmNotifier.ensureChannel(this);
            AlarmScheduler.rescheduleAll(this);
        } catch (Throwable ignored) {
        }
    }
}
