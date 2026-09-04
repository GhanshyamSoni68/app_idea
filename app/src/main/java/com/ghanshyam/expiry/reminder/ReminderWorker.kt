package com.ghanshyam.expiry.reminder

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.ghanshyam.expiry.core.time.AppClock
import com.ghanshyam.expiry.domain.repository.ItemRepository
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

/**
 * Daily sweep: work out what is due, post one notification per item, and
 * record what was sent.
 */
@HiltWorker
class ReminderWorker @AssistedInject constructor(
    @Assisted context: Context,
    @Assisted params: WorkerParameters,
    private val repository: ItemRepository,
    private val evaluator: ReminderEvaluator,
    private val notifier: Notifier,
    private val clock: AppClock,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        notifier.ensureChannel()

        // Nothing can be delivered, so leave every reminder unmarked and retry.
        // Marking them sent here would silently burn the user's only warning.
        if (!notifier.canPost()) return Result.retry()

        return try {
            val due = evaluator.evaluate(repository.getAll(), clock.today())
            for (reminder in due) {
                val posted = notifier.notifyExpiring(reminder.item, reminder.daysUntil)
                if (!posted) return Result.retry()
                repository.markNotified(
                    id = reminder.item.id,
                    offsets = reminder.item.notifiedOffsetsDays + reminder.offsetsToMark,
                )
            }
            Result.success()
        } catch (error: Exception) {
            // Transient database or notification failures are worth one retry;
            // WorkManager backs off and gives up on its own after that.
            Result.retry()
        }
    }
}
