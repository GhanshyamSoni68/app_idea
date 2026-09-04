package com.ghanshyam.expiry.ui.theme

import androidx.compose.ui.graphics.Color

// A calm teal, chosen so the urgency colours (amber, red) stand out against it
// rather than competing with it.
internal val Teal10 = Color(0xFF00201C)
internal val Teal20 = Color(0xFF003731)
internal val Teal30 = Color(0xFF005048)
internal val Teal40 = Color(0xFF006A5F)
internal val Teal80 = Color(0xFF5FDBC8)
internal val Teal90 = Color(0xFF7DF8E4)

internal val Slate10 = Color(0xFF0D1F22)
internal val Slate20 = Color(0xFF223538)
internal val Slate30 = Color(0xFF394B4F)
internal val Slate40 = Color(0xFF506367)
internal val Slate80 = Color(0xFFB8CBCF)
internal val Slate90 = Color(0xFFD4E7EB)

internal val Rust10 = Color(0xFF410002)
internal val Rust20 = Color(0xFF690005)
internal val Rust30 = Color(0xFF93000A)
internal val Rust40 = Color(0xFFBA1A1A)
internal val Rust80 = Color(0xFFFFB4AB)
internal val Rust90 = Color(0xFFFFDAD6)

internal val NeutralLight = Color(0xFFFAFDFB)
internal val NeutralDark = Color(0xFF0E1513)

/**
 * Colours for the urgency buckets. These are semantic rather than part of the
 * Material scheme, because their meaning must survive dynamic colour: if the
 * wallpaper happens to be red, "expired" still has to read as a warning.
 */
data class UrgencyColors(
    val expired: Color,
    val onExpired: Color,
    val soon: Color,
    val onSoon: Color,
    val upcoming: Color,
    val onUpcoming: Color,
    val distant: Color,
    val onDistant: Color,
)

internal val LightUrgencyColors = UrgencyColors(
    expired = Color(0xFFFFDAD6),
    onExpired = Color(0xFF93000A),
    soon = Color(0xFFFFE0B2),
    onSoon = Color(0xFF7A4100),
    upcoming = Color(0xFFFFF3C4),
    onUpcoming = Color(0xFF6B5300),
    distant = Color(0xFFDCE5E2),
    onDistant = Color(0xFF3A4A47),
)

internal val DarkUrgencyColors = UrgencyColors(
    expired = Color(0xFF5C1A17),
    onExpired = Color(0xFFFFB4AB),
    soon = Color(0xFF553100),
    onSoon = Color(0xFFFFCC80),
    upcoming = Color(0xFF4A3B00),
    onUpcoming = Color(0xFFFFE082),
    distant = Color(0xFF283533),
    onDistant = Color(0xFFB8CBCF),
)
