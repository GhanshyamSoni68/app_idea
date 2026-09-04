package com.ghanshyam.expiry.ui.lock

import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.ghanshyam.expiry.data.prefs.SettingsRepository
import com.ghanshyam.expiry.security.AppLock
import com.ghanshyam.expiry.security.UnlockResult
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject

data class LockUiState(
    /** Null until the stored preference has been read; nothing is shown until then. */
    val required: Boolean? = null,
    val unlocked: Boolean = false,
    val prompting: Boolean = false,
    val failureMessage: CharSequence? = null,
)

@HiltViewModel
class LockViewModel @Inject constructor(
    private val appLock: AppLock,
    private val settings: SettingsRepository,
) : ViewModel() {

    private val _uiState = MutableStateFlow(LockUiState())
    val uiState: StateFlow<LockUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch {
            // Read once rather than observing: turning the lock on from
            // Settings should apply next launch, not lock the user out of the
            // screen they are standing on.
            val enabled = settings.settings.first().appLockEnabled && appLock.isAvailable()
            _uiState.update { it.copy(required = enabled, unlocked = !enabled) }
        }
    }

    fun authenticate(activity: FragmentActivity, title: String, subtitle: String) {
        if (_uiState.value.prompting || _uiState.value.unlocked) return
        _uiState.update { it.copy(prompting = true, failureMessage = null) }

        viewModelScope.launch {
            when (val result = appLock.authenticate(activity, title, subtitle)) {
                UnlockResult.Success ->
                    _uiState.update { it.copy(unlocked = true, prompting = false) }

                UnlockResult.Cancelled ->
                    _uiState.update { it.copy(prompting = false) }

                // The screen lock was removed after the setting was turned on.
                // Refusing to open would strand the user with no way back in.
                UnlockResult.Unavailable ->
                    _uiState.update { it.copy(unlocked = true, prompting = false) }

                is UnlockResult.Error ->
                    _uiState.update { it.copy(prompting = false, failureMessage = result.message) }
            }
        }
    }
}
