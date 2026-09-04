package com.ghanshyam.expiry.data.local

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "items")
data class ItemEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0L,
    @ColumnInfo(name = "title")
    val title: String,
    @ColumnInfo(name = "category_id")
    val categoryId: String,
    /**
     * Epoch day rather than a formatted string: it sorts and compares
     * correctly in SQL, and carries no time zone to go wrong.
     */
    @ColumnInfo(name = "expires_on_epoch_day", index = true)
    val expiresOnEpochDay: Long,
    @ColumnInfo(name = "notes")
    val notes: String,
    @ColumnInfo(name = "reminder_offsets")
    val reminderOffsets: String,
    @ColumnInfo(name = "notified_offsets")
    val notifiedOffsets: String,
    @ColumnInfo(name = "created_at_epoch_millis")
    val createdAtEpochMillis: Long,
    @ColumnInfo(name = "updated_at_epoch_millis")
    val updatedAtEpochMillis: Long,
)
