package com.ghanshyam.expiry.reminder

import com.ghanshyam.expiry.domain.model.TrackedItem
import com.google.common.truth.Truth.assertThat
import org.junit.Test
import java.time.LocalDate

class ReminderEvaluatorTest {

    private val evaluator = ReminderEvaluator()
    private val today = LocalDate.of(2026, 3, 1)

    private fun item(
        daysAway: Long,
        offsets: List<Int> = listOf(30, 7, 0),
        notified: Set<Int> = emptySet(),
    ) = TrackedItem(
        id = 1L,
        title = "Passport",
        expiresOn = today.plusDays(daysAway),
        reminderOffsetsDays = offsets,
        notifiedOffsetsDays = notified,
    )

    @Test
    fun `nothing is due while every window is still closed`() {
        assertThat(evaluator.evaluate(listOf(item(daysAway = 60)), today)).isEmpty()
    }

    @Test
    fun `an offset falls due on the day its window opens`() {
        val due = evaluator.evaluate(listOf(item(daysAway = 30)), today)

        assertThat(due).hasSize(1)
        assertThat(due.first().offsetsToMark).containsExactly(30)
        assertThat(due.first().daysUntil).isEqualTo(30)
    }

    @Test
    fun `an offset already sent does not fire again`() {
        val due = evaluator.evaluate(
            listOf(item(daysAway = 30, notified = setOf(30))),
            today,
        )

        assertThat(due).isEmpty()
    }

    @Test
    fun `windows missed while the device was off collapse into one reminder`() {
        // Nothing ran for a fortnight, so the 30-day and 7-day windows both
        // opened. That is one notification, not two.
        val due = evaluator.evaluate(listOf(item(daysAway = 5)), today)

        assertThat(due).hasSize(1)
        assertThat(due.first().offsetsToMark).containsExactly(30, 7)
        // The message must state the real figure, not the stale "30 days".
        assertThat(due.first().daysUntil).isEqualTo(5)
    }

    @Test
    fun `the day itself is covered by the zero offset`() {
        val due = evaluator.evaluate(
            listOf(item(daysAway = 0, notified = setOf(30, 7))),
            today,
        )

        assertThat(due.first().offsetsToMark).containsExactly(0)
        assertThat(due.first().daysUntil).isEqualTo(0)
    }

    @Test
    fun `an expired item is announced once and then retires its schedule`() {
        val expired = item(daysAway = -3)
        val due = evaluator.evaluate(listOf(expired), today)

        assertThat(due).hasSize(1)
        assertThat(due.first().daysUntil).isEqualTo(-3)
        assertThat(due.first().offsetsToMark).contains(ReminderEvaluator.EXPIRED_NOTICE)
        // Every remaining offset retires alongside it.
        assertThat(due.first().offsetsToMark).containsAtLeast(30, 7, 0)

        val afterNotifying = expired.copy(
            notifiedOffsetsDays = expired.notifiedOffsetsDays + due.first().offsetsToMark,
        )
        assertThat(evaluator.evaluate(listOf(afterNotifying), today)).isEmpty()
    }

    @Test
    fun `a long-expired item does not alert again on every run`() {
        val ancient = item(
            daysAway = -900,
            notified = setOf(30, 7, 0, ReminderEvaluator.EXPIRED_NOTICE),
        )

        assertThat(evaluator.evaluate(listOf(ancient), today)).isEmpty()
    }

    @Test
    fun `an item with no reminders configured stays silent until it expires`() {
        val silent = item(daysAway = 3, offsets = emptyList())
        assertThat(evaluator.evaluate(listOf(silent), today)).isEmpty()

        // The expiry notice is not part of the configurable schedule, so it
        // still goes out.
        val lapsed = item(daysAway = -1, offsets = emptyList())
        assertThat(evaluator.evaluate(listOf(lapsed), today)).hasSize(1)
    }

    @Test
    fun `only the items that are due come back`() {
        val due = evaluator.evaluate(
            listOf(
                item(daysAway = 200).copy(id = 1L),
                item(daysAway = 7).copy(id = 2L, notifiedOffsetsDays = setOf(30)),
                item(daysAway = 90).copy(id = 3L),
            ),
            today,
        )

        assertThat(due.map { it.item.id }).containsExactly(2L)
    }

    @Test
    fun `an empty list is handled`() {
        assertThat(evaluator.evaluate(emptyList(), today)).isEmpty()
    }
}
