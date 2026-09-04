package com.ghanshyam.expiry.security

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.security.SecureRandom
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Supplies the SQLCipher passphrase for the item database.
 *
 * The passphrase is 32 random bytes generated once on first launch and held in
 * [EncryptedSharedPreferences], which is itself sealed by a key in the
 * Android Keystore. That keystore key never leaves the secure hardware and
 * cannot be exported, so the database file is unreadable if it is lifted off
 * the device — which is also why cloud backup of the database is disabled in
 * the manifest: the ciphertext would restore onto a device that has no way to
 * derive the key.
 */
@Singleton
class DatabaseKeyProvider @Inject constructor(
    private val context: Context,
) {
    private val prefs: SharedPreferences by lazy {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            PREFS_FILE,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    /**
     * Returns the passphrase as bytes, minting one on first call.
     *
     * SQLCipher zeroes the array it is handed, so callers must not retain or
     * reuse the return value — ask again instead.
     */
    @Synchronized
    fun passphrase(): ByteArray {
        val existing = prefs.getString(KEY_PASSPHRASE, null)
        if (existing != null) return existing.hexToBytes()

        val fresh = ByteArray(KEY_LENGTH_BYTES).also(SecureRandom()::nextBytes)
        prefs.edit().putString(KEY_PASSPHRASE, fresh.toHex()).apply()
        return fresh
    }

    private fun ByteArray.toHex(): String =
        joinToString(separator = "") { byte -> "%02x".format(byte) }

    private fun String.hexToBytes(): ByteArray =
        ByteArray(length / 2) { index ->
            substring(index * 2, index * 2 + 2).toInt(radix = 16).toByte()
        }

    private companion object {
        const val PREFS_FILE = "expiry_db_key"
        const val KEY_PASSPHRASE = "passphrase"
        const val KEY_LENGTH_BYTES = 32
    }
}
