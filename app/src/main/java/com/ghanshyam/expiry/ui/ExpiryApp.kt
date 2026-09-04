package com.ghanshyam.expiry.ui

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.res.stringResource
import androidx.fragment.app.FragmentActivity
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.domain.model.TrackedItem
import com.ghanshyam.expiry.ui.editor.EditorScreen
import com.ghanshyam.expiry.ui.items.ItemsScreen
import com.ghanshyam.expiry.ui.lock.LockScreen
import com.ghanshyam.expiry.ui.lock.LockViewModel
import com.ghanshyam.expiry.ui.navigation.Destinations
import com.ghanshyam.expiry.ui.scan.ScanScreen
import com.ghanshyam.expiry.ui.settings.SettingsScreen
import com.ghanshyam.expiry.ui.theme.ExpiryTheme

@Composable
fun ExpiryApp(
    activity: FragmentActivity,
    initialItemId: Long?,
    lockViewModel: LockViewModel = hiltViewModel(),
) {
    ExpiryTheme {
        val lockState by lockViewModel.uiState.collectAsStateWithLifecycle()
        val lockTitle = stringResource(R.string.lock_title)
        val lockSubtitle = stringResource(R.string.lock_subtitle)

        LaunchedEffect(lockState.required) {
            if (lockState.required == true && !lockState.unlocked) {
                lockViewModel.authenticate(activity, lockTitle, lockSubtitle)
            }
        }

        when {
            // Preference not read yet. Rendering the list here and replacing it
            // a frame later would flash the user's items past the lock.
            lockState.required == null -> Unit

            !lockState.unlocked -> LockScreen(
                failureMessage = lockState.failureMessage,
                onUnlockClick = {
                    lockViewModel.authenticate(activity, lockTitle, lockSubtitle)
                },
            )

            else -> ExpiryNavHost(initialItemId = initialItemId)
        }
    }
}

@Composable
private fun ExpiryNavHost(initialItemId: Long?) {
    val navController = rememberNavController()

    RequestNotificationPermission()

    // Opened from a reminder notification: land on the item it was about.
    LaunchedEffect(initialItemId) {
        if (initialItemId != null) {
            navController.navigate(Destinations.editor(initialItemId))
        }
    }

    NavHost(navController = navController, startDestination = Destinations.ITEMS) {
        composable(Destinations.ITEMS) {
            ItemsScreen(
                onAddItem = { navController.navigate(Destinations.editor()) },
                onOpenItem = { id -> navController.navigate(Destinations.editor(id)) },
                onOpenSettings = { navController.navigate(Destinations.SETTINGS) },
            )
        }

        composable(
            route = Destinations.EDITOR,
            arguments = listOf(
                navArgument(Destinations.ARG_ITEM_ID) {
                    type = NavType.LongType
                    defaultValue = TrackedItem.NO_ID
                },
            ),
        ) {
            EditorScreen(
                onDone = { navController.popBackStack() },
                onScan = { navController.navigate(Destinations.SCAN) },
            )
        }

        composable(Destinations.SCAN) {
            ScanScreen(
                onDateChosen = { date ->
                    // Hand the result to the editor that opened the scanner,
                    // then pop back to it.
                    navController.previousBackStackEntry
                        ?.savedStateHandle
                        ?.set(Destinations.RESULT_SCANNED_EPOCH_DAY, date.toEpochDay())
                    navController.popBackStack()
                },
                onCancel = { navController.popBackStack() },
            )
        }

        composable(Destinations.SETTINGS) {
            SettingsScreen(onBack = { navController.popBackStack() })
        }
    }
}

/**
 * Asks for notification permission on first launch. The app is a reminder app,
 * so this is not a peripheral nicety — without it nothing the app does reaches
 * the user.
 */
@Composable
private fun RequestNotificationPermission() {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return

    val launcher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
        onResult = { /* Declining is respected; Settings explains the cost. */ },
    )

    LaunchedEffect(Unit) {
        launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }
}
