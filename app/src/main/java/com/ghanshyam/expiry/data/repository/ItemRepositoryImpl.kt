package com.ghanshyam.expiry.data.repository

import com.ghanshyam.expiry.data.local.ItemDao
import com.ghanshyam.expiry.data.local.encodeOffsets
import com.ghanshyam.expiry.data.local.toDomain
import com.ghanshyam.expiry.data.local.toEntity
import com.ghanshyam.expiry.di.IoDispatcher
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.ghanshyam.expiry.domain.repository.ItemRepository
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flowOn
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import java.time.Clock
import java.time.Instant
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class ItemRepositoryImpl @Inject constructor(
    private val dao: ItemDao,
    @IoDispatcher private val io: CoroutineDispatcher,
    private val clock: Clock,
) : ItemRepository {

    override fun observeAll(): Flow<List<TrackedItem>> =
        dao.observeAll().map { entities -> entities.map { it.toDomain() } }.flowOn(io)

    override fun observeById(id: Long): Flow<TrackedItem?> =
        dao.observeById(id).map { it?.toDomain() }.flowOn(io)

    override suspend fun getAll(): List<TrackedItem> = withContext(io) {
        dao.getAll().map { it.toDomain() }
    }

    override suspend fun getById(id: Long): TrackedItem? = withContext(io) {
        dao.getById(id)?.toDomain()
    }

    override suspend fun save(item: TrackedItem): Long = withContext(io) {
        val now = Instant.now(clock)
        val existing = if (item.id == TrackedItem.NO_ID) null else dao.getById(item.id)

        // Moving the date means the old warnings no longer describe this item —
        // a renewed passport should warn again on the new schedule, so the
        // record of what has already been sent is dropped.
        val dateChanged = existing != null && existing.expiresOnEpochDay != item.expiresOn.toEpochDay()
        val normalised = item.normalised().let { candidate ->
            if (dateChanged) candidate.copy(notifiedOffsetsDays = emptySet()) else candidate
        }

        dao.upsert(
            normalised.toEntity().copy(
                createdAtEpochMillis = existing?.createdAtEpochMillis ?: now.toEpochMilli(),
                updatedAtEpochMillis = now.toEpochMilli(),
            ),
        )
    }

    override suspend fun delete(id: Long) = withContext(io) {
        dao.deleteById(id)
    }

    override suspend fun markNotified(id: Long, offsets: Set<Int>) = withContext(io) {
        dao.setNotifiedOffsets(id, encodeOffsets(offsets.sortedDescending()))
    }

    override suspend fun replaceAll(items: List<TrackedItem>) = withContext(io) {
        val now = Instant.now(clock).toEpochMilli()
        dao.replaceAll(
            items.map { item ->
                item.normalised().toEntity().copy(
                    createdAtEpochMillis = item.createdAt
                        .takeIf { it != Instant.EPOCH }?.toEpochMilli() ?: now,
                    updatedAtEpochMillis = now,
                )
            },
        )
    }
}
