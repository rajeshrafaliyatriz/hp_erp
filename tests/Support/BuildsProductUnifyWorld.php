<?php

namespace Tests\Support;

use App\Domain\ProductMaster\ProductMasterSync;
use App\Domain\ProductMaster\ProductPointers;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The world the pair review tests run on (slice 15, track P2-D), on in-memory sqlite.
 *
 * It is the world of tests/Support/BuildsCrmProductApiWorld.php (the real offer, master and signal migrations, the CRM catalogue with its item_type, SKU, category and
 * description, two organisations each with an administrator, a person without the right, and the shared built-in Products) with the 22 pointer tables of the registry
 * added to it (tests/Support/BuildsProductPointerWorld.php builds them from ProductPointers::all()), the real claims table (its unique key is what a merge must
 * respect) and the real decisions table of this track.
 *
 * `scholarWorld()` is the SHAPE of the dev data of tenant 6 (read on 13 Nov 2026): one Product, ScholarClone, with 7 Sub-products whose names, what they do and
 * modules are the real wording, and 9 catalogue items with their real name, category, SKU, price, type and description, two of them services and two of them
 * editions (both "School ERP"). `subProduct()` and `item()` make one more of either through the real sync, with any column changed, for the tests that need an exact score.
 *
 * The class that uses it defines nothing: T (1) and T2 (2) come from BuildsProductPointerWorld.
 */
trait BuildsProductUnifyWorld
{
    use BuildsCrmProductApiWorld;
    use BuildsProductPointerWorld;

    /** @var list<array<string, mixed>> what the audit trail was asked to record, in order */
    protected array $events = [];

    private int $unifySeq = 0;

