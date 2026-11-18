<?php

namespace Tests\Feature;

use App\Domain\Facts\FactValue;
use App\Domain\Facts\InvalidFactValue;
use Tests\TestCase;

/**
 * What counts as THE SAME value (the key of the unique index, so of "a dismissed value is never offered again"), ported from Comp AI's same-value.spec.ts
 * (MIT licence), and what may be stored as a value at all (G2G's checks: every value may have come from the web, so it is data and nothing else).
 */
class FactsFactValueTest extends TestCase
{
    // ---- same-value.spec.ts ----

    public function test_a_trailing_slash_is_ignored(): void
    {
        $this->assertTrue(FactValue::same('https://www.linkedin.com/in/pogrebs', 'https://www.linkedin.com/in/pogrebs/'));
    }

    public function test_www_the_scheme_and_case_are_ignored(): void
    {
        $this->assertTrue(FactValue::same('http://LinkedIn.com/in/Pogrebs/', 'https://www.linkedin.com/in/pogrebs'));
    }

    public function test_twitter_and_x_are_the_same_account(): void
    {
        $this->assertTrue(FactValue::same('https://twitter.com/pogrebs', 'https://x.com/pogrebs/'));
        $this->assertTrue(FactValue::same('https://mobile.twitter.com/pogrebs', 'https://x.com/pogrebs'));
    }

    public function test_two_different_profiles_stay_apart(): void
    {
        $this->assertFalse(FactValue::same('https://www.linkedin.com/in/pogrebs', 'https://www.linkedin.com/in/pogrebs-2'));
    }

    public function test_two_hosts_that_only_look_alike_are_not_merged(): void
    {
        $this->assertFalse(FactValue::same('https://github.com/pogrebs', 'https://gitlab.com/pogrebs'));
    }

    public function test_whitespace_in_a_plain_value_collapses(): void
    {
        $this->assertTrue(FactValue::same("  CEO &  Co-founder ", 'ceo & co-founder'));
    }

    public function test_a_plain_value_is_otherwise_left_alone(): void
    {
        $this->assertFalse(FactValue::same('Head of Security', 'Head of Marketing'));
    }

    public function test_a_query_string_is_dropped_because_a_profile_is_its_path(): void
    {
        $this->assertSame('x.com/pogrebs', FactValue::canonical('https://x.com/pogrebs?lang=en'));
        $this->assertTrue(FactValue::same('https://www.linkedin.com/in/pogrebs?originalSubdomain=uk', 'https://www.linkedin.com/in/pogrebs'));
    }

    public function test_a_value_that_is_not_a_web_address_is_compared_as_text(): void
    {
        $this->assertSame('ceo & co-founder', FactValue::canonical('CEO & Co-founder'));
        $this->assertSame('mailto:someone@example.com', FactValue::canonical('mailto:someone@example.com'));
        $this->assertSame('fernhill.com/careers', FactValue::canonical('Fernhill.com/careers'), 'a bare domain with a path is text, lower-cased');
    }

    public function test_a_non_default_port_is_part_of_the_host_and_a_default_one_is_not(): void
    {
        $this->assertSame('school.example:8080/a', FactValue::canonical('http://school.example:8080/a'));
        $this->assertSame('school.example/a', FactValue::canonical('https://school.example:443/a'));
        $this->assertSame('school.example/a', FactValue::canonical('http://school.example:80/a'));
    }

    public function test_the_hash_is_the_sha1_of_the_canonical_form_so_equal_values_share_a_key(): void
    {
        $this->assertSame(sha1('x.com/pogrebs'), FactValue::hash(FactValue::canonical('https://twitter.com/Pogrebs/')));
        $this->assertSame(40, strlen(FactValue::hash('a')));
    }

    // ---- email ----

    public function test_an_email_is_lower_cased_trimmed_and_checked(): void
    {
        $this->assertSame(['display' => 'principal@school.example', 'canonical' => 'principal@school.example'], FactValue::normalise('email', '  Principal@School.Example ', 191));
        $this->assertSame('info@school.example', FactValue::normalise('email', 'mailto:info@school.example?subject=Hi', 191)['display'], 'a scraped mailto link is read');
    }

    public function test_something_that_is_not_an_email_is_refused(): void
    {
        foreach (['', 'not an email', 'a@b', '@school.example', 'a b@school.example', 'javascript:alert(1)'] as $bad) {
            try {
                FactValue::normalise('email', $bad, 191);
                $this->fail("'{$bad}' was accepted as an email address");
            } catch (InvalidFactValue $e) {
                $this->assertSame('That is not an email address.', $e->getMessage());
            }
        }
    }

    public function test_an_email_longer_than_the_column_is_refused_not_cut_off(): void
    {
        $long = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.example';

        $this->expectException(InvalidFactValue::class);
        $this->expectExceptionMessage('longer than the 100 characters');

        FactValue::normalise('email', $long, 100);
    }

    public function test_a_role_mailbox_is_told_from_a_personal_address(): void
    {
        $this->assertSame('role', FactValue::emailKind('Principal@school.example'));
        $this->assertSame('role', FactValue::emailKind('admissions@school.example'));
        $this->assertSame('personal', FactValue::emailKind('priya.sharma@school.example'));
        $this->assertSame('personal', FactValue::emailKind('principal.office@school.example'), 'only the whole local part counts');
    }

