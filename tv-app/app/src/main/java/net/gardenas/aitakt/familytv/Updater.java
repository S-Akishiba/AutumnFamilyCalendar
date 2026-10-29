package net.gardenas.aitakt.familytv;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageInstaller;
import android.net.Uri;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.widget.Toast;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.MessageDigest;

/**
 * 新しい版の確認と更新。
 * サーバーの familytv.json（publishApk が書く）の versionCode が今の版より大きければ「更新しますか？」と聞き、
 * APK をダウンロードして SHA-256 を確かめてから Android のインストーラーに渡す。
 * 初回だけ「このアプリからのインストールを許可」が必要（設定画面を開く）。
 */
final class Updater {

    private static final String PREFS = "updater";
    private static final String LATER_UNTIL = "later_until";
    private static final String LATER_VERSION = "later_version";
    private static final long LATER_MS = 24L * 60 * 60 * 1000;

    private final Activity activity;
    private final Handler main = new Handler(Looper.getMainLooper());
    private boolean busy = false;

    Updater(Activity activity) {
        this.activity = activity;
    }

    /** 新しい版を確かめる。manual なら「あとで」を無視し、新しい版がないときもそう伝える */
    void check(boolean manual) {
        if (busy) {
            return;
        }
        busy = true;
        new Thread(() -> {
            try {
                JSONObject info = new JSONObject(new String(download(BuildConfig.UPDATE_URL, 64 * 1024), "UTF-8"));
                int code = info.getInt("versionCode");
                main.post(() -> {
                    busy = false;
                    if (code <= BuildConfig.VERSION_CODE) {
                        if (manual) {
                            toast("今の版（" + BuildConfig.VERSION_NAME + "）が最新です");
                        }
                        return;
                    }
                    SharedPreferences p = activity.getSharedPreferences(PREFS, 0);
                    if (!manual && p.getInt(LATER_VERSION, 0) == code && System.currentTimeMillis() < p.getLong(LATER_UNTIL, 0)) {
                        return;
                    }
                    ask(info, code);
                });
            } catch (Exception e) {
                main.post(() -> {
                    busy = false;
                    if (manual) {
                        toast("新しい版を確かめられませんでした");
                    }
                });
            }
        }).start();
    }

    private void ask(JSONObject info, int code) {
        if (activity.isFinishing()) {
            return;
        }
        new AlertDialog.Builder(activity)
                .setTitle("新しい版があります")
                .setMessage("家族カレンダー " + info.optString("versionName") + "（今は " + BuildConfig.VERSION_NAME + "）\n更新しますか？")
                .setPositiveButton("更新する", (d, w) -> start(info))
                .setNegativeButton("あとで", (d, w) -> activity.getSharedPreferences(PREFS, 0).edit()
                        .putInt(LATER_VERSION, code)
                        .putLong(LATER_UNTIL, System.currentTimeMillis() + LATER_MS)
                        .apply())
                .show();
    }

    private void start(JSONObject info) {
        if (!activity.getPackageManager().canRequestPackageInstalls()) {
            // 初回だけ：このアプリからのインストールを許可してもらう
            new AlertDialog.Builder(activity)
                    .setTitle("更新の許可")
                    .setMessage("次の画面で「家族カレンダー」からのインストールを許可して、戻るボタンで戻ってください。")
                    .setPositiveButton("設定を開く", (d, w) -> {
                        try {
                            activity.startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                    Uri.parse("package:" + activity.getPackageName())));
                        } catch (RuntimeException e) {
                            toast("設定 → アプリ → セキュリティと制限 → 提供元不明のアプリ で「家族カレンダー」を ON にしてください");
                        }
                    })
                    .setNegativeButton("やめる", null)
                    .show();
            return;
        }
        toast("新しい版をダウンロードしています…");
        busy = true;
        new Thread(() -> {
            try {
                URL apkUrl = new URL(new URL(BuildConfig.UPDATE_URL), info.getString("url"));
                byte[] apk = download(apkUrl.toString(), 50 * 1024 * 1024);
                String sha = info.optString("sha256", "");
                if (!sha.isEmpty() && !sha.equalsIgnoreCase(hex(MessageDigest.getInstance("SHA-256").digest(apk)))) {
                    throw new IllegalStateException("ダウンロードしたファイルが壊れています");
                }
                install(apk);
                main.post(() -> busy = false);
            } catch (Exception e) {
                main.post(() -> {
                    busy = false;
                    toast("更新できませんでした：" + e.getMessage());
                });
            }
        }).start();
    }

    /** Android のインストーラーに渡す（確認の画面は InstallReceiver が出す） */
    private void install(byte[] apk) throws Exception {
        PackageInstaller installer = activity.getPackageManager().getPackageInstaller();
        PackageInstaller.SessionParams params = new PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL);
        params.setAppPackageName(activity.getPackageName());
        int id = installer.createSession(params);
        try (PackageInstaller.Session session = installer.openSession(id)) {
            try (OutputStream out = session.openWrite("familytv.apk", 0, apk.length)) {
                out.write(apk);
                session.fsync(out);
            }
            Intent done = new Intent(activity, InstallReceiver.class);
            PendingIntent pi = PendingIntent.getBroadcast(activity, id, done,
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_MUTABLE);
            session.commit(pi.getIntentSender());
        }
    }

    private static byte[] download(String url, int maxBytes) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setConnectTimeout(10_000);
        c.setReadTimeout(30_000);
        c.setUseCaches(false);
        c.setRequestProperty("Cache-Control", "no-cache");
        try {
            if (c.getResponseCode() != 200) {
                throw new IllegalStateException("HTTP " + c.getResponseCode());
            }
            try (InputStream in = c.getInputStream(); ByteArrayOutputStream buf = new ByteArrayOutputStream()) {
                byte[] b = new byte[16 * 1024];
                int n;
                while ((n = in.read(b)) > 0) {
                    buf.write(b, 0, n);
                    if (buf.size() > maxBytes) {
                        throw new IllegalStateException("ファイルが大きすぎます");
                    }
                }
                return buf.toByteArray();
            }
        } finally {
            c.disconnect();
        }
    }

    private static String hex(byte[] bytes) {
        StringBuilder sb = new StringBuilder();
        for (byte b : bytes) {
            sb.append(String.format("%02x", b));
        }
        return sb.toString();
    }

    private void toast(String msg) {
        Toast.makeText(activity, msg, Toast.LENGTH_LONG).show();
    }
}
