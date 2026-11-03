<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadDescription;
use Tests\TestCase;

/**
 * Defect D9: a lead's description is cut by US, on a character boundary, at the lower of 2,000 characters and what the column holds on this host, instead
 * of by the database (silently on the live host, with an error on the strict dev host). The width of the real column is checked on the dev database
 * (Docs/agentic-crm/_evidence/s6/task3-*).
 */
class LeadDescriptionTest extends TestCase
{
    public function test_null_stays_null_and_short_text_is_untouched(): void
    {
        $this->assertNull(LeadDescription::fit(null));
        $this->assertSame('', LeadDescription::fit(''));
        $this->assertSame('A short one.', LeadDescription::fit('A short one.'));
        $this->assertSame("two\n\nparagraphs ", LeadDescription::fit("two\n\nparagraphs "), 'whitespace is the caller\'s business');
    }

    public function test_the_text_is_cut_to_two_thousand_characters_not_bytes(): void
    {
        $text = str_repeat('é', 2500);                                   // 2 bytes each

        $fit = LeadDescription::fit($text, 65535);

        $this->assertSame(2000, mb_strlen($fit));
        $this->assertSame(4000, strlen($fit));
        $this->assertSame(2000, LeadDescription::MAX_CHARS);
    }

    public function test_on_a_host_with_the_old_tinytext_the_cut_is_at_255_bytes_and_never_inside_a_character(): void
    {
        $text = str_repeat('é', 300);                                    // 2 bytes each: 255 bytes would end in the middle of one

        $fit = LeadDescription::fit($text, 255);

        $this->assertLessThanOrEqual(255, strlen($fit));
        $this->assertSame(254, strlen($fit), 'the last whole character that fits');
        $this->assertTrue(mb_check_encoding($fit, 'UTF-8'));
        $this->assertSame(255, strlen(LeadDescription::fit(str_repeat('x', 400), 255)));
    }

    public function test_a_host_that_is_not_mariadb_holds_the_wide_column(): void
    {
        $this->assertSame(65535, LeadDescription::maxBytes());
    }
}
