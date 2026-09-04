package com.ghanshyam.expiry.domain.model

/**
 * The kind of thing being tracked. Stored by [id] rather than by ordinal or
 * enum name so that reordering or renaming a constant cannot silently
 * repoint existing rows at a different category.
 */
enum class Category(val id: String) {
    IDENTITY("identity"),
    VEHICLE("vehicle"),
    INSURANCE("insurance"),
    WARRANTY("warranty"),
    SUBSCRIPTION("subscription"),
    HEALTH("health"),
    PROFESSIONAL("professional"),
    HOME("home"),
    OTHER("other");

    companion object {
        private val byId = entries.associateBy(Category::id)

        /** Unknown ids fall back to [OTHER] so a bad backup file cannot crash the app. */
        fun fromId(id: String): Category = byId[id] ?: OTHER
    }
}
