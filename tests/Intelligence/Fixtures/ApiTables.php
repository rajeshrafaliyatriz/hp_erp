<?php

namespace Tests\Intelligence\Fixtures;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\RightsChecker;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Support\DetectionClock;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fixtures for the service and API tests: the profile table the role lookup reads, plus stub detectors and an in-memory rights checker. Isolated SQLite only. */
final class ApiTables
{
    public static function createProfileTable(): void
    {
        Schema::create('tbluserprofilemaster', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('name')->nullable();
            $t->string('role_key', 64)->nullable();
            $t->softDeletes();
        });
    }

    public static function insight(string $key, array $override = []): Insight
    {
        $args = array_merge([
            'key' => $key, 'detector' => 'stub.one', 'domain' => 'hr', 'category' => 'attendance', 'severity' => 'attention', 'title' => 'Title ' . $key,
            'whatHappened' => 'What', 'whyItMatters' => 'Why', 'evidence' => [Evidence::metric('n', 3)], 'coverage' => Coverage::of(3, 5, 'basis', 1),
            'asOf' => '2026-11-21T00:00:00Z', 'suggestedAction' => 'Do', 'deepLink' => '/dashboard', 'confidence' => 'measured', 'audience' => 'organization',
            'surfaces' => ['hr_dashboard'], 'population' => null,
        ], $override);

        return new Insight(...$args);
    }

    /** @param list<Insight> $insights */
    public static function detector(string $id, string $domain, array $insights, ?Closure $authorizes = null, ?Closure $onDetect = null, ?Coverage $coverage = null, bool $insufficient = false): InsightDetector
    {
        return new class($id, $domain, $insights, $authorizes, $onDetect, $coverage, $insufficient) implements InsightDetector {
            public int $detectCalls = 0;

            public function __construct(private string $id, private string $domain, private array $insights, private ?Closure $authorizes, private ?Closure $onDetect, private ?Coverage $coverage, private bool $insufficient)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function title(): string
            {
                return 'Stub ' . $this->id;
            }

            public function domain(): string
            {
                return $this->domain;
            }

            public function surfaces(): array
            {
                return ['hr_dashboard'];
            }

            public function authorizes(IntelligenceCaller $caller): bool
            {
                return $this->authorizes === null ? true : (bool) ($this->authorizes)($caller);
            }

            public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
            {
                $this->detectCalls++;
                if ($this->onDetect !== null) {
                    ($this->onDetect)($caller);
                }

                $coverage = $this->coverage ?? Coverage::of(5, 5, 'stub', 1);

                return $this->insufficient
                    ? DetectorResult::insufficient($this, $coverage, $clock->asOf())
                    : DetectorResult::ok($this, $this->insights, $coverage, $clock->asOf());
            }
        };
    }

    public static function rights(array $granted = []): RightsChecker
    {
        return new class($granted) implements RightsChecker {
            public function __construct(private array $granted)
            {
            }

            public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
            {
                return in_array($accessLink, $this->granted, true);
            }
        };
    }
}
