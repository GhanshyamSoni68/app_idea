package com.ghanshyam.expiry.core.time

import java.time.LocalDate
import java.time.ZoneId

/**
 * Indirection over "what day is it", so reminder and urgency logic can be
 * tested against fixed dates instead of whatever the build machine thinks
 * today is.
 */
interface AppClock {
    fun today(): LocalDate
    fun zone(): ZoneId
}

class SystemAppClock : AppClock {
    override fun today(): LocalDate = LocalDate.now(zone())
    override fun zone(): ZoneId = ZoneId.systemDefault()
}

/** Test double; also handy for previews. */
class FixedAppClock(
    private val date: LocalDate,
    private val zone: ZoneId = ZoneId.of("UTC"),
) : AppClock {
    override fun today(): LocalDate = date
    override fun zone(): ZoneId = zone
}
