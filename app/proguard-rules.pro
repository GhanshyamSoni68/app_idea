# SQLCipher ships native bindings that must not be renamed or stripped.
-keep class net.zetetic.database.** { *; }
-keep class net.sqlcipher.** { *; }

# ML Kit text recognition loads model classes reflectively.
-keep class com.google.mlkit.** { *; }
-dontwarn com.google.mlkit.**

# Room generated implementations.
-keep class * extends androidx.room.RoomDatabase { <init>(); }

# Keep the encrypted-backup DTOs: they are serialised by name.
-keep class com.ghanshyam.expiry.data.backup.** { *; }
