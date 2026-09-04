package com.ghanshyam.expiry.security

import android.content.Context
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.suspendCancellableCoroutine
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.coroutines.resume

/** Result of asking the user to prove they own the device. */
sealed interface UnlockResult {
    data object Success : UnlockResult
    /** The user dismissed the prompt, or ran out of attempts. Stay locked. */
    data object Cancelled : UnlockResult
    /** Nothing to authenticate against, so the lock cannot be enforced. */
    data object Unavailable : UnlockResult
    data class Error(val message: CharSequence) : UnlockResult
}

/**
 * Wraps [BiometricPrompt] so the rest of the app can ask "is this the owner?"
 * with a single suspending call.
 *
 * Accepts a device credential (PIN, pattern, password) as well as a biometric.
 * Requiring a fingerprint alone would lock out anyone whose sensor fails or
 * who has none enrolled, and the credential is the same secret that protects
 * the keystore anyway.
 */
@Singleton
class AppLock @Inject constructor(
    private val context: Context,
) {
    private val authenticators =
        BiometricManager.Authenticators.BIOMETRIC_WEAK or
            BiometricManager.Authenticators.DEVICE_CREDENTIAL

    /** False when the device has neither a biometric nor a screen lock set up. */
    fun isAvailable(): Boolean =
        BiometricManager.from(context).canAuthenticate(authenticators) ==
            BiometricManager.BIOMETRIC_SUCCESS

    suspend fun authenticate(
        activity: FragmentActivity,
        title: String,
        subtitle: String,
    ): UnlockResult {
        if (!isAvailable()) return UnlockResult.Unavailable

        return suspendCancellableCoroutine { continuation ->
            val prompt = BiometricPrompt(
                activity,
                androidx.core.content.ContextCompat.getMainExecutor(context),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: AuthenticationResult) {
                        if (continuation.isActive) continuation.resume(UnlockResult.Success)
                    }

                    override fun onAuthenticationError(code: Int, message: CharSequence) {
                        if (!continuation.isActive) return
                        val outcome = when (code) {
                            BiometricPrompt.ERROR_USER_CANCELED,
                            BiometricPrompt.ERROR_NEGATIVE_BUTTON,
                            BiometricPrompt.ERROR_CANCELED,
                            -> UnlockResult.Cancelled

                            else -> UnlockResult.Error(message)
                        }
                        continuation.resume(outcome)
                    }

                    // Deliberately not resumed: a single bad fingerprint is not a
                    // final answer, and the prompt lets the user try again.
                    override fun onAuthenticationFailed() = Unit
                },
            )

            val info = BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setSubtitle(subtitle)
                .setAllowedAuthenticators(authenticators)
                .build()

            continuation.invokeOnCancellation { prompt.cancelAuthentication() }
            prompt.authenticate(info)
        }
    }
}
