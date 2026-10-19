package com.optilifesync.app;

import android.content.Intent;
import android.os.Bundle;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Yerel alarm eklentisi (web tarafında window.Capacitor.Plugins.OptiAlarm)
        registerPlugin(OptiAlarmPlugin.class);
        super.onCreate(savedInstanceState);

        try {
            AlarmNotifier.ensureChannel(this);
            SmartNotifier.ensureChannel(this);
            AlarmScheduler.rescheduleAll(this);
        } catch (Throwable ignored) {
        }
        openPathFromIntent(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        openPathFromIntent(intent);
    }

    /** Akıllı bildirime dokunulduysa ilgili sayfayı aç (yalnızca site içi yol). */
    private void openPathFromIntent(Intent intent) {
        if (intent == null) return;
        String path = intent.getStringExtra(SmartNotifier.EXTRA_OPEN_PATH);
        if (path == null || path.isEmpty()) return;
        intent.removeExtra(SmartNotifier.EXTRA_OPEN_PATH);
        final String url = AlarmStore.getBaseUrl(this) + SmartNotifier.safePath(path);
        try {
            if (getBridge() == null) return;
            final WebView wv = getBridge().getWebView();
            if (wv != null) wv.post(() -> wv.loadUrl(url));
        } catch (Throwable ignored) {
        }
    }
}
