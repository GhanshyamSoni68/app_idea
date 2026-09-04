package com.ghanshyam.expiry.data.backup

import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.security.SecureRandom
import java.time.Instant
import java.time.LocalDate
import javax.crypto.AEADBadTagException
import javax.crypto.Cipher
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.PBEKeySpec
import javax.crypto.spec.SecretKeySpec
import javax.inject.Inject
import javax.inject.Singleton

sealed interface RestoreResult {
    data class Success(val items: List<TrackedItem>) : RestoreResult
    data object WrongPassphrase : RestoreResult
    data object Corrupt : RestoreResult
    data object UnsupportedVersion : RestoreResult
}

/**
 * Reads and writes the encrypted backup file.
 *
 * The database key is bound to the device's keystore and cannot leave it, so a
 * copy of the database file is worthless on a new phone. A backup therefore
 * has to be re-encrypted under something the user can carry with them: a
 * passphrase they choose.
 *
 * File layout, all binary, no framing beyond fixed widths:
 *
 * ```
 * "EXPIRY" | version (1 byte) | salt (16) | iv (12) | AES-GCM ciphertext
 * ```
 *
 * The key is derived with PBKDF2-HMAC-SHA256. GCM authenticates the ciphertext,
 * so a wrong passphrase surfaces as a tag failure rather than as plausible-
 * looking garbage — which is what lets [restore] tell "wrong passphrase" apart
 * from "damaged file".
 */
@Singleton
class BackupCodec @Inject constructor() {

    fun backup(items: List<TrackedItem>, passphrase: CharArray): ByteArray {
        val salt = ByteArray(SALT_BYTES).also(SecureRandom()::nextBytes)
        val iv = ByteArray(IV_BYTES).also(SecureRandom()::nextBytes)
        val key = deriveKey(passphrase, salt)

        val cipher = Cipher.getInstance(TRANSFORMATION).apply {
            init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(TAG_BITS, iv))
        }
        val ciphertext = cipher.doFinal(encodeJson(items).toByteArray(Charsets.UTF_8))

        return MAGIC + byteArrayOf(VERSION) + salt + iv + ciphertext
    }

    fun restore(bytes: ByteArray, passphrase: CharArray): RestoreResult {
        val headerSize = MAGIC.size + 1 + SALT_BYTES + IV_BYTES
        if (bytes.size <= headerSize) return RestoreResult.Corrupt
        if (!bytes.copyOfRange(0, MAGIC.size).contentEquals(MAGIC)) return RestoreResult.Corrupt
        if (bytes[MAGIC.size] != VERSION) return RestoreResult.UnsupportedVersion

        var offset = MAGIC.size + 1
        val salt = bytes.copyOfRange(offset, offset + SALT_BYTES).also { offset += SALT_BYTES }
        val iv = bytes.copyOfRange(offset, offset + IV_BYTES).also { offset += IV_BYTES }
        val ciphertext = bytes.copyOfRange(offset, bytes.size)

        val plaintext = try {
            Cipher.getInstance(TRANSFORMATION).run {
                init(Cipher.DECRYPT_MODE, deriveKey(passphrase, salt), GCMParameterSpec(TAG_BITS, iv))
                doFinal(ciphertext)
            }
        } catch (badTag: AEADBadTagException) {
            // GCM rejected the tag: either the passphrase is wrong or the bytes
            // were altered. From here the two are indistinguishable, and "wrong
            // passphrase" is the overwhelmingly more likely of the two.
            return RestoreResult.WrongPassphrase
        } catch (failure: java.security.GeneralSecurityException) {
            return RestoreResult.Corrupt
        }

        return try {
            RestoreResult.Success(decodeJson(String(plaintext, Charsets.UTF_8)))
        } catch (malformed: JSONException) {
            RestoreResult.Corrupt
        }
    }

    private fun deriveKey(passphrase: CharArray, salt: ByteArray): SecretKeySpec {
        val spec = PBEKeySpec(passphrase, salt, PBKDF2_ITERATIONS, KEY_BITS)
        return try {
            SecretKeySpec(
                SecretKeyFactory.getInstance(KDF_ALGORITHM).generateSecret(spec).encoded,
                "AES",
            )
        } finally {
            spec.clearPassword()
        }
    }

    private fun encodeJson(items: List<TrackedItem>): String {
        val array = JSONArray()
        for (item in items) {
            array.put(
                JSONObject().apply {
                    put("title", item.title)
                    put("category", item.category.id)
                    put("expiresOn", item.expiresOn.toString())
                    put("notes", item.notes)
                    put("reminderOffsets", JSONArray(item.reminderOffsetsDays))
                    put("notifiedOffsets", JSONArray(item.notifiedOffsetsDays.toList()))
                    put("createdAt", item.createdAt.toEpochMilli())
                },
            )
        }
        return JSONObject().apply {
            put("version", VERSION.toInt())
            put("exportedAt", Instant.now().toEpochMilli())
            put("items", array)
        }.toString()
    }

    private fun decodeJson(json: String): List<TrackedItem> {
        val root = JSONObject(json)
        val array = root.getJSONArray("items")
        return buildList {
            for (index in 0 until array.length()) {
                val obj = array.getJSONObject(index)
                add(
                    TrackedItem(
                        title = obj.getString("title"),
                        category = Category.fromId(obj.optString("category", Category.OTHER.id)),
                        expiresOn = LocalDate.parse(obj.getString("expiresOn")),
                        notes = obj.optString("notes", ""),
                        reminderOffsetsDays = obj.optJSONArray("reminderOffsets").toIntList()
                            .ifEmpty { TrackedItem.DEFAULT_REMINDER_OFFSETS },
                        notifiedOffsetsDays = obj.optJSONArray("notifiedOffsets").toIntList().toSet(),
                        createdAt = Instant.ofEpochMilli(obj.optLong("createdAt", 0L)),
                    ),
                )
            }
        }
    }

    private fun JSONArray?.toIntList(): List<Int> {
        if (this == null) return emptyList()
        return (0 until length()).map { getInt(it) }.filter { it >= 0 }
    }

    private companion object {
        val MAGIC = "EXPIRY".toByteArray(Charsets.US_ASCII)
        const val VERSION: Byte = 1
        const val SALT_BYTES = 16
        const val IV_BYTES = 12
        const val TAG_BITS = 128
        const val KEY_BITS = 256

        /**
         * High enough to make a guessing attack on a short passphrase costly,
         * low enough that deriving the key on a mid-range phone stays under
         * about a second.
         */
        const val PBKDF2_ITERATIONS = 210_000
        const val KDF_ALGORITHM = "PBKDF2WithHmacSHA256"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
    }
}
