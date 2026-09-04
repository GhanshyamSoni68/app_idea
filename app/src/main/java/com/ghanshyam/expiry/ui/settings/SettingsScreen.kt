package com.ghanshyam.expiry.ui.settings

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.ghanshyam.expiry.BuildConfig
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.domain.model.TrackedItem
import java.time.LocalTime

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun SettingsScreen(
    onBack: () -> Unit,
    viewModel: SettingsViewModel = hiltViewModel(),
) {
    val settings by viewModel.settings.collectAsStateWithLifecycle()
    val message by viewModel.message.collectAsStateWithLifecycle()
    val pendingExport by viewModel.pendingExport.collectAsStateWithLifecycle()

    val snackbarHostState = remember { SnackbarHostState() }
    var showTimePicker by remember { mutableStateOf(false) }
    var passphrasePrompt by remember { mutableStateOf<PassphrasePrompt?>(null) }

    val lockAvailable = remember { viewModel.appLockAvailable() }

    val createFileLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.CreateDocument("application/octet-stream"),
    ) { uri ->
        if (uri == null) viewModel.cancelExport() else viewModel.completeExport(uri)
    }

    val openFileLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.OpenDocument(),
    ) { uri ->
        if (uri != null) passphrasePrompt = PassphrasePrompt.Restore(uri)
    }

    // Encryption finished, so hand the user a file picker for the destination.
    LaunchedEffect(pendingExport) {
        if (pendingExport != null) createFileLauncher.launch(DEFAULT_BACKUP_NAME)
    }

    val messageText = message?.let { resolveMessage(it) }
    LaunchedEffect(messageText) {
        if (messageText != null) {
            snackbarHostState.showSnackbar(messageText)
            viewModel.consumeMessage()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.title_settings)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowBack,
                            stringResource(R.string.cd_back),
                        )
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { padding ->
        Column(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
                .verticalScroll(rememberScrollState()),
        ) {
            SectionHeader(stringResource(R.string.settings_security))
            SwitchRow(
                title = stringResource(R.string.settings_app_lock),
                summary = if (lockAvailable) {
                    stringResource(R.string.settings_app_lock_summary)
                } else {
                    stringResource(R.string.settings_app_lock_unavailable)
                },
                checked = settings.appLockEnabled && lockAvailable,
                enabled = lockAvailable,
                onCheckedChange = viewModel::setAppLockEnabled,
            )

            HorizontalDivider()
            SectionHeader(stringResource(R.string.settings_reminders))
            ClickableRow(
                title = stringResource(R.string.settings_reminder_time),
                summary = "%02d:%02d".format(settings.reminderTime.hour, settings.reminderTime.minute),
                onClick = { showTimePicker = true },
            )
            Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) {
                Text(
                    text = stringResource(R.string.settings_default_offsets),
                    style = MaterialTheme.typography.bodyLarge,
                )
                Text(
                    text = stringResource(R.string.settings_default_offsets_summary),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(bottom = 8.dp),
                )
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    for (offset in TrackedItem.SELECTABLE_REMINDER_OFFSETS) {
                        FilterChip(
                            selected = offset in settings.defaultReminderOffsets,
                            onClick = { viewModel.toggleDefaultOffset(offset) },
                            label = {
                                Text(
                                    if (offset == 0) {
                                        stringResource(R.string.offset_on_the_day)
                                    } else {
                                        pluralStringResource(
                                            R.plurals.offset_days_before,
                                            offset,
                                            offset,
                                        )
                                    },
                                )
                            },
                        )
                    }
                }
            }

            HorizontalDivider()
            SectionHeader(stringResource(R.string.settings_data))
            ClickableRow(
                title = stringResource(R.string.settings_export),
                summary = null,
                onClick = { passphrasePrompt = PassphrasePrompt.Export },
            )
            ClickableRow(
                title = stringResource(R.string.settings_import),
                summary = null,
                onClick = { openFileLauncher.launch(arrayOf("*/*")) },
            )

            HorizontalDivider()
            SectionHeader(stringResource(R.string.settings_about))
            Text(
                text = stringResource(R.string.settings_privacy_summary),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 16.dp),
            )
            Text(
                text = stringResource(R.string.settings_version, BuildConfig.VERSION_NAME),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(16.dp),
            )
        }
    }

    if (showTimePicker) {
        ReminderTimePicker(
            initial = settings.reminderTime,
            onDismiss = { showTimePicker = false },
            onConfirm = { time ->
                viewModel.setReminderTime(time)
                showTimePicker = false
            },
        )
    }

    passphrasePrompt?.let { prompt ->
        PassphraseDialog(
            prompt = prompt,
            onDismiss = { passphrasePrompt = null },
            onConfirm = { passphrase ->
                when (prompt) {
                    PassphrasePrompt.Export -> viewModel.prepareExport(passphrase)
                    is PassphrasePrompt.Restore -> viewModel.restore(prompt.uri, passphrase)
                }
                passphrasePrompt = null
            },
        )
    }
}

@Composable
private fun resolveMessage(message: SettingsMessage): String = when (message) {
    is SettingsMessage.Exported ->
        pluralStringResource(R.plurals.backup_export_success, message.count, message.count)

    is SettingsMessage.Restored ->
        pluralStringResource(R.plurals.backup_import_success, message.count, message.count)

    SettingsMessage.WrongPassphrase -> stringResource(R.string.backup_error_wrong_passphrase)
    SettingsMessage.CorruptFile -> stringResource(R.string.backup_error_corrupt)
    SettingsMessage.FileError -> stringResource(R.string.backup_error_file)
}

@Composable
private fun SectionHeader(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 20.dp, bottom = 8.dp),
    )
}

@Composable
private fun SwitchRow(
    title: String,
    summary: String,
    checked: Boolean,
    enabled: Boolean,
    onCheckedChange: (Boolean) -> Unit,
) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge)
            Text(
                text = summary,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Switch(checked = checked, onCheckedChange = onCheckedChange, enabled = enabled)
    }
}

@Composable
private fun ClickableRow(title: String, summary: String?, onClick: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 14.dp),
    ) {
        Text(title, style = MaterialTheme.typography.bodyLarge)
        if (summary != null) {
            Text(
                text = summary,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ReminderTimePicker(
    initial: LocalTime,
    onDismiss: () -> Unit,
    onConfirm: (LocalTime) -> Unit,
) {
    val state = rememberTimePickerState(
        initialHour = initial.hour,
        initialMinute = initial.minute,
        is24Hour = false,
    )
    AlertDialog(
        onDismissRequest = onDismiss,
        confirmButton = {
            TextButton(onClick = { onConfirm(LocalTime.of(state.hour, state.minute)) }) {
                Text(stringResource(R.string.action_save))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) }
        },
        text = { TimePicker(state = state) },
    )
}

private const val DEFAULT_BACKUP_NAME = "expiry-backup.bin"
