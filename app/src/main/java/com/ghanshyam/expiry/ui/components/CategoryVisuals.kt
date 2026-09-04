package com.ghanshyam.expiry.ui.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Badge
import androidx.compose.material.icons.outlined.Business
import androidx.compose.material.icons.outlined.DirectionsCar
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.LocalHospital
import androidx.compose.material.icons.outlined.MoreHoriz
import androidx.compose.material.icons.outlined.Autorenew
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material.icons.outlined.Verified
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import com.ghanshyam.expiry.R
import com.ghanshyam.expiry.domain.model.Category

fun Category.icon(): ImageVector = when (this) {
    Category.IDENTITY -> Icons.Outlined.Badge
    Category.VEHICLE -> Icons.Outlined.DirectionsCar
    Category.INSURANCE -> Icons.Outlined.Shield
    Category.WARRANTY -> Icons.Outlined.Verified
    Category.SUBSCRIPTION -> Icons.Outlined.Autorenew
    Category.HEALTH -> Icons.Outlined.LocalHospital
    Category.PROFESSIONAL -> Icons.Outlined.Business
    Category.HOME -> Icons.Outlined.Home
    Category.OTHER -> Icons.Outlined.MoreHoriz
}

@Composable
fun Category.label(): String = stringResource(
    when (this) {
        Category.IDENTITY -> R.string.category_identity
        Category.VEHICLE -> R.string.category_vehicle
        Category.INSURANCE -> R.string.category_insurance
        Category.WARRANTY -> R.string.category_warranty
        Category.SUBSCRIPTION -> R.string.category_subscription
        Category.HEALTH -> R.string.category_health
        Category.PROFESSIONAL -> R.string.category_professional
        Category.HOME -> R.string.category_home
        Category.OTHER -> R.string.category_other
    },
)