    // ---- phone ----

    public function test_a_phone_number_keeps_its_form_and_compares_by_its_digits(): void
    {
        $a = FactValue::normalise('phone', '+91 98765 43210', 30);
        $b = FactValue::normalise('phone', '+91-98765-43210', 30);

        $this->assertSame('+91 98765 43210', $a['display']);
        $this->assertSame('+919876543210', $a['canonical']);
        $this->assertSame($a['canonical'], $b['canonical']);
        $this->assertSame('0265234567', FactValue::normalise('phone', '(0265) 234-567', 30)['canonical'], 'no plus, no country code is invented');
    }

    public function test_something_that_is_not_a_phone_number_is_refused(): void
    {
        foreach (['', '12345', 'call me', '+91 98765 43210 ext 5', '1234567890123456', '98765-abcde'] as $bad) {
            try {
                FactValue::normalise('phone', $bad, 30);
                $this->fail("'{$bad}' was accepted as a phone number");
            } catch (InvalidFactValue $e) {
                $this->assertStringContainsString('not a phone number', $e->getMessage(), $bad);
            }
        }
    }

    // ---- web address ----

    public function test_a_website_gets_a_scheme_loses_its_query_and_trailing_slash(): void
    {
        $this->assertSame('https://school.example', FactValue::normalise('url', 'School.Example/', 191)['display']);
        $this->assertSame('http://www.school.example/about', FactValue::normalise('url', 'http://WWW.school.example/about/?utm=1#top', 191)['display']);
        $this->assertSame('https://school.example:8443/x', FactValue::normalise('url', 'https://school.example:8443/x', 191)['display']);
    }

    public function test_an_address_with_any_other_scheme_is_refused_by_name(): void
    {
        foreach (['javascript:alert(1)', 'ftp://school.example', 'file:///etc/passwd', 'data:text/html,x', 'mailto:a@school.example'] as $bad) {
            try {
                FactValue::normalise('url', $bad, 191);
                $this->fail("'{$bad}' was accepted as a web address");
            } catch (InvalidFactValue $e) {
                $this->assertSame('Only web addresses (http or https) are accepted.', $e->getMessage(), $bad);
            }
        }
        $this->assertSame('https://school.example:8080/x', FactValue::normalise('url', 'school.example:8080/x', 191)['display'], 'a host with a port is not mistaken for a scheme');
    }

    public function test_only_http_and_https_addresses_are_accepted(): void
    {
        foreach (['javascript:alert(1)', 'ftp://school.example', 'file:///etc/passwd', 'data:text/html,x', 'mailto:a@school.example', 'https://u:p@school.example', 'https://localhost', 'https://school', 'https://exa mple.com', ''] as $bad) {
            try {
                FactValue::normalise('url', $bad, 191);
                $this->fail("'{$bad}' was accepted as a web address");
            } catch (InvalidFactValue $e) {
                $this->assertStringContainsString('web address', $e->getMessage(), $bad);
            }
        }
    }

    // ---- social ----

    public function test_a_profile_address_is_stored_in_its_canonical_form_and_only_for_its_own_network(): void
    {
        $this->assertSame('https://www.linkedin.com/in/pogrebs', FactValue::normalise('linkedin', 'linkedin.com/in/pogrebs/?x=1', 300)['display']);
        $this->assertSame('https://x.com/pogrebs', FactValue::normalise('x', 'https://twitter.com/pogrebs', 300)['display']);

        $this->expectException(InvalidFactValue::class);
        $this->expectExceptionMessage('not a LinkedIn profile address');
        FactValue::normalise('linkedin', 'https://x.com/pogrebs', 300);
    }

    // ---- text ----

    public function test_free_text_is_cleaned_of_control_characters_and_whitespace(): void
    {
        $this->assertSame('Head of Security', FactValue::normalise('text', "  Head\x00 of \n Security\t", 100)['display']);
        $this->assertSame('head of security', FactValue::normalise('text', 'Head  of Security', 100)['canonical']);
    }

    public function test_text_that_starts_like_a_spreadsheet_formula_is_refused(): void
    {
        foreach (['=1+1', '@SUM(A1)', '+1234', '-2+3', '+(1)', "\t=cmd"] as $bad) {
            try {
                FactValue::normalise('text', $bad, 100);
                $this->fail("'{$bad}' was accepted as text");
            } catch (InvalidFactValue $e) {
                $this->assertStringContainsString('cannot start with', $e->getMessage(), $bad);
            }
        }
        $this->assertSame('-Head of Sales', FactValue::normalise('text', '-Head of Sales', 100)['display'], 'a dash before a word is ordinary text');
    }

    public function test_empty_text_and_text_longer_than_the_column_are_refused(): void
    {
        $this->expectException(InvalidFactValue::class);
        FactValue::normalise('text', " \n\t ", 100);
    }

    public function test_text_longer_than_the_column_is_refused_not_cut_off(): void
    {
        $this->expectExceptionMessage('longer than the 100 characters');

        FactValue::normalise('text', str_repeat('x', 101), 100);
    }

    public function test_an_unknown_field_type_is_a_programming_error_and_says_so(): void
    {
        $this->expectExceptionMessage("The field type 'blob' is not known.");

        FactValue::normalise('blob', 'x', 100);
    }
}
