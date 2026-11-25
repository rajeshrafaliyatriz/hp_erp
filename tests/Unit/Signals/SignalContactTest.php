<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\SignalContact;
use PHPUnit\Framework\TestCase;

class SignalContactTest extends TestCase
{
    private function row(array $o = []): object
    {
        return (object) ($o + ['company_name' => 'Sunrise Public School', 'company_website' => 'https://sunrise.example.org', 'company_location' => 'Pune',
            'observed_event' => 'The school is looking for a fee tool.', 'source_url' => 'https://news.test/a']);
    }

    public function test_email_and_phone_validation(): void
    {
        $this->assertTrue(SignalContact::validEmail('principal@sunriseschool.in'));
        foreach (['', 'nope', 'a@b', 'noreply@school.in', 'x@example.com', 'logo@2x.png'] as $bad) {
            $this->assertFalse(SignalContact::validEmail($bad), $bad);
        }
        $this->assertSame('+919876543210', SignalContact::normalisePhone('98765 43210'));
        $this->assertSame('+919876543210', SignalContact::normalisePhone('+91-98765-43210'));
        $this->assertSame('+919876543210', SignalContact::normalisePhone('09876543210'));
        $this->assertSame('+14155550123', SignalContact::normalisePhone('+1 415 555 0123'));
        foreach (['', '12345', '1234567890', '0000000000', 'abc'] as $bad) {
            $this->assertNull(SignalContact::normalisePhone($bad), $bad);
        }
    }

    public function test_it_reports_only_what_the_evidence_text_states(): void
    {
        $c = SignalContact::build($this->row(['observed_event' => 'Contact the office at admin@sunriseschool.in or call 98765 43210 to book a demo.']));
        $this->assertSame('complete', $c['status']);
        $this->assertSame('admin@sunriseschool.in', $c['email']);
        $this->assertSame('+919876543210', $c['phone']);
        $this->assertSame('stated_in_source', $c['email_source']['status']);
        $this->assertSame('https://news.test/a', $c['phone_source']['url']);
        $this->assertFalse($c['verified']);
    }

    public function test_missing_details_are_never_invented(): void
    {
        $c = SignalContact::build($this->row());
        $this->assertSame('not_available', $c['status']);
        $this->assertNull($c['email']);
        $this->assertNull($c['phone']);
        $this->assertStringContainsString('email address and phone number', $c['message']);
        $this->assertSame('Sunrise Public School', $c['company']);   // every other field still shown
        $this->assertSame('https://sunrise.example.org', $c['website']);
    }

    public function test_only_one_detail_is_shown_as_partial(): void
    {
        $c = SignalContact::build($this->row(['observed_event' => 'Mail principal@sunriseschool.in']));
        $this->assertSame('partial', $c['status']);
        $this->assertSame('principal@sunriseschool.in', $c['email']);
        $this->assertNull($c['phone']);
        $this->assertStringContainsString('phone number', $c['message']);
        $this->assertStringNotContainsString('email address', $c['message']);
    }

    public function test_a_crm_lead_wins_over_text_and_is_labelled_as_crm(): void
    {
        $lead = (object) ['lead_no' => 'L-7', 'first_name' => 'Asha', 'last_name' => 'Rao', 'designation' => 'Principal', 'email' => 'asha@sunriseschool.in',
            'mobile' => '9123456780', 'phone' => null, 'city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India', 'website' => null];
        $c = SignalContact::build($this->row(['observed_event' => 'info@other.in 9876543210']), $lead);
        $this->assertSame('Asha Rao', $c['name']);
        $this->assertSame('Principal', $c['designation']);
        $this->assertSame('asha@sunriseschool.in', $c['email']);
        $this->assertSame('+919123456780', $c['phone']);
        $this->assertSame('crm_record', $c['email_source']['status']);
        $this->assertSame(['city' => 'Pune', 'state' => 'Maharashtra', 'country' => 'India'], $c['location']);
    }

    public function test_an_invalid_crm_value_is_ignored_not_shown(): void
    {
        $lead = (object) ['lead_no' => 'L-8', 'first_name' => 'X', 'last_name' => '', 'email' => 'not-an-email', 'mobile' => '123', 'phone' => '45'];
        $c = SignalContact::build($this->row(), $lead);
        $this->assertNull($c['email']);
        $this->assertNull($c['phone']);
        $this->assertSame('not_available', $c['status']);
    }

    public function test_a_linkedin_profile_is_taken_only_from_the_evidence(): void
    {
        $c = SignalContact::build($this->row(['observed_event' => 'See https://www.linkedin.com/company/sunrise-school/ for details']));
        $this->assertSame('https://www.linkedin.com/company/sunrise-school/', $c['linkedin']);
        $this->assertNull(SignalContact::build($this->row())['linkedin']);
    }
}
