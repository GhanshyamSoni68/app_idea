package com.ghanshyam.expiry.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * The default Material 3 scale, with the list-facing styles tightened.
 * Item titles and dates sit next to each other in dense rows, so they need
 * slightly closer tracking than the stock display styles give.
 */
internal val ExpiryTypography = Typography().run {
    copy(
        titleMedium = titleMedium.merge(
            TextStyle(fontWeight = FontWeight.SemiBold, letterSpacing = 0.sp),
        ),
        labelLarge = labelLarge.merge(
            TextStyle(fontFamily = FontFamily.Default, fontWeight = FontWeight.Medium),
        ),
    )
}