    protected function buildUnifyWorld(): void
    {
        $this->buildCrmProductApiWorld();
        foreach (['2026_12_11_150100_create_g2g_product_claims_table', '2026_12_23_100000_create_g2g_product_match_decisions_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        // The two signal tables the API world builds from their real migrations have NOT NULL columns of their own; here they are plain pointer tables, like the other
        // eight, so a test can put a row in each of them holding a master id. The catalogue and the claims keep their real definitions.
        foreach (['g2g_company_opportunities', 'g2g_research_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (ProductPointers::all() as $entry) {
            $this->ensurePointerTable($entry);
        }

        // The audit trail is under test here (what is recorded and when), so the stand-in of the API world is replaced by one that remembers.
        $this->events = [];
        $test = $this;
        $this->app->instance(EventRecorder::class, new class($test) extends EventRecorder {
            public function __construct(private object $test) {}

            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                $this->test->recordEvent(['type' => $type, 'tenant' => $subInstituteId, 'entityType' => $entityType, 'entityId' => $entityId, 'actor' => $actorId, 'payload' => $payload, 'key' => $idempotencyKey]);

                return count($this->test->recordedEvents());
            }
        });
    }

    /** @param array<string, mixed> $event */
    public function recordEvent(array $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<array<string, mixed>> */
    public function recordedEvents(): array
    {
        return $this->events;
    }

    // ---- identities (what the controllers resolve from a token) ----

    /** @return array{user: object, user_id: int, sub_institute_id: int} an administrator of an organisation */
    protected function adminIdentity(int $tenant = self::T): array
    {
        $profile = $tenant === self::T ? 1 : 3;
        $id = (int) DB::table('tbluser')->insertGetId(['user_name' => 'admin' . ++$this->unifySeq, 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['user' => DB::table('tbluser')->where('id', $id)->first(), 'user_id' => $id, 'sub_institute_id' => $tenant];
    }

    // ---- rows ----

    /**
     * One Sub-product with a Portfolio offer, made through the real sync (so its master row is what production makes).
     *
     * @param array<string, mixed> $over columns of g2g_product_offers
     * @return array{master: int, offer: int}
     */
    protected function subProduct(string $code, string $name, array $over = [], int $tenant = self::T): array
    {
        $offer = (int) DB::table('g2g_product_offers')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'offer_id' => $code, 'name' => $name, 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype', 'deployment_model' => 'SaaS (multi-tenant)',
            'what_it_does' => null, 'modules_components' => null, 'typical_deal_band' => 'To fill', 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new ProductMasterSync)->offer($tenant, $offer, ProductMasterSync::CREATED);
        $master = (int) DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('offer_row_id', $offer)->value('id');
        $this->assertGreaterThan(0, $master, "the sync made a master row for offer {$code}");

        return ['master' => $master, 'offer' => $offer];
    }

    /**
     * One live catalogue item with its master row, made through the real sync; by default under ScholarClone.
     *
     * @param array<string, mixed> $over columns of crm_products
     * @return array{master: int, crm: int}
     */
    protected function item(string $name, array $over = [], int $tenant = self::T): array
    {
        $n = ++$this->unifySeq;
        $crm = (int) DB::table('crm_products')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'product_no' => 'PRD-W' . $n, 'item_type' => 'product', 'name' => $name, 'is_active' => 1, 'product_master_id' => $this->sharedId('scholarclone', $tenant),
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
        (new ProductMasterSync)->catalogueItem($tenant, $crm, ProductMasterSync::CREATED);
        $master = (int) DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('crm_product_id', $crm)->value('id');
        $this->assertGreaterThan(0, $master, "the sync made a master row for catalogue item {$name}");

        return ['master' => $master, 'crm' => $crm];
    }

    /**
     * The shape of the dev data of tenant 6: ScholarClone with 7 Sub-products and 9 catalogue items (two services, two editions), plus an Enterprise Brain Sub-product
     * (another Product, so nothing may be paired across Products). Every name, category, SKU, price and sentence is the real one.
     *
     * @return array{product: int, other: int, subs: array<string, array{master: int, offer: int}>, items: array<string, array{master: int, crm: int}>}
     */
    protected function scholarWorld(int $tenant = self::T): array
    {
        $subs = [];
        foreach ([
            ['SCH-01', 'Scholar School ERP', 'Complete school administration ERP', '285 menus across 25 module groups: Admission, Fees, Student, Result, Attendance, Transport, Library, Hostel, Inventory, HRMS, LMS, SQAA etc.'],
            ['SCH-02', 'Fees Intelligence & Decision Support', 'Role-based fee intelligence for CFO, Principal, Trustee etc.', 'Fees intelligence dashboard, 63-signal DSS, Fees Action Centre'],
            ['SCH-03', 'LMS-PAL (AI question generation)', 'AI-generated questions and assessments for school LMS', 'LMS + Pal Quiz semantic pipeline'],
            ['SCH-04', 'Career Intelligence & Counselling', 'Student career guidance grounded in competency data', 'KASBA + NCDG competency framework, IPIP Big Five'],
            ['SCH-05', 'Prompt-to-Video Lessons', 'Teacher types a prompt; pipeline produces a finished lesson video', 'Agent pipeline: prompt → lesson video'],
            ['SCH-06', 'Learning ESO (mastery execution)', 'Personalised mastery paths executed per learner', 'Runtime mastery layer on Concept Intelligence'],
            ['SCH-07', 'School FTS / Digital Office', 'Digital file movement and tracking for school administration', 'eOffice-style file tracking'],
        ] as [$code, $name, $what, $modules]) {
            $subs[$code] = $this->subProduct($code, $name, ['what_it_does' => $what, 'modules_components' => $modules], $tenant);
        }
        $subs['EB-01'] = $this->subProduct('EB-01', 'Enterprise Brain Intelligence Layer', ['parent_product' => 'EB', 'what_it_does' => 'Intelligence layer installed above a company\'s ERP/HR systems', 'modules_components' => '10-stage intelligence loop'], $tenant);

        $items = [];
        foreach ([
            'gujarat' => ['Scholar One ERP - Gujarat Edition', 'product', 'SCH-01-GUJ', 'School ERP', 120000, 'Comprehensive K-12 School ERP suite including student lifecycle, academics, administration, fee collection, staff payroll, and compliance reporting tailored for Gujarat educational standards.'],
            'core' => ['Scholar Core School Management', 'product', 'SCH-CORE-GUJ', 'School ERP', 75000, 'Core Student Information System (SIS) managing admissions, student demographics, enrollment, section allocations, and parent portal communications.'],
            'fees' => ['Scholar CCB Fee Collection System', 'product', 'SCH-CCB-GUJ', 'Fees Intelligence', 35000, 'End-to-end fee management, fee structure customization, online payment gateway reconciliation, automated overdue SMS reminders, and real-time defaulter tracking.'],
            'lms' => ['Scholar LMS & Digital Classroom', 'product', 'SCH-LMS-GUJ', 'Digital Learning', 45000, 'Interactive Learning Management System supporting digital content distribution, assignment submissions, recorded video lectures, and live interactive classes.'],
            'attendance' => ['Scholar Smart Attendance & Biometric Access', 'product', 'SCH-ATT-GUJ', 'Student Safety', 30000, 'Automated RFID and biometric student and staff attendance system with real-time push alerts, leave workflows, and Gujarat Shiksha Setu compliance integration.'],
            'exam' => ['Scholar Examination & CCE Report Engine', 'product', 'SCH-EXAM-GUJ', 'Academic Evaluation', 40000, 'Comprehensive examination scheduler, hall ticket generator, continuous comprehensive evaluation (CCE) grading, mark entry, and dynamic report card publishing.'],
            'fleet' => ['Scholar Fleet GPS Bus Tracking System', 'product', 'SCH-BUS-GUJ', 'Transport Management', 50000, 'Real-time IoT bus tracking, route planning, speed alerts, geo-fenced safe pickup/drop notifications, and emergency SOS alerts for parents and transport managers.'],
            'implementation' => ['ScholarClone On-Premise & Cloud Implementation', 'service', 'SCH-SRV-IMP', 'Implementation', 25000, 'Professional turnkey onboarding service: database provisioning, legacy data cleaning & migration, institutional hierarchy configuration, and administrator hand-holding.'],
            'training' => ['ScholarClone Faculty & Staff Certification Training', 'service', 'SCH-SRV-TRN', 'Training', 15000, 'Dedicated on-site and remote training workshops for teaching staff, accountants, and administrators with official ScholarClone system proficiency certification.'],
        ] as $key => [$name, $type, $sku, $category, $price, $description]) {
            $items[$key] = $this->item($name, ['item_type' => $type, 'sku' => $sku, 'category' => $category, 'unit_price' => $price, 'description' => $description], $tenant);
        }

        return ['product' => $this->sharedId('scholarclone', $tenant), 'other' => $this->sharedId('enterprise_brain', $tenant), 'subs' => $subs, 'items' => $items];
    }

    // ---- the references a merge must carry ----

    /**
     * One row in EVERY table that points at a master row (the 10 columns of ProductPointers::toMaster(), except the Product pointer of crm_products), each holding $masterId.
     *
     * @return array<string, int> "table.column" => the id of the row made
     */
    protected function pointAtMaster(int $masterId, int $tenant = self::T, string $claim = 'Cuts paperwork by 60%'): array
    {
        $made = [];
        foreach (ProductPointers::toMaster() as $e) {
            if ($e['table'] === ProductPointers::CRM_PRODUCTS) {
                continue;
            }
            $key = $e['table'] . '.' . $e['column'];
            $made[$key] = $e['table'] === 'g2g_product_claims'
                ? $this->claim($masterId, $claim, 'proposed', $tenant)
                : (int) DB::table($e['table'])->insertGetId([$e['column'] => $masterId, $e['scope'] => $tenant, 'updated_at' => self::STAMP]);
        }

        return $made;
    }

    protected function claim(int $masterId, string $sentence, string $status = 'proposed', int $tenant = self::T): int
    {
        return (int) DB::table('g2g_product_claims')->insertGetId([
            'sub_institute_id' => $tenant, 'product_master_id' => $masterId, 'claim' => $sentence, 'claim_hash' => sha1(mb_strtolower(trim($sentence))), 'status' => $status,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ]);
    }

    /**
     * One line in EVERY table that points at a catalogue item (the 12 columns of ProductPointers::toCrmProduct()), each holding $crmId, with a quantity and a total
     * where the table has them, so the census has something to count and sum.
     *
     * @return array<string, int> "table.column" => the id of the row made
     */
    protected function pointAtCrm(int $crmId, int $tenant = self::T, float $quantity = 2.0, float $total = 100.0): array
    {
        $made = [];
        foreach (ProductPointers::toCrmProduct() as $e) {
            $over = [];
            foreach ($e['measures'] as $measure) {
                $over[$measure] = $measure === 'quantity' ? $quantity : $total;
            }
            $made[$e['table'] . '.' . $e['column']] = $this->pointRow($e, $tenant, $crmId, $over);
        }

        return $made;
    }
}
