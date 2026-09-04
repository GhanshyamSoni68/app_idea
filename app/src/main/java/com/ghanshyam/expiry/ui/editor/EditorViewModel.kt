package com.ghanshyam.expiry.ui.editor

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.ghanshyam.expiry.core.time.AppClock
import com.ghanshyam.expiry.data.prefs.SettingsRepository
import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.ghanshyam.expiry.domain.repository.ItemRepository
import com.ghanshyam.expiry.ui.navigation.Destinations
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.LocalDate
import javax.inject.Inject

data class EditorUiState(
    val loading: Boolean = true,
    val isNewItem: Boolean = true,
    val title: String = "",
    val category: Category = Category.OTHER,
    val expiresOn: LocalDate? = null,
    val notes: String = "",
    val reminderOffsets: List<Int> = TrackedItem.DEFAULT_REMINDER_OFFSETS,
    val titleError: Boolean = false,
    val dateError: Boolean = false,
    val saved: Boolean = false,
    val deleted: Boolean = false,
)

@HiltViewModel
class EditorViewModel @Inject constructor(
    private val repository: ItemRepository,
    private val settings: SettingsRepository,
    private val clock: AppClock,
    private val savedStateHandle: SavedStateHandle,
) : ViewModel() {

    /** Declared as [androidx.navigation.NavType.LongType] in the graph. */
    private val itemId: Long =
        savedStateHandle.get<Long>(Destinations.ARG_ITEM_ID) ?: TrackedItem.NO_ID

    private val _uiState = MutableStateFlow(EditorUiState())
    val uiState: StateFlow<EditorUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch { load() }
        viewModelScope.launch { observeScanResult() }
    }

    private suspend fun load() {
        val existing = if (itemId == TrackedItem.NO_ID) null else repository.getById(itemId)
        _uiState.update {
            if (existing == null) {
                it.copy(
                    loading = false,
                    isNewItem = true,
                    reminderOffsets = settings.settings.first().defaultReminderOffsets,
                )
            } else {
                it.copy(
                    loading = false,
                    isNewItem = false,
                    title = existing.title,
                    category = existing.category,
                    expiresOn = existing.expiresOn,
                    notes = existing.notes,
                    reminderOffsets = existing.reminderOffsetsDays,
                )
            }
        }
    }

    /**
     * The scan screen hands its result back through this entry's saved state.
     * The key is cleared once consumed so that returning to the editor later,
     * or rotating the device, cannot re-apply a date the user has since edited.
     */
    private suspend fun observeScanResult() {
        savedStateHandle
            .getStateFlow(Destinations.RESULT_SCANNED_EPOCH_DAY, NO_SCAN_RESULT)
            .collect { epochDay ->
                if (epochDay == NO_SCAN_RESULT) return@collect
                savedStateHandle[Destinations.RESULT_SCANNED_EPOCH_DAY] = NO_SCAN_RESULT
                _uiState.update {
                    it.copy(expiresOn = LocalDate.ofEpochDay(epochDay), dateError = false)
                }
            }
    }

    fun onTitleChange(value: String) =
        _uiState.update { it.copy(title = value, titleError = false) }

    fun onCategoryChange(value: Category) = _uiState.update { it.copy(category = value) }

    fun onDateChange(value: LocalDate) =
        _uiState.update { it.copy(expiresOn = value, dateError = false) }

    fun onNotesChange(value: String) = _uiState.update { it.copy(notes = value) }

    fun onToggleOffset(offset: Int) = _uiState.update { state ->
        val next = if (offset in state.reminderOffsets) {
            state.reminderOffsets - offset
        } else {
            state.reminderOffsets + offset
        }
        state.copy(reminderOffsets = next.sortedDescending())
    }

    fun save() {
        val state = _uiState.value
        val titleBlank = state.title.isBlank()
        val dateMissing = state.expiresOn == null

        if (titleBlank || dateMissing) {
            _uiState.update { it.copy(titleError = titleBlank, dateError = dateMissing) }
            return
        }

        viewModelScope.launch {
            repository.save(
                TrackedItem(
                    id = itemId,
                    title = state.title,
                    category = state.category,
                    expiresOn = requireNotNull(state.expiresOn),
                    notes = state.notes,
                    reminderOffsetsDays = state.reminderOffsets,
                ),
            )
            _uiState.update { it.copy(saved = true) }
        }
    }

    fun delete() {
        if (itemId == TrackedItem.NO_ID) return
        viewModelScope.launch {
            repository.delete(itemId)
            _uiState.update { it.copy(deleted = true) }
        }
    }

    fun today(): LocalDate = clock.today()

    private companion object {
        const val NO_SCAN_RESULT = -1L
    }
}
