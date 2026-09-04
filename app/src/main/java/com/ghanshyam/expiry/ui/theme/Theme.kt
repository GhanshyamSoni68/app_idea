package com.ghanshyam.expiry.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalOf
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.platform.LocalContext

private val LightScheme = lightColorScheme(
    primary = Teal40,
    onPrimary = NeutralLight,
    primaryContainer = Teal90,
    onPrimaryContainer = Teal10,
    secondary = Slate40,
    onSecondary = NeutralLight,
    secondaryContainer = Slate90,
    onSecondaryContainer = Slate10,
    error = Rust40,
    onError = NeutralLight,
    errorContainer = Rust90,
    onErrorContainer = Rust10,
    background = NeutralLight,
    surface = NeutralLight,
)

private val DarkScheme = darkColorScheme(
    primary = Teal80,
    onPrimary = Teal20,
    primaryContainer = Teal30,
    onPrimaryContainer = Teal90,
    secondary = Slate80,
    onSecondary = Slate20,
    secondaryContainer = Slate30,
    onSecondaryContainer = Slate90,
    error = Rust80,
    onError = Rust20,
    errorContainer = Rust30,
    onErrorContainer = Rust90,
    background = NeutralDark,
    surface = NeutralDark,
)

val LocalUrgencyColors: CompositionLocalOf<UrgencyColors> =
    staticCompositionLocalOf { LightUrgencyColors }

@Composable
fun ExpiryTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    /** Material You wallpaper colours, where the platform offers them. */
    dynamicColor: Boolean = true,
    content: @Composable () -> Unit,
) {
    val supportsDynamic = dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S
    val context = LocalContext.current

    val colorScheme = when {
        supportsDynamic && darkTheme -> dynamicDarkColorScheme(context)
        supportsDynamic -> dynamicLightColorScheme(context)
        darkTheme -> DarkScheme
        else -> LightScheme
    }

    androidx.compose.runtime.CompositionLocalProvider(
        LocalUrgencyColors provides if (darkTheme) DarkUrgencyColors else LightUrgencyColors,
    ) {
        MaterialTheme(
            colorScheme = colorScheme,
            typography = ExpiryTypography,
            content = content,
        )
    }
}
