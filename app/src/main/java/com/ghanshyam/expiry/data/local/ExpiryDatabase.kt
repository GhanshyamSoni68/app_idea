package com.ghanshyam.expiry.data.local

import androidx.room.Database
import androidx.room.RoomDatabase

@Database(
    entities = [ItemEntity::class],
    version = 1,
    exportSchema = true,
)
abstract class ExpiryDatabase : RoomDatabase() {
    abstract fun itemDao(): ItemDao

    companion object {
        const val NAME = "expiry.db"
    }
}
