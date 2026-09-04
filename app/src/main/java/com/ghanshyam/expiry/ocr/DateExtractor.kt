package com.ghanshyam.expiry.ocr

import java.time.DateTimeException
import java.time.LocalDate
import java.time.format.TextStyle
import java.time.temporal.ChronoUnit
import java.util.Locale
import javax.inject.Inject
import javax.inject.Singleton

/**
 * A date found in scanned text, with a guess at how likely it is to be the
 * expiry date the user is looking for.
 */
data class DateCandidate(
    val date: LocalDate,
    val confidence: Float,
    val matchedText: String,
    /** True when day/month order could not be resolved (e.g. `04/03/27`). */
    val ambiguous: Boolean = false,
)

/**
 * Pulls dates out of raw OCR text and ranks them.
 *
 * Documents are noisy: a passport shows a date of birth, a date of issue and a
 * date of expiry, and OCR returns all three in no particular order. Rather
 * than guessing and being confidently wrong, this ranks candidates and the UI
 * asks the user to confirm — but a good ranking means the right answer is
 * usually already at the top.
 *
 * Ranking uses three signals:
 * - **Nearby words.** "Valid until" beside a date is strong evidence; "date of
 *   birth" is strong evidence against.
 * - **Direction in time.** Expiry dates are normally in the future, and a date
 *   decades in the past is almost certainly a birth date.
 * - **Precision.** A four-digit year is more trustworthy than a two-digit one.
 *
 * Pure Kotlin with no Android dependency, so the whole ranking is unit-testable.
 */
@Singleton
class DateExtractor @Inject constructor() {

    /**
     * One reading of a matched token. [demoted] marks the losing side of an
     * ambiguous day/month pair: still offered, but never above the reading the
     * locale's own convention implies.
     */
    private data class ParsedDate(
        val date: LocalDate,
        val ambiguous: Boolean = false,
        val demoted: Boolean = false,
    )

    fun extract(
        text: String,
        today: LocalDate,
        locale: Locale = Locale.getDefault(),
    ): List<DateCandidate> {
        if (text.isBlank()) return emptyList()

        val monthNames = monthNamesFor(locale)
        val dayFirst = prefersDayFirst(locale)
        val consumed = mutableListOf<IntRange>()
        val candidates = mutableListOf<DateCandidate>()

        // Ordered most specific first: an ISO date would otherwise be partly
        // eaten by the looser numeric pattern, and a full date by the
        // month/year one.
        for (pattern in patterns(monthNames)) {
            for (match in pattern.regex.findAll(text)) {
                if (consumed.any { it.overlaps(match.range) }) continue

                // Claim the span whether or not it parses. A date-shaped token
                // that turns out to be impossible has still been accounted
                // for, and letting a looser pattern re-read part of it would
                // turn a rejection into a wrong answer.
                consumed += match.range

                val parsed = pattern.parse(match, monthNames, dayFirst, today) ?: continue
                candidates += parsed.map { reading ->
                    DateCandidate(
                        date = reading.date,
                        confidence = score(
                            date = reading.date,
                            today = today,
                            context = contextAround(text, match.range),
                            ambiguous = reading.ambiguous,
                            demoted = reading.demoted,
                        ),
                        matchedText = match.value.trim(),
                        ambiguous = reading.ambiguous,
                    )
                }
            }
        }

        // Sort before de-duplicating: the same date read from two places
        // should survive at the higher of its two confidences, and distinctBy
        // keeps whichever it sees first.
        return candidates
            .sortedWith(compareByDescending<DateCandidate> { it.confidence }.thenBy { it.date })
            .distinctBy { it.date }
    }

    // ---- scoring ----------------------------------------------------------

    private fun score(
        date: LocalDate,
        today: LocalDate,
        context: String,
        ambiguous: Boolean,
        demoted: Boolean,
    ): Float {
        var score = BASE_SCORE

        val lowered = context.lowercase(Locale.ROOT)
        if (EXPIRY_HINTS.any { it in lowered }) score += EXPIRY_HINT_BONUS
        if (NEGATIVE_HINTS.any { it in lowered }) score -= NEGATIVE_HINT_PENALTY

        val daysAway = ChronoUnit.DAYS.between(today, date)
        val yearsAway = ChronoUnit.YEARS.between(today, date)
        score += when {
            // Documents rarely stay valid beyond a decade; a date far out is
            // more likely a misread than a real expiry.
            daysAway >= 0 && yearsAway <= PLAUSIBLE_FUTURE_YEARS -> FUTURE_BONUS
            daysAway >= 0 -> FUTURE_BONUS / 2f
            yearsAway <= -LIKELY_BIRTH_YEARS -> -DISTANT_PAST_PENALTY
            else -> -RECENT_PAST_PENALTY
        }

        if (ambiguous) score -= AMBIGUITY_PENALTY
        if (demoted) score -= ALTERNATIVE_PENALTY

        return score.coerceIn(0f, 1f)
    }

