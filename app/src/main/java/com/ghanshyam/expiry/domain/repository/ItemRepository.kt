package com.ghanshyam.expiry.domain.repository

import com.ghanshyam.expiry.domain.model.TrackedItem
import kotlinx.coroutines.flow.Flow

interface ItemRepository {
    fun observeAll(): Flow<List<TrackedItem>>
    fun observeById(id: Long): Flow<TrackedItem?>
    suspend fun getAll(): List<TrackedItem>
    suspend fun getById(id: Long): TrackedItem?

    /** Inserts or updates, returning the item's row id. */
    suspend fun save(item: TrackedItem): Long
    suspend fun delete(id: Long)

    /** Records that [offsets] have been notified for the item's current date. */
    suspend fun markNotified(id: Long, offsets: Set<Int>)

    /** Wipes the table and inserts [items]. Used by backup restore. */
    suspend fun replaceAll(items: List<TrackedItem>)
}
