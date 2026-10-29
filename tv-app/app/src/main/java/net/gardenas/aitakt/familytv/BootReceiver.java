package net.gardenas.aitakt.familytv;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * 電源を入れたら家族カレンダーを開く。
 * Android 10 以降はアプリが自分で画面を開くのが制限されているので、
 * adb shell appops set net.gardenas.aitakt.familytv SYSTEM_ALERT_WINDOW allow
 * で許可したときだけ動く（許可しなければ何も起きない）。
 */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction())) {
            return;
        }
        Intent open = new Intent(context, MainActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        try {
            context.startActivity(open);
        } catch (RuntimeException e) {
            // 許可がなければ開けない。ホーム画面から開けばよい
        }
    }
}
