package com.ghanshyam.expiry.reminder

import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import dagger.Lazy
import com.ghanshyam.expiry.core.time.AppClock
import java.time.Duration
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.ZonedDateTime
import java.util.concurrent.TimeUnit
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Runs [ReminderWorker] once a day at the user's chosen local time.
 *
 * Periodic work, not an exact alarm: a reminder that a document expires in a
 * week does not need to land on the second, and exact alarms would mean
 * requesting SCHEDULE_EXACT_ALARM, which Google Play only grants to apps whose
 * core purpose is alarms and clocks. Work that is a few minutes late is fine;
 * a rejected release is not.
 */
@Singleton
class ReminderScheduler @Inject constructor(
    /**
     * Lazy on purpose. `WorkManager.getInstance` reads the configuration off
     * the Application, and this scheduler is itself injected into that
     * Application — resolving it eagerly would ask for the WorkManager while
     * the object that configures it is still being built.
     */
    private val workManagerProvider: Lazy<WorkManager>,
    private val clock: AppClock,
) {
    private val workManager: WorkManager get() = workManagerProvider.get()

    fun schedule(at: LocalTime) {
        val request = PeriodicWorkRequestBuilder<ReminderWorker>(1, TimeUnit.DAYS)
            .setInitialDelay(delayUntilNext(at).toMinutes(), TimeUnit.MINUTES)
            .setConstraints(Constraints.NONE)
            .addTag(TAG)
            .build()

        workManager.enqueueUniquePeriodicWork(
            UNIQUE_NAME,
            // UPDATE keeps the existing work's history while applying the new
            // schedule; REPLACE would restart the period and could skip a day.
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    /** Runs the check immediately, for the "test reminders" affordance. */
    fun runNow() {
        workManager.enqueue(
            androidx.work.OneTimeWorkRequestBuilder<ReminderWorker>()
                .addTag(TAG)
                .build(),
        )
    }

    internal fun delayUntilNext(at: LocalTime): Duration {
        val zone = clock.zone()
        val now = ZonedDateTime.now(zone)
        var next = LocalDateTime.of(clock.today(), at).atZone(zone)
        if (!next.isAfter(now)) next = next.plusDays(1)
        return Duration.between(now, next)
    }

    private companion object {
        const val UNIQUE_NAME = "expiry_daily_reminders"
        const val TAG = "reminders"
    }
}
