package com.ghanshyam.expiry.data.prefs

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.ghanshyam.expiry.domain.model.TrackedItem
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import java.time.LocalTime
import javax.inject.Inject
import javax.inject.Singleton

private val Context.settingsDataStore: DataStore<Preferences> by preferencesDataStore(name = "settings")

@Singleton
class SettingsRepository @Inject constructor(
    private val context: Context,
) {
    val settings: Flow<AppSettings> = context.settingsDataStore.data.map { prefs ->
        AppSettings(
            appLockEnabled = prefs[KeyAppLock] ?: false,
            reminderTime = LocalTime.of(
                (prefs[KeyReminderHour] ?: DEFAULT_HOUR).coerceIn(0, 23),
                (prefs[KeyReminderMinute] ?: 0).coerceIn(0, 59),
            ),
            defaultReminderOffsets = prefs[KeyDefaultOffsets]
                ?.split(',')
                ?.mapNotNull { it.trim().toIntOrNull() }
                ?.filter { it >= 0 }
                ?.sortedDescending()
                ?.takeIf { it.isNotEmpty() }
                ?: TrackedItem.DEFAULT_REMINDER_OFFSETS,
        )
    }

    suspend fun setAppLockEnabled(enabled: Boolean) {
        context.settingsDataStore.edit { it[KeyAppLock] = enabled }
    }

    suspend fun setReminderTime(time: LocalTime) {
        context.settingsDataStore.edit {
            it[KeyReminderHour] = time.hour
            it[KeyReminderMinute] = time.minute
        }
    }

    suspend fun setDefaultReminderOffsets(offsets: List<Int>) {
        val sanitised = offsets.filter { it >= 0 }.distinct().sortedDescending()
        context.settingsDataStore.edit {
            it[KeyDefaultOffsets] = sanitised.joinToString(",")
        }
    }

    private companion object {
        const val DEFAULT_HOUR = 9
        val KeyAppLock = booleanPreferencesKey("app_lock_enabled")
        val KeyReminderHour = intPreferencesKey("reminder_hour")
        val KeyReminderMinute = intPreferencesKey("reminder_minute")
        val KeyDefaultOffsets = stringPreferencesKey("default_reminder_offsets")
    }
}
