package com.ghanshyam.expiry.di

import android.content.Context
import androidx.room.Room
import com.ghanshyam.expiry.data.local.ExpiryDatabase
import com.ghanshyam.expiry.data.local.ItemDao
import com.ghanshyam.expiry.security.DatabaseKeyProvider
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import net.zetetic.database.sqlcipher.SupportOpenHelperFactory
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {

    @Provides
    @Singleton
    fun provideDatabase(
        @ApplicationContext context: Context,
        keyProvider: DatabaseKeyProvider,
    ): ExpiryDatabase {
        System.loadLibrary("sqlcipher")

        // SQLCipher zeroes this array once the database is opened, which is why
        // a fresh copy is requested here rather than a cached one being reused.
        val passphrase = keyProvider.passphrase()

        return Room.databaseBuilder(context, ExpiryDatabase::class.java, ExpiryDatabase.NAME)
            .openHelperFactory(SupportOpenHelperFactory(passphrase))
            // No fallbackToDestructiveMigration: silently deleting someone's
            // documents on a schema change would be the worst possible failure
            // mode for this app. Migrations are written by hand from v2 on.
            .build()
    }

    @Provides
    @Singleton
    fun provideItemDao(database: ExpiryDatabase): ItemDao = database.itemDao()
}
