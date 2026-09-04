package com.ghanshyam.expiry.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.ghanshyam.expiry.domain.model.Urgency
import com.ghanshyam.expiry.ui.theme.LocalUrgencyColors

/** Small coloured pill carrying the "expires in N days" line. */
@Composable
fun UrgencyChip(
    text: String,
    urgency: Urgency,
    modifier: Modifier = Modifier,
) {
    val (container, content) = urgencyColorsFor(urgency)
    Text(
        text = text,
        style = MaterialTheme.typography.labelMedium,
        color = content,
        modifier = modifier
            .clip(RoundedCornerShape(percent = 50))
            .background(container)
            .padding(PaddingValues(horizontal = 10.dp, vertical = 4.dp)),
    )
}

@Composable
fun urgencyColorsFor(urgency: Urgency): Pair<Color, Color> {
    val colors = LocalUrgencyColors.current
    return when (urgency) {
        Urgency.EXPIRED -> colors.expired to colors.onExpired
        Urgency.THIS_WEEK -> colors.soon to colors.onSoon
        Urgency.THIS_MONTH -> colors.upcoming to colors.onUpcoming
        Urgency.LATER -> colors.distant to colors.onDistant
    }
}
