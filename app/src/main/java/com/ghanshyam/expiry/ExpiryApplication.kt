package com.ghanshyam.expiry

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import com.ghanshyam.expiry.data.prefs.SettingsRepository
import com.ghanshyam.expiry.di.ApplicationScope
import com.ghanshyam.expiry.reminder.Notifier
import com.ghanshyam.expiry.reminder.ReminderScheduler
import dagger.hilt.android.HiltAndroidApp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import javax.inject.Inject

@HiltAndroidApp
class ExpiryApplication : Application(), Configuration.Provider {

    @Inject lateinit var workerFactory: HiltWorkerFactory

    @Inject lateinit var notifier: Notifier

    @Inject lateinit var scheduler: ReminderScheduler

    @Inject lateinit var settings: SettingsRepository

    @Inject @ApplicationScope lateinit var appScope: CoroutineScope

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setWorkerFactory(workerFactory)
            .build()

    override fun onCreate() {
        super.onCreate()
        notifier.ensureChannel()

        // Re-asserting the schedule on every launch is cheap (WorkManager keeps
        // the existing periodic work) and covers the cases where it can be
        // lost: an app update, a cleared work database, an aggressive OEM
        // battery manager.
        appScope.launch {
            scheduler.schedule(settings.settings.first().reminderTime)
        }
    }
}
