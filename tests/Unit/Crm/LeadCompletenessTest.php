<?php

namespace Tests\Unit\Crm;

use App\Domain\Crm\Leads\LeadCompleteness;
use PHPUnit\Framework\TestCase;

/** What counts as a lead that can be contacted properly: one definition shared by the workflow facts and the "details completed" event. */
class LeadCompletenessTest extends TestCase
{
    private function lead(array $o = []): array
    {
        return $o + ['first_name' => 'Asha', 'last_name' => 'Rao', 'company' => 'Acme Co', 'email' => 'asha@acme.test', 'phone' => '+910000', 'mobile' => null];
    }

    public function test_a_complete_lead_misses_nothing(): void
    {
        $this->assertSame([], LeadCompleteness::missing($this->lead()));
        $this->assertTrue(LeadCompleteness::hasRealName($this->lead()));
    }

    public function test_a_signal_promoted_lead_has_no_real_name_email_or_phone(): void
    {
        $promoted = ['first_name' => null, 'last_name' => 'Acme Co', 'company' => 'Acme Co', 'email' => null, 'phone' => null, 'mobile' => null];
        $this->assertSame(['a real contact name', 'an email address', 'a phone number'], LeadCompleteness::missing($promoted));
        $this->assertFalse(LeadCompleteness::hasRealName($promoted));
    }

    public function test_a_last_name_equal_to_the_company_is_not_a_real_name_even_with_a_first_name_and_any_case(): void
    {
        $this->assertContains('a real contact name', LeadCompleteness::missing($this->lead(['last_name' => ' ACME co ', 'first_name' => 'X'])));
    }

    public function test_a_mobile_number_is_enough_instead_of_a_phone(): void
    {
        $this->assertSame([], LeadCompleteness::missing($this->lead(['phone' => '', 'mobile' => '+911'])));
        $this->assertSame(['a phone number'], LeadCompleteness::missing($this->lead(['phone' => ' ', 'mobile' => ''])));
    }

    public function test_a_missing_company_is_reported_and_objects_work_too(): void
    {
        $this->assertSame(['a company'], LeadCompleteness::missing((object) $this->lead(['company' => ''])));
    }
}
