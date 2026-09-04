package com.ghanshyam.expiry.domain

import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.ghanshyam.expiry.domain.model.Urgency
import com.google.common.truth.Truth.assertThat
import org.junit.Test
import java.time.LocalDate

class TrackedItemTest {

    private val today = LocalDate.of(2026, 3, 1)

    private fun itemExpiring(on: LocalDate) = TrackedItem(title = "Thing", expiresOn = on)

    @Test
    fun `days until is negative once the date has passed`() {
        assertThat(itemExpiring(today.plusDays(10)).daysUntil(today)).isEqualTo(10)
        assertThat(itemExpiring(today).daysUntil(today)).isEqualTo(0)
        assertThat(itemExpiring(today.minusDays(4)).daysUntil(today)).isEqualTo(-4)
    }

    @Test
    fun `urgency buckets split at the documented boundaries`() {
        assertThat(itemExpiring(today.minusDays(1)).urgency(today)).isEqualTo(Urgency.EXPIRED)
        assertThat(itemExpiring(today).urgency(today)).isEqualTo(Urgency.THIS_WEEK)
        assertThat(itemExpiring(today.plusDays(7)).urgency(today)).isEqualTo(Urgency.THIS_WEEK)
        assertThat(itemExpiring(today.plusDays(8)).urgency(today)).isEqualTo(Urgency.THIS_MONTH)
        assertThat(itemExpiring(today.plusDays(31)).urgency(today)).isEqualTo(Urgency.THIS_MONTH)
        assertThat(itemExpiring(today.plusDays(32)).urgency(today)).isEqualTo(Urgency.LATER)
    }

    @Test
    fun `normalising trims text and canonicalises the reminder schedule`() {
        val messy = TrackedItem(
            title = "  Passport  ",
            expiresOn = today,
            notes = "  renew early\n",
            reminderOffsetsDays = listOf(7, 30, 7, -5, 0),
        )

        val clean = messy.normalised()

        assertThat(clean.title).isEqualTo("Passport")
        assertThat(clean.notes).isEqualTo("renew early")
        // Duplicates dropped, negatives dropped, furthest warning first.
        assertThat(clean.reminderOffsetsDays).containsExactly(30, 7, 0).inOrder()
    }

    @Test
    fun `an unknown category id falls back to other rather than throwing`() {
        // A backup written by a future version could name a category this
        // build has never heard of.
        assertThat(Category.fromId("teleporter-licence")).isEqualTo(Category.OTHER)
        assertThat(Category.fromId("identity")).isEqualTo(Category.IDENTITY)
    }
}
