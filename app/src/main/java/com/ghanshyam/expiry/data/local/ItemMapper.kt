package com.ghanshyam.expiry.data.local

import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import java.time.Instant
import java.time.LocalDate

/**
 * Offsets are persisted as a comma-separated list. It keeps the schema flat
 * and greppable, and the values are a handful of small non-negative integers,
 * so a relation of its own would cost a join for nothing.
 */
internal fun encodeOffsets(offsets: Collection<Int>): String = offsets.joinToString(",")

internal fun decodeOffsets(raw: String): List<Int> =
    raw.split(',')
        .mapNotNull { it.trim().toIntOrNull() }
        .filter { it >= 0 }

fun ItemEntity.toDomain(): TrackedItem = TrackedItem(
    id = id,
    title = title,
    category = Category.fromId(categoryId),
    expiresOn = LocalDate.ofEpochDay(expiresOnEpochDay),
    notes = notes,
    reminderOffsetsDays = decodeOffsets(reminderOffsets).sortedDescending(),
    notifiedOffsetsDays = decodeOffsets(notifiedOffsets).toSet(),
    createdAt = Instant.ofEpochMilli(createdAtEpochMillis),
    updatedAt = Instant.ofEpochMilli(updatedAtEpochMillis),
)

fun TrackedItem.toEntity(): ItemEntity = ItemEntity(
    id = id,
    title = title,
    categoryId = category.id,
    expiresOnEpochDay = expiresOn.toEpochDay(),
    notes = notes,
    reminderOffsets = encodeOffsets(reminderOffsetsDays),
    notifiedOffsets = encodeOffsets(notifiedOffsetsDays),
    createdAtEpochMillis = createdAt.toEpochMilli(),
    updatedAtEpochMillis = updatedAt.toEpochMilli(),
)
