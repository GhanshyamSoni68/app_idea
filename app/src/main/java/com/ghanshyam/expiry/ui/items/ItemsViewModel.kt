package com.ghanshyam.expiry.ui.items

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.ghanshyam.expiry.core.time.AppClock
import com.ghanshyam.expiry.domain.model.Category
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.ghanshyam.expiry.domain.model.Urgency
import com.ghanshyam.expiry.domain.repository.ItemRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.util.Locale
import javax.inject.Inject

data class ItemRow(
    val item: TrackedItem,
    val daysUntil: Long,
    val urgency: Urgency,
)

data class ItemSection(
    val urgency: Urgency,
    val rows: List<ItemRow>,
)

data class ItemsUiState(
    val loading: Boolean = true,
    val sections: List<ItemSection> = emptyList(),
    val query: String = "",
    val categoryFilter: Category? = null,
    /** Only the categories actually in use, so the filter row stays short. */
    val availableCategories: List<Category> = emptyList(),
    val totalItemCount: Int = 0,
) {
    /** Nothing tracked at all — show the onboarding empty state. */
    val isEmpty: Boolean get() = !loading && totalItemCount == 0

    /** Items exist but the current search or filter hides them all. */
    val hasNoMatches: Boolean get() = !loading && totalItemCount > 0 && sections.isEmpty()
}

@HiltViewModel
class ItemsViewModel @Inject constructor(
    private val repository: ItemRepository,
    private val clock: AppClock,
) : ViewModel() {

    private val query = MutableStateFlow("")
    private val categoryFilter = MutableStateFlow<Category?>(null)

    val uiState: StateFlow<ItemsUiState> =
        combine(repository.observeAll(), query, categoryFilter) { items, search, filter ->
            buildState(items, search, filter, clock.today())
        }.stateIn(
            scope = viewModelScope,
            // Keep the flow warm briefly across configuration changes so a
            // rotation does not re-query and flash the empty state.
            started = SharingStarted.WhileSubscribed(5_000),
            initialValue = ItemsUiState(),
        )

    fun onQueryChange(value: String) {
        query.value = value
    }

    fun onCategoryFilterChange(category: Category?) {
        categoryFilter.value = category
    }

    fun delete(id: Long) {
        viewModelScope.launch { repository.delete(id) }
    }

    private fun buildState(
        items: List<TrackedItem>,
        search: String,
        filter: Category?,
        today: LocalDate,
    ): ItemsUiState {
        val normalisedQuery = search.trim().lowercase(Locale.getDefault())

        val matching = items.filter { item ->
            (filter == null || item.category == filter) &&
                (
                    normalisedQuery.isEmpty() ||
                        item.title.lowercase(Locale.getDefault()).contains(normalisedQuery) ||
                        item.notes.lowercase(Locale.getDefault()).contains(normalisedQuery)
                    )
        }

        val sections = matching
            .map { item ->
                val days = item.daysUntil(today)
                ItemRow(item = item, daysUntil = days, urgency = Urgency.of(days))
            }
            .groupBy(ItemRow::urgency)
            // Iterating the enum rather than the map keeps the sections in
            // urgency order regardless of what the query happened to return.
            .let { grouped ->
                Urgency.entries.mapNotNull { urgency ->
                    grouped[urgency]
                        ?.sortedBy { it.item.expiresOn }
                        ?.takeIf(List<ItemRow>::isNotEmpty)
                        ?.let { ItemSection(urgency, it) }
                }
            }

        return ItemsUiState(
            loading = false,
            sections = sections,
            query = search,
            categoryFilter = filter,
            availableCategories = items.map(TrackedItem::category).distinct()
                .sortedBy(Category::ordinal),
            totalItemCount = items.size,
        )
    }
}