    /** Text immediately before the match, where a label like "Valid until" sits. */
    private fun contextAround(text: String, range: IntRange): String {
        val start = (range.first - CONTEXT_CHARS).coerceAtLeast(0)
        val end = (range.last + 1 + CONTEXT_CHARS / 2).coerceAtMost(text.length)
        return text.substring(start, end)
    }

    // ---- patterns ---------------------------------------------------------

    private class Pattern(
        val regex: Regex,
        val parse: (MatchResult, Map<String, Int>, Boolean, LocalDate) -> List<ParsedDate>?,
    )

    private fun patterns(monthNames: Map<String, Int>): List<Pattern> {
        val month = monthNames.keys.sortedByDescending(String::length).joinToString("|") { Regex.escape(it) }

        return listOf(
            // 2027-03-12
            Pattern(Regex("""\b(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})\b""")) { m, _, _, _ ->
                val (y, mo, d) = m.destructured
                dateOrNull(y.toInt(), mo.toInt(), d.toInt())?.let { listOf(ParsedDate(it)) }
            },

            // 12 March 2027 / 12-Mar-27
            Pattern(Regex("""\b(\d{1,2})(?:st|nd|rd|th)?[\s\-.,]+($month)[\s\-.,]+(\d{2,4})\b""", RegexOption.IGNORE_CASE)) { m, names, _, today ->
                val (d, mo, y) = m.destructured
                val monthNumber = names[mo.lowercase(Locale.ROOT)] ?: return@Pattern null
                dateOrNull(expandYear(y, today), monthNumber, d.toInt())?.let { listOf(ParsedDate(it)) }
            },

            // March 12, 2027 / Mar 12 27
            Pattern(Regex("""\b($month)[\s\-.,]+(\d{1,2})(?:st|nd|rd|th)?[\s\-.,]+(\d{2,4})\b""", RegexOption.IGNORE_CASE)) { m, names, _, today ->
                val (mo, d, y) = m.destructured
                val monthNumber = names[mo.lowercase(Locale.ROOT)] ?: return@Pattern null
                dateOrNull(expandYear(y, today), monthNumber, d.toInt())?.let { listOf(ParsedDate(it)) }
            },

            // 12/03/2027 — order depends on locale, and may be unresolvable.
            Pattern(Regex("""\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})\b""")) { m, _, dayFirst, today ->
                val (first, second, rawYear) = m.destructured
                resolveNumericOrder(first.toInt(), second.toInt(), expandYear(rawYear, today), dayFirst)
            },

            // 03/27 or 03-2027: a card-style month/year. The last day of that
            // month is the real deadline, which is how card expiry works.
            Pattern(Regex("""\b(0?[1-9]|1[0-2])[-/](\d{2}|\d{4})\b""")) { m, _, _, today ->
                val (mo, y) = m.destructured
                endOfMonthOrNull(expandYear(y, today), mo.toInt())?.let { listOf(ParsedDate(it)) }
            },

            // March 2027
            Pattern(Regex("""\b($month)[\s\-.,]+(\d{4})\b""", RegexOption.IGNORE_CASE)) { m, names, _, _ ->
                val (mo, y) = m.destructured
                val monthNumber = names[mo.lowercase(Locale.ROOT)] ?: return@Pattern null
                endOfMonthOrNull(y.toInt(), monthNumber)?.let { listOf(ParsedDate(it)) }
            },
        )
    }

