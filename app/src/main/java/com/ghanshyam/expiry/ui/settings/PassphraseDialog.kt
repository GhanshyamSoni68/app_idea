package com.ghanshyam.expiry.ui.settings

import android.net.Uri
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.ghanshyam.expiry.R

/** Which operation the passphrase is being collected for. */
sealed interface PassphrasePrompt {
    /** Creating a backup: the passphrase is entered twice. */
    data object Export : PassphrasePrompt

    /** Reading a backup at [uri]: entered once. */
    data class Restore(val uri: Uri) : PassphrasePrompt
}

@Composable
fun PassphraseDialog(
    prompt: PassphrasePrompt,
    onDismiss: () -> Unit,
    onConfirm: (CharArray) -> Unit,
) {
    val isExport = prompt is PassphrasePrompt.Export
    var passphrase by remember { mutableStateOf("") }
    var confirmation by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<Int?>(null) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.backup_passphrase_title)) },
        text = {
            Column {
                Text(
                    stringResource(
                        if (isExport) {
                            R.string.backup_passphrase_body
                        } else {
                            R.string.backup_restore_passphrase_body
                        },
                    ),
                )
                OutlinedTextField(
                    value = passphrase,
                    onValueChange = {
                        passphrase = it
                        error = null
                    },
                    label = { Text(stringResource(R.string.backup_passphrase_hint)) },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(top = 16.dp),
                )
                if (isExport) {
                    OutlinedTextField(
                        value = confirmation,
                        onValueChange = {
                            confirmation = it
                            error = null
                        },
                        label = { Text(stringResource(R.string.backup_passphrase_confirm_hint)) },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(top = 8.dp),
                    )
                }
                error?.let { messageRes ->
                    Text(
                        text = stringResource(messageRes),
                        color = androidx.compose.material3.MaterialTheme.colorScheme.error,
                        style = androidx.compose.material3.MaterialTheme.typography.bodySmall,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    when {
                        passphrase.length < MIN_PASSPHRASE_LENGTH ->
                            error = R.string.backup_error_too_short

                        isExport && passphrase != confirmation ->
                            error = R.string.backup_error_mismatch

                        else -> onConfirm(passphrase.toCharArray())
                    }
                },
            ) { Text(stringResource(R.string.action_save)) }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) }
        },
    )
}

private const val MIN_PASSPHRASE_LENGTH = 8
