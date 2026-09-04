package com.ghanshyam.expiry.reminder

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.ghanshyam.expiry.MainActivity
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.domain.model.TrackedItem
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class Notifier @Inject constructor(
    private val context: Context,
) {
    private val manager = NotificationManagerCompat.from(context)

    fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val channel = NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = context.getString(R.string.notification_channel_description)
            setShowBadge(true)
        }
        manager.createNotificationChannel(channel)
    }

    fun canPost(): Boolean =
        manager.areNotificationsEnabled() &&
            (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU || hasPostPermission())

    private fun hasPostPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED

    /**
     * Posts one notification for [item]. Returns false when the post was
     * refused, so the caller can leave the reminder unmarked and try again on
     * the next run rather than silently swallowing it.
     */
    fun notifyExpiring(item: TrackedItem, daysUntil: Long): Boolean {
        if (!canPost()) return false

        val openApp = PendingIntent.getActivity(
            context,
            item.id.toInt(),
            Intent(context, MainActivity::class.java)
                .setAction(Intent.ACTION_VIEW)
                .putExtra(MainActivity.EXTRA_ITEM_ID, item.id)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(item.title)
            .setContentText(relativeText(daysUntil))
            .setStyle(NotificationCompat.BigTextStyle().bigText(bigText(item, daysUntil)))
            .setContentIntent(openApp)
            .setAutoCancel(true)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .build()

        return try {
            manager.notify(item.id.toInt(), notification)
            true
        } catch (securityException: SecurityException) {
            // Permission can be revoked between the check above and the post.
            false
        }
    }

    private fun relativeText(daysUntil: Long): String = with(context) {
        when {
            daysUntil < 0L -> {
                val ago = -daysUntil
                if (ago == 0L) getString(R.string.expired_today)
                else resources.getQuantityString(R.plurals.expired_days_ago, ago.toInt(), ago.toInt())
            }

            daysUntil == 0L -> getString(R.string.expires_today)
            daysUntil == 1L -> getString(R.string.expires_tomorrow)
            else -> resources.getQuantityString(
                R.plurals.expires_in_days,
                daysUntil.toInt(),
                daysUntil.toInt(),
            )
        }
    }

    private fun bigText(item: TrackedItem, daysUntil: Long): String {
        val relative = relativeText(daysUntil)
        return if (item.notes.isBlank()) relative else "$relative\n${item.notes}"
    }

    private companion object {
        const val CHANNEL_ID = "expiry_reminders"
    }
}
