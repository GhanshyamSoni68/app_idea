package com.ghanshyam.expiry.ui.items

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.domain.model.Urgency
import com.ghanshyam.expiry.ui.components.EmptyState
import com.ghanshyam.expiry.ui.components.UrgencyChip
import com.ghanshyam.expiry.ui.components.icon
import com.ghanshyam.expiry.ui.components.label
import com.ghanshyam.expiry.ui.formatDate
import com.ghanshyam.expiry.ui.relativeExpiry

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ItemsScreen(
    onAddItem: () -> Unit,
    onOpenItem: (Long) -> Unit,
    onOpenSettings: () -> Unit,
    viewModel: ItemsViewModel = hiltViewModel(),
) {
    val state by viewModel.uiState.collectAsStateWithLifecycle()
    val listState = rememberLazyListState()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.title_items)) },
                actions = {
                    IconButton(onClick = onOpenSettings) {
                        Icon(Icons.Outlined.Settings, stringResource(R.string.cd_settings))
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = onAddItem) {
                Icon(Icons.Filled.Add, stringResource(R.string.cd_add_item))
            }
        },
    ) { padding ->
        Box(modifier = Modifier.padding(padding).fillMaxSize()) {
            when {
                state.isEmpty -> EmptyState(
                    title = stringResource(R.string.empty_title),
                    body = stringResource(R.string.empty_body),
                    actionLabel = stringResource(R.string.empty_action),
                    onAction = onAddItem,
                )

                else -> ItemList(
                    state = state,
                    listState = listState,
                    onQueryChange = viewModel::onQueryChange,
                    onCategoryFilterChange = viewModel::onCategoryFilterChange,
                    onOpenItem = onOpenItem,
                )
            }
        }
    }
}

@Composable
private fun ItemList(
    state: ItemsUiState,
    listState: androidx.compose.foundation.lazy.LazyListState,
    onQueryChange: (String) -> Unit,
    onCategoryFilterChange: (com.ghanshyam.expiry.domain.model.Category?) -> Unit,
    onOpenItem: (Long) -> Unit,
) {
    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize(),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = 96.dp),
    ) {
        item(key = "search") {
            OutlinedTextField(
                value = state.query,
                onValueChange = onQueryChange,
                singleLine = true,
                placeholder = { Text(stringResource(R.string.search_hint)) },
                leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                trailingIcon = {
                    if (state.query.isNotEmpty()) {
                        IconButton(onClick = { onQueryChange("") }) {
                            Icon(Icons.Filled.Close, stringResource(R.string.cd_clear_search))
                        }
                    }
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 8.dp),
            )
        }

        // A filter row with one category in it is just noise.
        if (state.availableCategories.size > 1) {
            item(key = "filters") {
                androidx.compose.foundation.lazy.LazyRow(
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 16.dp),
                    modifier = Modifier.padding(bottom = 8.dp),
                ) {
                    item {
                        FilterChip(
                            selected = state.categoryFilter == null,
                            onClick = { onCategoryFilterChange(null) },
                            label = { Text(stringResource(R.string.filter_all)) },
                        )
                    }
                    items(state.availableCategories, key = { it.id }) { category ->
                        FilterChip(
                            selected = state.categoryFilter == category,
                            onClick = {
                                onCategoryFilterChange(
                                    if (state.categoryFilter == category) null else category,
                                )
                            },
                            label = { Text(category.label()) },
                        )
                    }
                }
            }
        }

        if (state.hasNoMatches) {
            item(key = "no-matches") {
                Text(
                    text = stringResource(R.string.no_matches),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 32.dp),
                )
            }
        }

        for (section in state.sections) {
            item(key = "header-${section.urgency}") {
                SectionHeader(section.urgency)
            }
            items(section.rows, key = { it.item.id }) { row ->
                ItemCard(row = row, onClick = { onOpenItem(row.item.id) })
            }
        }
    }
}

@Composable
private fun SectionHeader(urgency: Urgency) {
    Text(
        text = stringResource(
            when (urgency) {
                Urgency.EXPIRED -> R.string.bucket_expired
                Urgency.THIS_WEEK -> R.string.bucket_this_week
                Urgency.THIS_MONTH -> R.string.bucket_this_month
                Urgency.LATER -> R.string.bucket_later
            },
        ),
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 4.dp),
    )
}

@Composable
private fun ItemCard(row: ItemRow, onClick: () -> Unit) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Icon(
            imageVector = row.item.category.icon(),
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(24.dp),
        )
        Column(
            modifier = Modifier
                .weight(1f)
                .padding(start = 16.dp),
        ) {
            Text(
                text = row.item.title,
                style = MaterialTheme.typography.titleMedium,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = formatDate(row.item.expiresOn),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        UrgencyChip(
            text = relativeExpiry(row.daysUntil),
            urgency = row.urgency,
        )
    }
}
