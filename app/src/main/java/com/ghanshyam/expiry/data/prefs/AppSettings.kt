package com.ghanshyam.expiry.data.prefs

import com.ghanshyam.expiry.domain.model.TrackedItem
import java.time.LocalTime

data class AppSettings(
    /** Require a biometric or device credential each time the app is opened. */
    val appLockEnabled: Boolean = false,
    /** Local time of day the reminder check runs and notifications are posted. */
    val reminderTime: LocalTime = LocalTime.of(9, 0),
    /** Pre-filled reminder schedule for newly added items. */
    val defaultReminderOffsets: List<Int> = TrackedItem.DEFAULT_REMINDER_OFFSETS,
)
