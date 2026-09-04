package com.ghanshyam.expiry.domain.model

import java.time.Instant
import java.time.LocalDate
import java.time.temporal.ChronoUnit

/**
 * Something that runs out on a date: a passport, an insurance policy, a
 * warranty, a subscription's renewal.
 *
 * Dates are [LocalDate] rather than instants on purpose. An expiry date is a
 * calendar fact printed on a document, not a moment in time — a passport that
 * expires on 4 March expires on 4 March wherever the holder happens to be, so
 * it must not shift when the device changes time zone.
 */
data class TrackedItem(
    val id: Long = NO_ID,
    val title: String,
    val category: Category = Category.OTHER,
    val expiresOn: LocalDate,
    val notes: String = "",
    /**
     * How many days before [expiresOn] to send a reminder. `0` means on the day
     * itself. Kept sorted descending (furthest warning first) by [normalised].
     */
    val reminderOffsetsDays: List<Int> = DEFAULT_REMINDER_OFFSETS,
    /**
     * Offsets already notified for the current [expiresOn]. Cleared whenever the
     * date changes so a renewed item warns again on the new schedule.
     */
    val notifiedOffsetsDays: Set<Int> = emptySet(),
    val createdAt: Instant = Instant.EPOCH,
    val updatedAt: Instant = Instant.EPOCH,
) {
    /** Negative once the item has expired. */
    fun daysUntil(today: LocalDate): Long = ChronoUnit.DAYS.between(today, expiresOn)

    fun urgency(today: LocalDate): Urgency = Urgency.of(daysUntil(today))

    /**
     * Trims the title, drops offsets that are negative or duplicated, and sorts
     * what is left. Call before persisting so the stored shape is always
     * canonical and comparisons in tests and the UI stay predictable.
     */
    fun normalised(): TrackedItem = copy(
        title = title.trim(),
        notes = notes.trim(),
        reminderOffsetsDays = reminderOffsetsDays
            .filter { it >= 0 }
            .distinct()
            .sortedDescending(),
        notifiedOffsetsDays = notifiedOffsetsDays.filter { it >= 0 }.toSet(),
    )

    companion object {
        const val NO_ID: Long = 0L

        /** A month's warning, a week's warning, then the day itself. */
        val DEFAULT_REMINDER_OFFSETS: List<Int> = listOf(30, 7, 0)

        /** Offsets offered in the editor and settings. */
        val SELECTABLE_REMINDER_OFFSETS: List<Int> = listOf(180, 90, 60, 30, 14, 7, 3, 1, 0)
    }
}

/** Coarse buckets the item list groups by. */
enum class Urgency {
    EXPIRED,
    THIS_WEEK,
    THIS_MONTH,
    LATER;

    companion object {
        fun of(daysUntil: Long): Urgency = when {
            daysUntil < 0 -> EXPIRED
            daysUntil <= 7 -> THIS_WEEK
            daysUntil <= 31 -> THIS_MONTH
            else -> LATER
        }
    }
}
