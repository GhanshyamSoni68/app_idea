package com.ghanshyam.expiry.ocr

import com.google.common.truth.Truth.assertThat
import org.junit.Test
import java.time.LocalDate
import java.util.Locale

class DateExtractorTest {

    private val extractor = DateExtractor()
    private val today = LocalDate.of(2026, 3, 1)
    private val uk = Locale.UK
    private val us = Locale.US

    private fun extract(text: String, locale: Locale = uk) =
        extractor.extract(text, today, locale)

    private fun topDate(text: String, locale: Locale = uk) =
        extract(text, locale).firstOrNull()?.date

    @Test
    fun `reads an ISO date`() {
        assertThat(topDate("Valid until 2029-07-14")).isEqualTo(LocalDate.of(2029, 7, 14))
    }

    @Test
    fun `reads a day-month-year date written with words`() {
        assertThat(topDate("Expiry 14 July 2029")).isEqualTo(LocalDate.of(2029, 7, 14))
        assertThat(topDate("Expires 14-Jul-29")).isEqualTo(LocalDate.of(2029, 7, 14))
    }

    @Test
    fun `reads a month-first date written with words`() {
        assertThat(topDate("Expires July 14, 2029")).isEqualTo(LocalDate.of(2029, 7, 14))
    }

    @Test
    fun `a day above twelve settles the order regardless of locale`() {
        // 14 cannot be a month, so both locales must read this the same way.
        assertThat(topDate("Valid until 14/07/2029", uk)).isEqualTo(LocalDate.of(2029, 7, 14))
        assertThat(topDate("Valid until 14/07/2029", us)).isEqualTo(LocalDate.of(2029, 7, 14))
    }

    @Test
    fun `an ambiguous numeric date follows the locale but offers the alternative`() {
        val ukCandidates = extract("Valid until 04/03/2029", uk)
        val usCandidates = extract("Valid until 04/03/2029", us)

        assertThat(ukCandidates.first().date).isEqualTo(LocalDate.of(2029, 3, 4))
        assertThat(usCandidates.first().date).isEqualTo(LocalDate.of(2029, 4, 3))

        // The other reading is still offered, so a wrong guess costs one tap.
        assertThat(ukCandidates.map { it.date }).contains(LocalDate.of(2029, 4, 3))
        assertThat(ukCandidates.first().ambiguous).isTrue()
    }

    @Test
    fun `an equal day and month is not ambiguous`() {
        val candidates = extract("Valid until 03/03/2029")
        assertThat(candidates).hasSize(1)
        assertThat(candidates.first().ambiguous).isFalse()
    }

    @Test
    fun `a card-style month and year expires at the end of that month`() {
        assertThat(topDate("VALID THRU 09/29")).isEqualTo(LocalDate.of(2029, 9, 30))
        assertThat(topDate("Expires February 2028")).isEqualTo(LocalDate.of(2028, 2, 29))
    }

    @Test
    fun `the expiry date outranks the issue date on the same document`() {
        val text = """
            REPUBLIC OF EXAMPLE
            Date of issue 12 May 2019
            Date of expiry 12 May 2029
        """.trimIndent()

        assertThat(topDate(text)).isEqualTo(LocalDate.of(2029, 5, 12))
    }

    @Test
    fun `a date of birth is ranked below a genuine expiry`() {
        val text = """
            Date of birth 04 August 1994
            Expires 04 August 2031
        """.trimIndent()

        val candidates = extract(text)
        assertThat(candidates.first().date).isEqualTo(LocalDate.of(2031, 8, 4))

        val birth = candidates.single { it.date == LocalDate.of(1994, 8, 4) }
        assertThat(birth.confidence).isLessThan(candidates.first().confidence)
    }

    @Test
    fun `a two-digit year lands in the century that makes sense`() {
        // Expiry-shaped, so the near future.
        assertThat(topDate("Expires 14-Jul-29")).isEqualTo(LocalDate.of(2029, 7, 14))
        // A birth year cannot be in the future, so it belongs to the last century.
        assertThat(topDate("Date of birth 14-Jul-94")).isEqualTo(LocalDate.of(1994, 7, 14))
    }

    @Test
    fun `an impossible date is discarded rather than clamped`() {
        assertThat(extract("Valid until 31/02/2029")).isEmpty()
        assertThat(extract("Expires 2029-13-01")).isEmpty()
    }

    @Test
    fun `each date appears once however many times it is printed`() {
        val text = "Expires 2029-07-14. Reminder: 2029-07-14 is the deadline."
        val dates = extract(text).map { it.date }
        assertThat(dates).containsNoDuplicates()
    }

    @Test
    fun `empty and date-free text yield nothing`() {
        assertThat(extract("")).isEmpty()
        assertThat(extract("   ")).isEmpty()
        assertThat(extract("No dates on this page at all")).isEmpty()
    }

    @Test
    fun `confidence stays within range`() {
        val candidates = extract("Date of birth 01/01/1970 Expiry 2029-07-14")
        assertThat(candidates).isNotEmpty()
        candidates.forEach { candidate ->
            assertThat(candidate.confidence).isAtLeast(0f)
            assertThat(candidate.confidence).isAtMost(1f)
        }
    }

    @Test
    fun `the matched text is reported so the user can tell candidates apart`() {
        val candidate = extract("Valid until 2029-07-14").first()
        assertThat(candidate.matchedText).isEqualTo("2029-07-14")
    }
}
