<?php

namespace Tests\Unit\Intelligence\Platform;

use App\Domain\Intelligence\Detectors\Platform\GovernanceDetector;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Support\Facades\Schema;

class GovernanceDetectorTest extends PlatformTestCase
{
    private const LINKS = ['/ai/policies'];

    private function detector(): GovernanceDetector
    {
        return new GovernanceDetector($this->db(), $this->thresholds());
    }

    private function admin(int $tenant = 1, string $role = 'administrator')
    {
        return $this->platformCaller($role, self::LINKS, $tenant);
    }

    public function test_active_policies_assigned_to_nothing_are_counted_and_acknowledgements_are_not_invented(): void
    {
        $assigned = $this->addPolicy(1);
        $this->assignPolicy($assigned, 1);
        $orgWide = $this->addPolicy(1);
        $this->assignPolicy($orgWide, null);                    // an assignment with no organisation still governs
        $loose1 = $this->addPolicy(1, 1, 1);
        $loose2 = $this->addPolicy(1);
        $inactiveAssign = $this->addPolicy(1);
        $this->assignPolicy($inactiveAssign, 1, 0);             // inactive assignment: governs nothing
        $this->addPolicy(1, 0);                                 // an inactive policy is not assessed
        $this->addPolicy(null);                                 // platform default: not this organisation's to assess
        $foreign = $this->addPolicy(92);                        // another tenant
        $this->assignPolicy($loose2, 92);                       // another tenant's assignment does not govern THIS tenant's policy
        $this->db()->table('ai_policy_acknowledgements')->insert(['policy_id' => $loose1, 'user_id' => 55, 'sub_institute_id' => 1, 'acknowledged' => 1]);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertCount(1, $result->insights);
        $insight = $result->insights[0];
        $this->assertSame('attention', $insight->severity);
        $ev = $this->evidenceByLabel($insight);
        $this->assertSame(5, $ev['Active AI policies']['value']);
        $this->assertSame(3, $ev['Active policies assigned to nothing']['value']);
        $this->assertSame(1, $ev['Active policies that ask for acknowledgement']['value']);
        $this->assertStringContainsString('not recorded', $ev['Policy acknowledgements']['value']);
        $ids = array_column(array_filter($insight->toArray()['evidence'], fn ($e) => $e['kind'] === 'entity'), 'entity_id');
        sort($ids);
        $this->assertSame([$loose1, $loose2, $inactiveAssign], $ids);
        $this->assertNotContains($foreign, $ids);
        $this->assertSame('/ai/policies', $insight->deepLink);
        $this->assertStringNotContainsString('55', json_encode($insight->toArray()['evidence']), 'no individual acknowledger is named');
    }

    public function test_the_acknowledgement_table_is_never_read(): void
    {
        $this->addPolicy(1);

        $sql = strtolower(implode("\n", $this->sqlOf(fn () => $this->detector()->detect($this->admin(), $this->clock()))));

        $this->assertStringNotContainsString('ai_policy_acknowledgements', $sql);
        $this->assertStringNotContainsString('user_id', $sql);
    }

    public function test_every_policy_assigned_is_ok_and_empty(): void
    {
        $this->assignPolicy($this->addPolicy(1), 1);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_OK, $result->state);
        $this->assertSame([], $result->insights);
        $this->assertSame(1, $result->coverage->observed);
    }

    public function test_no_policies_is_insufficient_not_a_clean_bill(): void
    {
        $this->addPolicy(92);
        $this->addPolicy(null);

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_INSUFFICIENT, $result->state);
        $this->assertSame(0, $result->coverage->observed);
    }

    public function test_executive_and_auditor_get_no_record_references(): void
    {
        $this->addPolicy(1);

        foreach (['executive', 'auditor'] as $role) {
            $result = $this->detector()->detect($this->admin(1, $role), $this->clock());
            $this->assertCount(1, $result->insights, $role);
            $this->assertFalse($this->hasEntityEvidence($result->insights[0]), $role);
        }
    }

    public function test_missing_tables_are_data_unavailable(): void
    {
        Schema::drop('ai_policy_assignments');

        $result = $this->detector()->detect($this->admin(), $this->clock());

        $this->assertSame(Taxonomy::STATE_DATA_UNAVAILABLE, $result->state);
        $this->assertNull($result->summary()['insight_count']);
    }

    public function test_authorization_needs_the_policies_right(): void
    {
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator')));
        $this->assertFalse($this->detector()->authorizes($this->platformCaller('administrator', ['/ai/usage-cost'])));
        $this->assertTrue($this->detector()->authorizes($this->platformCaller('employee', self::LINKS)));
    }
}
