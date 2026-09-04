package com.ghanshyam.expiry.ui.scan

import androidx.camera.core.ImageProxy
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.ghanshyam.expiry.core.time.AppClock
import com.ghanshyam.expiry.ocr.DateCandidate
import com.ghanshyam.expiry.ocr.DateExtractor
import com.ghanshyam.expiry.ocr.TextScanner
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.util.Locale
import java.util.concurrent.atomic.AtomicBoolean
import javax.inject.Inject

data class ScanUiState(
    val candidates: List<DateCandidate> = emptyList(),
    val framesAnalysed: Int = 0,
) {
    /**
     * Only admit that nothing was found after a fair number of frames.
     * Saying so on frame one would be wrong the moment the user steadies
     * their hand.
     */
    val showNoResults: Boolean
        get() = candidates.isEmpty() && framesAnalysed >= FRAMES_BEFORE_GIVING_UP

    private companion object {
        const val FRAMES_BEFORE_GIVING_UP = 20
    }
}

@HiltViewModel
class ScanViewModel @Inject constructor(
    private val textScanner: TextScanner,
    private val dateExtractor: DateExtractor,
    private val clock: AppClock,
) : ViewModel() {

    private val _uiState = MutableStateFlow(ScanUiState())
    val uiState: StateFlow<ScanUiState> = _uiState.asStateFlow()

    /**
     * CameraX delivers frames faster than OCR can consume them. Rather than
     * queueing (which would show results for a view the camera has long since
     * left), frames arriving while a recognition is in flight are dropped.
     */
    private val busy = AtomicBoolean(false)

    fun onFrame(imageProxy: ImageProxy, locale: Locale) {
        if (!busy.compareAndSet(false, true)) {
            imageProxy.close()
            return
        }

        viewModelScope.launch {
            try {
                // TextScanner closes the proxy on every path.
                val text = textScanner.recognise(imageProxy)
                val found = dateExtractor.extract(text, clock.today(), locale)
                _uiState.update { state ->
                    state.copy(
                        candidates = merge(state.candidates, found),
                        framesAnalysed = state.framesAnalysed + 1,
                    )
                }
            } catch (error: Exception) {
                // A frame that fails to decode is not worth surfacing; the next
                // one is milliseconds away.
                _uiState.update { it.copy(framesAnalysed = it.framesAnalysed + 1) }
            } finally {
                busy.set(false)
            }
        }
    }

    /**
     * Accumulates across frames instead of replacing, because a date can be
     * legible in one frame and blurred in the next. Each date is kept at the
     * highest confidence it has ever been seen with.
     */
    private fun merge(
        existing: List<DateCandidate>,
        found: List<DateCandidate>,
    ): List<DateCandidate> =
        (existing + found)
            .groupBy(DateCandidate::date)
            .map { (_, sightings) -> sightings.maxBy(DateCandidate::confidence) }
            .sortedWith(compareByDescending<DateCandidate> { it.confidence }.thenBy { it.date })
            .take(MAX_CANDIDATES)

    fun today(): LocalDate = clock.today()

    override fun onCleared() {
        super.onCleared()
        textScanner.close()
    }

    private companion object {
        const val MAX_CANDIDATES = 6
    }
}
