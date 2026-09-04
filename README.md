# Expiry

An Android app for everything in your life that runs out — passports, visas,
driving licences, insurance policies, vehicle registration, product warranties,
professional certifications, domain names, free trials before they auto-charge.

Point the camera at a document, and on-device OCR reads the expiry date off it.
Everything is stored encrypted on the phone. Nothing is uploaded, there is no
account, and there are no ads.

## Why this app

People already track these dates — in a notes app, a calendar, a drawer full of
paper, or not at all. The cost of missing one is real and specific: a fine for
lapsed vehicle insurance, a flight refused at check-in because a passport falls
inside the six-month rule, a free trial that silently becomes a subscription.

Existing apps solve one slice each — a warranty tracker here, a subscription
tracker there — and most want an account before they will show you anything. The
gap is a single, private place for all of it, where adding an item takes a photo
rather than a form.

## What it does

- **Scan a date.** CameraX feeds frames to ML Kit's on-device text recogniser.
  Candidate dates are extracted and ranked, then the user confirms one.
- **Rank sensibly.** A document shows several dates. The ranking reads the words
  around each one ("valid until" is evidence for, "date of birth" is evidence
  against), prefers dates in the future, and resolves `04/03/27` using the
  device locale while still offering the other reading.
- **Remind, without nagging.** A daily background check posts one notification
  per item, stating the real number of days left. Windows missed while the phone
  was off collapse into a single catch-up reminder rather than a burst.
- **Encrypt at rest.** The database is SQLCipher-encrypted with a random key
  held in `EncryptedSharedPreferences`, sealed by an Android Keystore key that
  cannot leave the device. An optional biometric lock gates the app itself.
- **Stay portable.** Because the database key is device-bound, cloud backup is
  disabled. Instead the user exports an AES-GCM encrypted backup file under a
  passphrase of their own choosing.

## Architecture

Single Gradle module, layered by package, with dependencies pointing inward.

```
domain/      Models and repository interfaces. Pure Kotlin, no Android.
data/        Room + SQLCipher, DataStore settings, encrypted backup codec.
ocr/         ML Kit wrapper, and the date extraction and ranking logic.
reminder/    Scheduling, evaluation and notification.
security/    Database key provider, biometric gate.
ui/          Compose screens and ViewModels, Material 3.
di/          Hilt modules.
```

The pieces worth reading first are `ocr/DateExtractor.kt` (the ranking) and
`reminder/ReminderEvaluator.kt` (what is due, and why it does not spam). Both
are deliberately free of Android types so they are covered by plain JVM unit
tests.

**Stack:** Kotlin, Jetpack Compose + Material 3 (with dynamic colour), Hilt,
Room, WorkManager, DataStore, CameraX, ML Kit Text Recognition, SQLCipher,
AndroidX Biometric.

**Min SDK 26**, target 35. API 26 gives `java.time` without desugaring, which
matters here because the whole app is date arithmetic.

## Building

```bash
./gradlew assembleDebug     # debug APK
./gradlew testDebugUnitTest # unit tests
```

Open in Android Studio and let it sync; it will fetch the Android SDK
components and may offer newer dependency versions than the ones pinned in
`gradle/libs.versions.toml`.

For a release build, copy `keystore.properties.example` to
`keystore.properties` and fill it in. That file is gitignored — the keystore and
its passwords must never be committed.

## Design decisions worth defending

**Dates are `LocalDate`, not instants.** An expiry date is a calendar fact
printed on a document. A passport that expires on 4 March expires on 4 March
wherever its holder happens to be standing, so the value must not shift when the
device changes time zone.

**Periodic work, not exact alarms.** A reminder that something expires in a week
does not need to land on the second, and exact alarms require
`SCHEDULE_EXACT_ALARM`, which Google Play grants only to apps whose core purpose
is alarms and clocks. Work that is a few minutes late is fine; a rejected
release is not.

**No `fallbackToDestructiveMigration`.** Silently deleting someone's documents
on a schema change would be the worst failure this app could have. Migrations
get written by hand.

**The reminder worker retries rather than marking.** If notifications cannot be
posted, nothing is recorded as sent — marking it would burn the user's only
warning on a notification they never saw.

**A wrong backup passphrase is reported as a wrong passphrase.** AES-GCM
authenticates the ciphertext, so a bad passphrase fails the tag check instead of
producing plausible-looking garbage. That is what lets restore distinguish
"wrong passphrase" from "damaged file".

## Publishing notes

New personal Play Console accounts (created after 13 November 2023) must run a
closed test with **12 testers opted in continuously for 14 days** before
production access is granted. Google verifies that testers actually used the
app, and fake testers risk account termination. Plan the closed test around real
people who will genuinely install and open it.

## Status

The feature set above is implemented. Not yet built:

- Photo attachments on items (encrypted with Jetpack Security `EncryptedFile`)
- A home-screen widget for what is expiring soon
- Recurring items that roll forward automatically on renewal
- Instrumented tests for the Room and WorkManager layers
