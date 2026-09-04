package com.ghanshyam.expiry.ocr

import android.annotation.SuppressLint
import androidx.camera.core.ImageProxy
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import kotlinx.coroutines.suspendCancellableCoroutine
import javax.inject.Inject
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/**
 * On-device text recognition.
 *
 * The Latin model is bundled into the APK (see the ML Kit metadata in the
 * manifest), so scanning works on a plane, in a basement, or on a phone with
 * no SIM — and no photograph of anybody's passport is ever uploaded anywhere.
 *
 * Deliberately not a singleton: [close] releases the native recognizer, and
 * the scan screen calls it on teardown, so a shared instance would leave the
 * next scan holding a closed client.
 */
class TextScanner @Inject constructor() {

    private val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)

    /**
     * Recognises text in a camera frame.
     *
     * Closes [imageProxy] before returning; CameraX stalls the analysis
     * pipeline until each frame is released, so this must happen on every path
     * including failure.
     */
    @SuppressLint("UnsafeOptInUsageError")
    suspend fun recognise(imageProxy: ImageProxy): String {
        val mediaImage = imageProxy.image
        if (mediaImage == null) {
            imageProxy.close()
            return ""
        }

        return try {
            val input = InputImage.fromMediaImage(
                mediaImage,
                imageProxy.imageInfo.rotationDegrees,
            )
            suspendCancellableCoroutine { continuation ->
                recognizer.process(input)
                    .addOnSuccessListener { result ->
                        if (continuation.isActive) continuation.resume(result.text)
                    }
                    .addOnFailureListener { error ->
                        if (continuation.isActive) continuation.resumeWithException(error)
                    }
                    .addOnCanceledListener {
                        if (continuation.isActive) continuation.resume("")
                    }
            }
        } finally {
            imageProxy.close()
        }
    }

    fun close() = recognizer.close()
}