    /**
     * Decides whether `04/03/27` is 4 March or 3 April.
     *
     * A value above 12 can only be a day, which settles it outright. When both
     * numbers could be either, the locale's own convention decides and the
     * result is flagged ambiguous — but the other reading is returned too, so
     * the user can pick it without rescanning.
     */
    private fun resolveNumericOrder(
        first: Int,
        second: Int,
        year: Int,
        dayFirst: Boolean,
    ): List<ParsedDate>? {
        val firstCouldBeMonth = first in 1..12
        val secondCouldBeMonth = second in 1..12

        return when {
            firstCouldBeMonth && secondCouldBeMonth -> {
                val dayFirstReading = dateOrNull(year, second, first)
                val monthFirstReading = dateOrNull(year, first, second)
                val preferred = if (dayFirst) dayFirstReading else monthFirstReading
                val alternative = if (dayFirst) monthFirstReading else dayFirstReading

                when {
                    // 03/03 reads the same either way, so there is nothing to
                    // be ambiguous about.
                    preferred != null && preferred == alternative ->
                        listOf(ParsedDate(preferred))

                    else -> listOfNotNull(
                        preferred?.let { ParsedDate(it, ambiguous = true) },
                        alternative?.let { ParsedDate(it, ambiguous = true, demoted = true) },
                    ).takeIf { it.isNotEmpty() }
                }
            }

            secondCouldBeMonth -> dateOrNull(year, second, first)?.let { listOf(ParsedDate(it)) }
            firstCouldBeMonth -> dateOrNull(year, first, second)?.let { listOf(ParsedDate(it)) }
            else -> null
        }
    }

    /**
     * Widens a two-digit year using a sliding window rather than a fixed
     * century, so `98` reads as 1998 while `27` reads as 2027.
     */
    private fun expandYear(raw: String, today: LocalDate): Int {
        if (raw.length == 4) return raw.toInt()
        val twoDigit = raw.toInt()
        val century = (today.year / 100) * 100
        val candidate = century + twoDigit
        return when {
            candidate > today.year + FUTURE_WINDOW_YEARS -> candidate - 100
            candidate < today.year - PAST_WINDOW_YEARS -> candidate + 100
            else -> candidate
        }
    }

    private fun dateOrNull(year: Int, month: Int, day: Int): LocalDate? = try {
        LocalDate.of(year, month, day)
    } catch (invalid: DateTimeException) {
        null
    }

    private fun endOfMonthOrNull(year: Int, month: Int): LocalDate? = try {
        LocalDate.of(year, month, 1).withDayOfMonth(LocalDate.of(year, month, 1).lengthOfMonth())
    } catch (invalid: DateTimeException) {
        null
    }

    /**
     * Month names in English plus the device locale. English is always
     * included because travel and financial documents are routinely printed in
     * English regardless of where their holder lives.
     */
    private fun monthNamesFor(locale: Locale): Map<String, Int> = buildMap {
        for (styleLocale in setOf(Locale.ENGLISH, locale)) {
            for (month in java.time.Month.values()) {
                for (style in listOf(TextStyle.FULL, TextStyle.SHORT)) {
                    val name = month.getDisplayName(style, styleLocale)
                        .lowercase(Locale.ROOT)
                        .removeSuffix(".")
                    if (name.length >= 3 && name.all(Char::isLetter)) put(name, month.value)
                }
            }
        }
    }

    /**
     * Whether the locale writes the day before the month. The US, and a
     * handful of territories that follow its conventions, are the exceptions.
     */
    private fun prefersDayFirst(locale: Locale): Boolean =
        locale.country.uppercase(Locale.ROOT) !in MONTH_FIRST_COUNTRIES

    private fun IntRange.overlaps(other: IntRange): Boolean =
        first <= other.last && other.first <= last

    private companion object {
        const val BASE_SCORE = 0.35f
        const val EXPIRY_HINT_BONUS = 0.4f
        const val NEGATIVE_HINT_PENALTY = 0.35f
        const val FUTURE_BONUS = 0.25f
        const val DISTANT_PAST_PENALTY = 0.3f
        const val RECENT_PAST_PENALTY = 0.15f
        const val AMBIGUITY_PENALTY = 0.1f
        const val ALTERNATIVE_PENALTY = 0.1f
        const val CONTEXT_CHARS = 40
        const val PLAUSIBLE_FUTURE_YEARS = 15L
        const val LIKELY_BIRTH_YEARS = 15L
        const val FUTURE_WINDOW_YEARS = 60
        const val PAST_WINDOW_YEARS = 40

        val EXPIRY_HINTS = listOf(
            "expiry", "expires", "expire", "expiration", "exp date", "exp.",
            "valid until", "valid till", "valid thru", "valid through", "valid to",
            "good through", "good thru", "renew", "renewal", "due", "best before",
            "use by", "end date", "terminates", "lapses",
        )

        val NEGATIVE_HINTS = listOf(
            "birth", "d.o.b", "dob", "born",
            "issue", "issued", "date of issue", "issuing",
            "start date", "from", "printed", "purchased", "purchase date",
        )

        /** Countries that conventionally write month before day. */
        val MONTH_FIRST_COUNTRIES = setOf("US", "PH", "FM", "MH", "PW")
    }
}
