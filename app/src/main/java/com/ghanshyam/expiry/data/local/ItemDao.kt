package com.ghanshyam.expiry.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Transaction
import androidx.room.Update
import kotlinx.coroutines.flow.Flow

/**
 * An abstract class rather than an interface: the `@Transaction` methods below
 * have bodies, and Room's handling of those is only unconditional for abstract
 * classes — on an interface it depends on how JVM default methods happen to be
 * compiled.
 */
@Dao
abstract class ItemDao {

    @Query("SELECT * FROM items ORDER BY expires_on_epoch_day ASC, title COLLATE NOCASE ASC")
    abstract fun observeAll(): Flow<List<ItemEntity>>

    @Query("SELECT * FROM items WHERE id = :id")
    abstract fun observeById(id: Long): Flow<ItemEntity?>

    @Query("SELECT * FROM items ORDER BY expires_on_epoch_day ASC")
    abstract suspend fun getAll(): List<ItemEntity>

    @Query("SELECT * FROM items WHERE id = :id")
    abstract suspend fun getById(id: Long): ItemEntity?

    @Insert(onConflict = OnConflictStrategy.ABORT)
    abstract suspend fun insert(entity: ItemEntity): Long

    @Update
    abstract suspend fun update(entity: ItemEntity)

    @Query("DELETE FROM items WHERE id = :id")
    abstract suspend fun deleteById(id: Long)

    @Query("DELETE FROM items")
    abstract suspend fun deleteAll()

    @Query("UPDATE items SET notified_offsets = :offsets WHERE id = :id")
    abstract suspend fun setNotifiedOffsets(id: Long, offsets: String)

    /**
     * Room's `@Upsert` keys off the primary key, and a new item carries the
     * sentinel id 0. Branching on it explicitly is unambiguous and returns the
     * row id either way, which callers need in order to reference the item.
     */
    @Transaction
    open suspend fun upsert(entity: ItemEntity): Long =
        if (entity.id == 0L) {
            insert(entity)
        } else {
            update(entity)
            entity.id
        }

    /**
     * Used by backup restore. Ids are dropped so that restoring onto a device
     * that already has rows cannot collide with them.
     */
    @Transaction
    open suspend fun replaceAll(entities: List<ItemEntity>) {
        deleteAll()
        entities.forEach { insert(it.copy(id = 0L)) }
    }
}
