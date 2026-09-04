package com.ghanshyam.expiry.ui.navigation

import com.ghanshyam.expiry.domain.model.TrackedItem

object Destinations {
    const val ITEMS = "items"
    const val SCAN = "scan"
    const val SETTINGS = "settings"

    const val ARG_ITEM_ID = "itemId"
    private const val EDITOR_BASE = "editor"
    const val EDITOR = "$EDITOR_BASE?$ARG_ITEM_ID={$ARG_ITEM_ID}"

    /** Pass [TrackedItem.NO_ID] (the default) to open the editor for a new item. */
    fun editor(itemId: Long = TrackedItem.NO_ID): String = "$EDITOR_BASE?$ARG_ITEM_ID=$itemId"

    /**
     * Key the scan screen writes the chosen date into, on the editor's saved
     * state handle. The editor reads it once on resume, so a rotation cannot
     * re-apply a date the user has since changed.
     */
    const val RESULT_SCANNED_EPOCH_DAY = "scanned_epoch_day"
}
