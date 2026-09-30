package com.optilifesync.app;

import android.app.Activity;
import android.app.KeyguardManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.media.AudioAttributes;
import android.media.AudioManager;
import android.media.MediaPlayer;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.lang.ref.WeakReference;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/**
 * Tam ekran alarm ekranı. Kilit ekranının üzerinde açılır ve ekranı uyandırır.
 * Ses, bildirim tarafından (alarm kanalı, sürekli tekrar) çalınır; bildirim izni
 * kapalıysa ses bu ekran tarafından çalınır.
 */
public class AlarmActivity extends Activity {

    static final String EXTRA_NOTIF_ID = "notif_id";
    static final String EXTRA_PLAY_SOUND = "play_sound";

    private static WeakReference<AlarmActivity> current = new WeakReference<>(null);

    private MediaPlayer player;
    private final Handler handler = new Handler(Looper.getMainLooper());

    static void finishIfShowing() {
        AlarmActivity a = current.get();
        if (a != null && !a.isFinishing()) {
            a.runOnUiThread(a::finish);
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        current = new WeakReference<>(this);
        showOverLockScreen();
        render(getIntent());
        if (getIntent().getBooleanExtra(EXTRA_PLAY_SOUND, false)) startSound();
        // 10 dakika sonra kendiliğinden kapan
        handler.postDelayed(this::finish, 10 * 60 * 1000L);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        render(intent);
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        stopSound();
        if (current.get() == this) current = new WeakReference<>(null);
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        // Geri tuşu alarmı kapatmaz; kullanıcı bir seçim yapmalı
        moveTaskToBack(true);
    }

    private void showOverLockScreen() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
            KeyguardManager km = (KeyguardManager) getSystemService(Context.KEYGUARD_SERVICE);
            if (km != null) {
                try { km.requestDismissKeyguard(this, null); } catch (Exception ignored) {}
            }
        } else {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED
                    | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
                    | WindowManager.LayoutParams.FLAG_DISMISS_KEYGUARD);
        }
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        Window w = getWindow();
        w.setStatusBarColor(Color.parseColor("#0B1220"));
        w.setNavigationBarColor(Color.parseColor("#0B1220"));
    }

    private int dp(float v) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, getResources().getDisplayMetrics());
    }

    private void render(final Intent data) {
        String title = data.getStringExtra(AlarmScheduler.EXTRA_TITLE);
        String body = data.getStringExtra(AlarmScheduler.EXTRA_BODY);
        final boolean trackable = data.getBooleanExtra(AlarmScheduler.EXTRA_TRACKABLE, false);
        int snooze = AlarmStore.getSnoozeMinutes(this);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER_HORIZONTAL);
        root.setPadding(dp(28), dp(64), dp(28), dp(40));
        GradientDrawable bg = new GradientDrawable(GradientDrawable.Orientation.TOP_BOTTOM,
                new int[]{Color.parseColor("#0B1220"), Color.parseColor("#0C2A22")});
        root.setBackground(bg);

        TextView badge = new TextView(this);
        badge.setText("OptiLifeSync");
        badge.setTextColor(Color.parseColor("#6EE7B7"));
        badge.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14);
        badge.setTypeface(Typeface.DEFAULT_BOLD);
        badge.setLetterSpacing(0.08f);
        root.addView(badge);

        TextView clock = new TextView(this);
        clock.setText(new SimpleDateFormat("HH:mm", Locale.getDefault()).format(new Date()));
        clock.setTextColor(Color.WHITE);
        clock.setTextSize(TypedValue.COMPLEX_UNIT_SP, 84);
        clock.setTypeface(Typeface.create("sans-serif-light", Typeface.NORMAL));
        clock.setPadding(0, dp(28), 0, dp(4));
        root.addView(clock);

        TextView t = new TextView(this);
        t.setText(title == null || title.isEmpty() ? "⏰ Hatırlatıcı" : title);
        t.setTextColor(Color.WHITE);
        t.setTextSize(TypedValue.COMPLEX_UNIT_SP, 26);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        t.setGravity(Gravity.CENTER);
        t.setPadding(0, dp(12), 0, dp(8));
        root.addView(t);

        TextView b = new TextView(this);
        b.setText(body == null ? "" : body);
        b.setTextColor(Color.parseColor("#C3CDDC"));
        b.setTextSize(TypedValue.COMPLEX_UNIT_SP, 18);
        b.setGravity(Gravity.CENTER);
        root.addView(b);

        View spacer = new View(this);
        root.addView(spacer, new LinearLayout.LayoutParams(1, 0, 1f));

        if (trackable) {
            root.addView(button("✅  Aldım", "#10B981", Color.WHITE, v -> act(AlarmActions.ACTION_TAKEN, data)), btnParams(64));
        }
        root.addView(button("⏰  " + snooze + " dakika ertele", "#1E293B", Color.WHITE, v -> act(AlarmActions.ACTION_SNOOZE, data)), btnParams(58));
        root.addView(button(trackable ? "Bu dozu atla" : "Kapat", "#00000000", Color.parseColor("#94A3B8"),
                v -> act(trackable ? AlarmActions.ACTION_SKIP : AlarmActions.ACTION_DISMISS, data)), btnParams(52));

        setContentView(root);
    }

    private LinearLayout.LayoutParams btnParams(int heightDp) {
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(heightDp));
        lp.topMargin = dp(12);
        return lp;
    }

    private Button button(String text, String bgColor, int fg, View.OnClickListener l) {
        Button btn = new Button(this);
        btn.setText(text);
        btn.setAllCaps(false);
        btn.setTextColor(fg);
        btn.setTextSize(TypedValue.COMPLEX_UNIT_SP, 18);
        btn.setTypeface(Typeface.DEFAULT_BOLD);
        GradientDrawable d = new GradientDrawable();
        d.setColor(Color.parseColor(bgColor));
        d.setCornerRadius(dp(20));
        if ("#00000000".equals(bgColor)) d.setStroke(dp(1), Color.parseColor("#334155"));
        btn.setBackground(d);
        btn.setStateListAnimator(null);
        btn.setOnClickListener(l);
        return btn;
    }

    private void act(String action, Intent data) {
        stopSound();
        AlarmActions.handle(this, action, data, null);
        finish();
    }

    private void startSound() {
        try {
            player = new MediaPlayer();
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
                player.setAudioAttributes(new AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_ALARM)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build());
            } else {
                player.setAudioStreamType(AudioManager.STREAM_ALARM);
            }
            player.setDataSource(this, AlarmNotifier.alarmSound());
            player.setLooping(true);
            player.prepare();
            player.start();
        } catch (Exception e) {
            stopSound();
        }
    }

    private void stopSound() {
        if (player != null) {
            try { player.stop(); } catch (Exception ignored) {}
            try { player.release(); } catch (Exception ignored) {}
            player = null;
        }
    }
}
