<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Outreach\OutreachReach;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What can be done with a person and a company in the Outreach tab. Pure rules: one row of facts in, one word out.
 */
class OutreachReachTest extends TestCase
{
    private const MAIL = 'principal@riverdale-school.org';

    private const PHONE = '+919876543210';

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function persons(): array
    {
        return [
            'email and phone' => [['email' => self::MAIL, 'phone' => self::PHONE], 'ready'],
            'email only' => [['email' => self::MAIL], 'email_only'],
            'phone only' => [['phone' => self::PHONE], 'phone_only'],
            'mobile counts as a phone' => [['email' => self::MAIL, 'mobile' => '9876543210'], 'ready'],
            'second email counts as an email' => [['secondary_email' => self::MAIL], 'email_only'],
            'nothing found' => [[], 'needs_details'],
            'a name alone is not a way to reach them' => [['full_name' => 'Meera Patel'], 'needs_details'],
            'an unusable email is nothing' => [['email' => 'noreply@riverdale-school.org'], 'needs_details'],
            'a test address is nothing' => [['email' => 'someone@example.com'], 'needs_details'],
            'a number that is not a number is nothing' => [['phone' => '12345'], 'needs_details'],
            'opted out' => [['email' => self::MAIL, 'phone' => self::PHONE, 'consent_status' => 'opted_out'], 'blocked'],
            'suppressed' => [['email' => self::MAIL, 'consent_status' => 'suppressed'], 'blocked'],
            'a suppression stamp blocks even with no answer' => [['email' => self::MAIL, 'suppressed_at' => '2026-11-01 10:00:00'], 'blocked'],
            'no email asked for leaves the phone' => [['email' => self::MAIL, 'phone' => self::PHONE, 'email_opt_out' => 1], 'phone_only'],
            'do not call leaves the email' => [['email' => self::MAIL, 'phone' => self::PHONE, 'do_not_call' => 1], 'email_only'],
            'both channels ruled out' => [['email' => self::MAIL, 'phone' => self::PHONE, 'email_opt_out' => 1, 'do_not_call' => 1], 'blocked'],
            'the only channel ruled out' => [['email' => self::MAIL, 'email_opt_out' => 1], 'blocked'],
            'a flag with nothing to rule out' => [['do_not_call' => 1], 'needs_details'],
            'agreed to hear from us changes nothing by itself' => [['email' => self::MAIL, 'consent_status' => 'opted_in'], 'email_only'],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('persons')]
    public function test_a_person_has_the_reach_their_facts_give(array $row, string $expected): void
    {
        $this->assertSame($expected, OutreachReach::forPerson($row));
        $this->assertSame($expected, OutreachReach::forPerson((object) $row), 'a row from the database (an object) is read the same way');
    }

    public function test_a_company_has_the_best_reach_among_its_people(): void
    {
        $this->assertSame('ready', OutreachReach::forCompany(['needs_details', 'ready', 'blocked']));
        $this->assertSame('email_only', OutreachReach::forCompany(['phone_only', 'email_only']));
        $this->assertSame('phone_only', OutreachReach::forCompany(['needs_details', 'phone_only']));
    }

    public function test_a_company_with_nobody_to_try_needs_details_and_is_blocked_only_when_everyone_is(): void
    {
        $this->assertSame('needs_details', OutreachReach::forCompany([]));
        $this->assertSame('needs_details', OutreachReach::forCompany(['needs_details', 'needs_details']));
        $this->assertSame('needs_details', OutreachReach::forCompany(['blocked', 'needs_details']), 'someone is still without details, so there is something to do');
        $this->assertSame('blocked', OutreachReach::forCompany(['blocked']));
        $this->assertSame('blocked', OutreachReach::forCompany(['blocked', 'blocked']));
    }

    public function test_only_three_reaches_can_be_written_to(): void
    {
        foreach (['ready', 'email_only', 'phone_only'] as $reach) {
            $this->assertTrue(OutreachReach::isReachable($reach), $reach);
        }
        foreach (['needs_details', 'blocked', 'nonsense'] as $reach) {
            $this->assertFalse(OutreachReach::isReachable($reach), $reach);
        }
    }

    public function test_it_says_in_words_why_someone_cannot_be_written_to(): void
    {
        $this->assertSame('They opted out.', OutreachReach::whyBlocked(['consent_status' => 'opted_out']));
        $this->assertSame('Suppressed: bounced.', OutreachReach::whyBlocked(['consent_status' => 'suppressed', 'suppressed_reason' => 'bounced']));
        $this->assertSame('They were suppressed.', OutreachReach::whyBlocked(['consent_status' => 'suppressed']));
        $this->assertSame('They asked for no email and no calls.', OutreachReach::whyBlocked(['email_opt_out' => 1, 'do_not_call' => 1]));
        $this->assertSame('They asked for no email.', OutreachReach::whyBlocked(['email_opt_out' => 1]));
        $this->assertSame('They asked for no calls.', OutreachReach::whyBlocked(['do_not_call' => 1]));
    }

    public function test_the_address_to_write_to_is_the_main_email_else_the_second_one_in_lower_case(): void
    {
        $this->assertSame(self::MAIL, OutreachReach::emailOf(['email' => 'Principal@Riverdale-School.org']));
        $this->assertSame(self::MAIL, OutreachReach::emailOf(['email' => 'noreply@riverdale-school.org', 'secondary_email' => self::MAIL]));
        $this->assertNull(OutreachReach::emailOf(['phone' => self::PHONE]));
    }
}
