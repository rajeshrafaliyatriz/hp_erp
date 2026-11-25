<?php

namespace Tests\Feature;

use App\Domain\Signals\Opportunities\SignalContact;
use Tests\TestCase;

class SignalContactOwnDomainTest extends TestCase
{
    private function row(array $o = []): object
    {
        return (object) ($o + ['company_name' => 'QBIZ HR', 'company_website' => null, 'company_location' => null, 'observed_event' => '', 'source_url' => 'https://news.test/a']);
    }

    public function test_the_companys_own_domain_is_preferred_and_our_own_addresses_are_never_shown(): void
    {
        config(['signals_connectors.own_contact_domains' => 'scholarclone.com']);
        $row = $this->row(['company_website' => 'https://www.qbizhr.in', 'observed_event' => 'Write to hello@scholarclone.com or gmail person x@gmail.com or info@qbizhr.in']);
        $this->assertSame('info@qbizhr.in', SignalContact::build($row)['email']);
        $only = $this->row(['observed_event' => 'Write to hello@scholarclone.com']);
        $this->assertNull(SignalContact::build($only)['email']);
    }
}
