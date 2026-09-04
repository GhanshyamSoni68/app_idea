package com.ghanshyam.expiry.data.backup

import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.google.common.truth.Truth.assertThat
import org.junit.Test
import java.time.Instant
import java.time.LocalDate

class BackupCodecTest {

    private val codec = BackupCodec()
    private val passphrase = "correct horse battery".toCharArray()

    private val items = listOf(
        TrackedItem(
            id = 1L,
            title = "Passport",
            category = Category.IDENTITY,
            expiresOn = LocalDate.of(2031, 5, 12),
            notes = "Renew six months early for visa-free travel",
            reminderOffsetsDays = listOf(180, 30, 0),
            notifiedOffsetsDays = setOf(180),
            createdAt = Instant.ofEpochMilli(1_700_000_000_000L),
        ),
        TrackedItem(
            id = 2L,
            title = "Car insurance",
            category = Category.INSURANCE,
            expiresOn = LocalDate.of(2027, 1, 31),
        ),
    )

    @Test
    fun `a backup round-trips every field that matters`() {
        val bytes = codec.backup(items, passphrase.copyOf())
        val result = codec.restore(bytes, passphrase.copyOf())

        assertThat(result).isInstanceOf(RestoreResult.Success::class.java)
        val restored = (result as RestoreResult.Success).items

        assertThat(restored).hasSize(2)
        assertThat(restored[0].title).isEqualTo("Passport")
        assertThat(restored[0].category).isEqualTo(Category.IDENTITY)
        assertThat(restored[0].expiresOn).isEqualTo(LocalDate.of(2031, 5, 12))
        assertThat(restored[0].notes).isEqualTo("Renew six months early for visa-free travel")
        assertThat(restored[0].reminderOffsetsDays).containsExactly(180, 30, 0).inOrder()
        assertThat(restored[0].notifiedOffsetsDays).containsExactly(180)
        assertThat(restored[0].createdAt).isEqualTo(Instant.ofEpochMilli(1_700_000_000_000L))
    }

    @Test
    fun `the wrong passphrase is reported as such rather than as corruption`() {
        val bytes = codec.backup(items, passphrase.copyOf())

        assertThat(codec.restore(bytes, "not the passphrase".toCharArray()))
            .isEqualTo(RestoreResult.WrongPassphrase)
    }

    @Test
    fun `a tampered file is rejected`() {
        val bytes = codec.backup(items, passphrase.copyOf())
        // Flip a bit in the ciphertext; GCM's tag must catch it.
        bytes[bytes.size - 1] = (bytes[bytes.size - 1].toInt() xor 0x01).toByte()

        assertThat(codec.restore(bytes, passphrase.copyOf()))
            .isEqualTo(RestoreResult.WrongPassphrase)
    }

    @Test
    fun `a file that is not a backup at all is rejected`() {
        val notABackup = "just some text pretending to be a backup".toByteArray()

        assertThat(codec.restore(notABackup, passphrase.copyOf()))
            .isEqualTo(RestoreResult.Corrupt)
    }

    @Test
    fun `a truncated file is rejected`() {
        val bytes = codec.backup(items, passphrase.copyOf())

        assertThat(codec.restore(bytes.copyOfRange(0, 20), passphrase.copyOf()))
            .isEqualTo(RestoreResult.Corrupt)
    }

    @Test
    fun `a future format version is refused rather than misread`() {
        val bytes = codec.backup(items, passphrase.copyOf())
        bytes["EXPIRY".length] = 99

        assertThat(codec.restore(bytes, passphrase.copyOf()))
            .isEqualTo(RestoreResult.UnsupportedVersion)
    }

    @Test
    fun `every backup uses a fresh salt and nonce`() {
        val first = codec.backup(items, passphrase.copyOf())
        val second = codec.backup(items, passphrase.copyOf())

        // Identical input under the same passphrase must not produce identical
        // bytes, or the file would leak that nothing had changed.
        assertThat(first).isNotEqualTo(second)
    }

    @Test
    fun `an empty vault round-trips`() {
        val bytes = codec.backup(emptyList(), passphrase.copyOf())
        val result = codec.restore(bytes, passphrase.copyOf())

        assertThat((result as RestoreResult.Success).items).isEmpty()
    }
}
