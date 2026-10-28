<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\EvidenceGrader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Primary-source decision: explainable, label-based, conservative. Pure (config only). */
class EvidenceGraderPrimarySourceTest extends TestCase
{
    private function reason(string $url, ?string $name, ?string $website = null): ?string
    {
        return (new EvidenceGrader())->primaryReason($url, ['name' => $name, 'website' => $website]);
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}> */
    public static function cases(): array
    {
        $podar = 'Podar World School';
        $dps = 'Delhi Public School';

        return [
            'own site' => ['https://podarworld.com/about', $podar, 'https://www.podarworld.com', 'buyer_website'],
            'own site subdomain' => ['https://careers.podarworld.com/jobs', $podar, 'podarworld.com', 'buyer_website'],
            'own site uppercase port trailing dot' => ['HTTPS://Careers.PodarWorld.COM.:8443/x', $podar, 'https://podarworld.com', 'buyer_website'],
            'own site userinfo' => ['https://user:pw@www.podarworld.com/', $podar, 'podarworld.com', 'buyer_website'],
            'own website host is shared hosting' => ['https://school.blogspot.com/p', 'X', 'school.blogspot.com', 'buyer_website'],
            'name on .ac.in' => ['https://podarworld.ac.in/tender', $podar, null, 'buyer_name_on_official_domain'],
            'name on .ac.in hyphen' => ['https://podar-world.ac.in/', $podar, null, 'buyer_name_on_official_domain'],
            'name on .ac.in digit split' => ['https://podar2024.ac.in/', $podar, null, 'buyer_name_on_official_domain'],
            'name on subdomain of own .edu' => ['https://admissions.stanford.edu/', 'Stanford University', null, 'buyer_name_on_official_domain'],
            'name on gov.in' => ['https://cbse.gov.in/notice', 'CBSE Board', null, 'buyer_name_on_official_domain'],
            'name on own com' => ['https://careers.acmeindustries.com/', 'Acme Industries', null, 'buyer_name_on_own_domain'],
            'name on own co.in' => ['https://acme.co.in/', 'Acme Industries', null, 'buyer_name_on_own_domain'],
            'concatenated generic run' => ['https://delhipublicschool.com/', $dps, null, 'buyer_name_on_own_domain'],
            'unrelated .edu' => ['https://www.mit.edu/x', $podar, null, null],
            'unrelated .gov' => ['https://www.nasa.gov/x', $podar, null, null],
            'unrelated gov.in' => ['https://education.punjab.gov.in/tender/12', 'Acme Industries', null, null],
            'unrelated ac.in' => ['https://iitd.ac.in/', $podar, null, null],
            'prefix lookalike' => ['https://xpodar.ac.in/', $podar, null, null],
            'suffix lookalike' => ['https://podarx.ac.in/', $podar, null, null],
            'name as subdomain of evil' => ['https://podar.evil.com/', $podar, null, null],
            'name in path' => ['https://evil.com/podar', $podar, null, null],
            'official suffix then evil' => ['https://podar.ac.in.evil.com/', $podar, null, null],
            'evil with edu inside' => ['https://podar.evil.com.edu.attacker.in/', $podar, null, null],
            'name under someone else official domain subdomain' => ['https://podar.mit.edu/', $podar, null, null],
            'substring only' => ['https://delhiblog.com/', $dps, null, null],
            'substring only 2' => ['https://notdelhi.com/', $dps, null, null],
            'generic only name' => ['https://publicschool.ac.in/', 'Public School', null, null],
            'generic only name on own' => ['https://school.com/', 'The International School', null, null],
            'empty name' => ['https://podarworld.ac.in/', '', null, null],
            'null name' => ['https://podarworld.com/', null, null, null],
            'short token only' => ['https://abc.com/', 'ABC School', null, null],
            'shared hosting blogspot' => ['https://podar.blogspot.com/', $podar, null, null],
            'shared hosting wordpress' => ['https://podarworld.wordpress.com/', $podar, null, null],
            'shared hosting not own' => ['https://x.wordpress.com/', $podar, 'podarworld.com', null],
            'sibling of own website' => ['https://podarworld.com.evil.com/', $podar, 'podarworld.com', null],
            'empty url' => ['', $podar, 'podarworld.com', null],
            'malformed scheme' => ['http:///podar', $podar, null, null],
            'malformed spaces' => ['https://podar world.ac.in/', $podar, null, null],
            'malformed underscores host' => ['https://-podarworld-.ac.in/', $podar, null, null],
            'ip address' => ['http://192.168.1.1/', $podar, null, null],
            'bare word' => ['podar', $podar, null, null],
            'garbage' => ['::::', $podar, null, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_primary_reason(string $url, ?string $name, ?string $website, ?string $expected): void
    {
        $this->assertSame($expected, $this->reason($url, $name, $website));
        $this->assertSame($expected !== null, (new EvidenceGrader())->isPrimary($url, ['name' => $name, 'website' => $website]));
    }

    public function test_grade_uses_the_stricter_rule(): void
    {
        $g = new EvidenceGrader();
        $buyer = ['name' => 'Podar World School', 'website' => null];
        $ok = $g->grade(['quote_verified' => true], 'page_text', 'https://podarworld.ac.in/t', $buyer);
        $this->assertSame('confirmed', $ok['claim_level']);
        $no = $g->grade(['quote_verified' => true], 'page_text', 'https://www.mit.edu/t', $buyer);
        $this->assertFalse($no['primary']);
        $this->assertSame('hypothesis', $no['claim_level']);
    }
}
