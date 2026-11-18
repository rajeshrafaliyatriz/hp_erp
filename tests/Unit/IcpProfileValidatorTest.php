<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpProfileValidator;
use Tests\TestCase;

class IcpProfileValidatorTest extends TestCase
{
    private IcpProfileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IcpProfileValidator();
    }

    private function validConfig(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Standard Corporate ICP',
            'industry_label' => 'Mid-size enterprises and educational institutions',
            'customer_model' => 'b2b',
            'pitch' => 'Complete digital management and workflow automation.',
            'guidance' => 'Target leadership roles and decision makers.',
            'buyer_types' => ['Director of Technology', 'Chief Information Officer'],
            'regions' => ['Gujarat', 'Maharashtra'],
        ], $overrides);
    }

    public function test_multiline_text_is_accepted_for_industry_label_and_guidance(): void
    {
        $multilineIndustry = "Line 1: Education Group\nLine 2: Higher Education\r\nLine 3: Multiple Campuses";
        $multilineGuidance = "Guidance note paragraph 1.\n\nGuidance note paragraph 2.\r\nGuidance note paragraph 3.";

        $problems = $this->validator->validate($this->validConfig([
            'industry_label' => $multilineIndustry,
            'guidance' => $multilineGuidance,
        ]));

        $this->assertSame([], $problems);
    }

    public function test_large_content_up_to_10k_chars_for_industry_label_and_5k_for_guidance(): void
    {
        $longIndustry = str_repeat("Detailed enterprise profile description. \n", 220); // ~9,460 chars
        $longGuidance = str_repeat("Detailed qualification and buyer persona guidance. \n", 95); // ~4,845 chars

        $this->assertGreaterThan(5000, mb_strlen($longIndustry));
        $this->assertLessThanOrEqual(10000, mb_strlen($longIndustry));

        $this->assertGreaterThan(1500, mb_strlen($longGuidance));
        $this->assertLessThanOrEqual(5000, mb_strlen($longGuidance));

        $problems = $this->validator->validate($this->validConfig([
            'industry_label' => $longIndustry,
            'guidance' => $longGuidance,
        ]));

        $this->assertSame([], $problems);
    }

    public function test_buyer_types_allows_up_to_200_chars_per_item(): void
    {
        $title200 = str_repeat('A', 190);
        $problems = $this->validator->validate($this->validConfig([
            'buyer_types' => [
                'Director of Information Technology & Digital Learning in Higher Education',
                $title200,
            ],
        ]));

        $this->assertSame([], $problems);
    }

    public function test_criteria_operators_and_validations(): void
    {
        $criteria = [
            ['attribute' => 'student_count', 'op' => '>=', 'value' => 500, 'label' => 'at least 500 students'],
            ['field' => 'annual_budget', 'op' => 'between', 'value' => [100000, 5000000], 'label' => 'budget between 100k and 5m'],
            ['attribute' => 'board', 'op' => 'in', 'value' => ['CBSE', 'ICSE', 'IB'], 'label' => 'recognized board'],
            ['attribute' => 'campus_type', 'op' => '=', 'value' => 'residential', 'label' => 'residential campus'],
            ['attribute' => 'region', 'op' => 'in_regions', 'label' => 'within priority states'],
        ];

        $problems = $this->validator->validate($this->validConfig([
            'must_have' => $criteria,
        ]));

        $this->assertSame([], $problems);
    }

    public function test_empty_value_in_numeric_criteria_returns_required(): void
    {
        $criteria = [
            ['attribute' => 'student_count', 'op' => '>=', 'value' => '', 'label' => 'min students'],
        ];

        $problems = $this->validator->validate($this->validConfig([
            'must_have' => $criteria,
        ]));

        $this->assertContains('must_have[0].value: required', $problems);
    }
}
