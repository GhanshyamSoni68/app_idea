package com.ghanshyam.expiry.reminder

import com.ghanshyam.expiry.domain.model.TrackedItem
import java.time.LocalDate
import javax.inject.Inject
import javax.inject.Singleton

/**
 * A notification that should be posted now, plus the offsets to record as sent
 * so it is not posted again tomorrow.
 */
data class DueReminder(
    val item: TrackedItem,
    val offsetsToMark: Set<Int>,
    val daysUntil: Long,
)

/**
 * Decides which items are due a reminder today.
 *
 * Pure and free of Android types so the rules can be tested against fixed
 * dates. Two behaviours are worth spelling out:
 *
 * - **Catch-up, not spam.** If the device was off for a fortnight, every offset
 *   whose window has since opened counts as due, but they collapse into a
 *   single notification per item that states the *real* number of days left.
 *   Posting a stale "30 days to go" when 16 remain would be worse than useless.
 * - **One expiry notice.** Once the date passes, a single notice goes out and
 *   every remaining offset is marked sent, so a long-expired item cannot
 *   generate a fresh alert on every run.
 */
@Singleton
class ReminderEvaluator @Inject constructor() {

    fun evaluate(items: List<TrackedItem>, today: LocalDate): List<DueReminder> =
        items.mapNotNull { item -> evaluateOne(item, today) }

    private fun evaluateOne(item: TrackedItem, today: LocalDate): DueReminder? {
        val daysUntil = item.daysUntil(today)
        val alreadySent = item.notifiedOffsetsDays

        val due: Set<Int> = if (daysUntil >= 0) {
            // An offset of N means "warn me when N days remain", so its window
            // is open once the remaining days have fallen to N or below.
            item.reminderOffsetsDays
                .filter { offset -> offset >= daysUntil && offset !in alreadySent }
                .toSet()
        } else {
            if (EXPIRED_NOTICE in alreadySent) {
                emptySet()
            } else {
                // Retire the whole schedule alongside the expiry notice.
                item.reminderOffsetsDays.toSet() + EXPIRED_NOTICE
            }
        }

        return if (due.isEmpty()) null else DueReminder(item, due, daysUntil)
    }

    companion object {
        /**
         * Pseudo-offset recorded once the "this has expired" notice has been
         * sent. Real offsets are days-before-expiry, so they are non-negative
         * and small; the maximum int shares that non-negative space without
         * colliding with any schedule a user could choose.
         */
        const val EXPIRED_NOTICE: Int = Int.MAX_VALUE
    }
}
