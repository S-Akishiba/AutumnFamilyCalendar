package net.gardenas.aitakt.familytv;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.graphics.Color;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

/**
 * 家族カレンダーのテレビ表示（tv.php）を全画面で出し続けるだけのアプリ。
 * 画面は消さない（スクリーンセーバーにも切り替わらない）。つながらなければ 30 秒ごとにやり直す。
 * 新しい版は起動時と 6 時間ごとに確かめる（Updater）。
 * リモコン: 決定ボタン＝読み直し、決定ボタン長押し＝新しい版を確かめる、戻るボタン 2 回＝終了。
 */
public class MainActivity extends Activity {

    private static final String HOST = Uri.parse(BuildConfig.TV_URL).getHost();
    private static final long RETRY_MS = 30_000;
    private static final long UPDATE_CHECK_MS = 6L * 60 * 60 * 1000;

    private WebView web;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable retry = () -> web.loadUrl(BuildConfig.TV_URL);
    private long lastBack = 0;
    private boolean longPressed = false;
    private Updater updater;
    private final Runnable updateCheck = new Runnable() {
        @Override
        public void run() {
            updater.check(false);
            handler.postDelayed(this, UPDATE_CHECK_MS);
        }
    };

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        updater = new Updater(this);

        web = new WebView(this);
        web.setBackgroundColor(Color.rgb(0x0d, 0x11, 0x17));
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true); // つないだときの鍵は localStorage に保存される
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        CookieManager.getInstance().setAcceptCookie(true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                // 家族カレンダーの中だけ動く（外のページには行かない）
                return !HOST.equals(request.getUrl().getHost());
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    showOffline();
                }
            }
        });

        setContentView(web);
        hideSystemUi();
        if (savedInstanceState == null) {
            web.loadUrl(BuildConfig.TV_URL);
        } else {
            web.restoreState(savedInstanceState);
        }
    }

    private void showOffline() {
        String html = "<html><body style='margin:0;height:100%;display:flex;align-items:center;justify-content:center;"
                + "background:#0d1117;color:#8b949e;font:3vw sans-serif'>"
                + "家族カレンダーに接続しています…（30 秒ごとにやり直します）</body></html>";
        web.loadDataWithBaseURL(null, html, "text/html", "utf-8", null);
        handler.removeCallbacks(retry);
        handler.postDelayed(retry, RETRY_MS);
    }

    @SuppressWarnings("deprecation")
    private void hideSystemUi() {
        getWindow().getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION);
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) {
            if (event.getRepeatCount() == 0) {
                longPressed = false;
                event.startTracking(); // 長押しを見分ける（離したときに読み直す）
            }
            return true;
        }
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            long now = System.currentTimeMillis();
            if (now - lastBack < 2000) {
                finish();
            } else {
                lastBack = now;
                Toast.makeText(this, "もう一度押すと終了します", Toast.LENGTH_SHORT).show();
            }
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    public boolean onKeyLongPress(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) {
            longPressed = true;
            Toast.makeText(this, "新しい版を確かめています（今は " + BuildConfig.VERSION_NAME + "）", Toast.LENGTH_SHORT).show();
            updater.check(true);
            return true;
        }
        return super.onKeyLongPress(keyCode, event);
    }

    @Override
    public boolean onKeyUp(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER) {
            if (!longPressed && !event.isCanceled()) {
                handler.removeCallbacks(retry);
                web.loadUrl(BuildConfig.TV_URL);
                Toast.makeText(this, "読み直しています", Toast.LENGTH_SHORT).show();
            }
            longPressed = false;
            return true;
        }
        return super.onKeyUp(keyCode, event);
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            hideSystemUi();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        web.onResume();
        web.resumeTimers();
        handler.removeCallbacks(updateCheck);
        handler.postDelayed(updateCheck, 5_000); // 起動して少し待ってから
    }

    @Override
    protected void onPause() {
        web.onPause();
        web.pauseTimers();
        handler.removeCallbacks(updateCheck);
        CookieManager.getInstance().flush();
        super.onPause();
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        web.destroy();
        super.onDestroy();
    }
}
