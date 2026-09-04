package com.ghanshyam.expiry

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.fragment.app.FragmentActivity
import com.ghanshyam.expiry.ui.ExpiryApp
import dagger.hilt.android.AndroidEntryPoint

/**
 * Extends [FragmentActivity] rather than ComponentActivity because
 * `BiometricPrompt` requires a fragment host to show its dialog.
 */
@AndroidEntryPoint
class MainActivity : FragmentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        val initialItemId = intent?.getLongExtra(EXTRA_ITEM_ID, NO_ITEM)
            ?.takeIf { it != NO_ITEM }

        setContent {
            ExpiryApp(
                activity = this,
                initialItemId = initialItemId,
            )
        }
    }

    companion object {
        /** Set by reminder notifications so tapping one opens that item. */
        const val EXTRA_ITEM_ID = "com.ghanshyam.expiry.extra.ITEM_ID"
        private const val NO_ITEM = -1L
    }
}
