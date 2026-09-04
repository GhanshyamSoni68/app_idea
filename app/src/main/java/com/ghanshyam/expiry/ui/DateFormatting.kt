package com.ghanshyam.expiry.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import com.ghanshyam.expiry.R
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * "Expires in 12 days" rather than a bare date: days remaining is the thing
 * the user actually needs, and it matches what the notification says.
 */
@Composable
fun relativeExpiry(daysUntil: Long): String = when {
    daysUntil < 0L -> {
        val ago = (-daysUntil).toInt()
        if (ago == 0) stringResource(R.string.expired_today)
        else pluralStringResource(R.plurals.expired_days_ago, ago, ago)
    }

    daysUntil == 0L -> stringResource(R.string.expires_today)
    daysUntil == 1L -> stringResource(R.string.expires_tomorrow)
    else -> pluralStringResource(R.plurals.expires_in_days, daysUntil.toInt(), daysUntil.toInt())
}

/** The absolute date, written the way the user's locale writes dates. */
@Composable
fun formatDate(date: LocalDate): String {
    val locale = currentLocale()
    val formatter = remember(locale) {
        DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale)
    }
    return remember(date, formatter) { formatter.format(date) }
}

@Composable
fun currentLocale(): Locale {
    val configuration = LocalConfiguration.current
    return configuration.locales[0] ?: Locale.getDefault()
}
