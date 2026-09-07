<?php

namespace App\Services\Events;

use App\Services\Events\Concerns\DrivesFromEventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REACTOR. Awards a badge when somebody finishes a course. (kind = R)
 *
 * ── WHY AWARDING HAD TO BECOME A WRITE ──────────────────────────────────────
 *
 * `SkillDevelopmentController::getUserAchievements()` recomputes every badge's
 * criteria on every request and, for any it finds satisfied, reports
 *
 *     $earnedDate = now()->format('d/m/Y');
 *
 * So the date a badge was earned is always TODAY, and a badge whose criterion
 * is a rolling window — "5 courses this month" is the one definition that
 * exists — vanishes the moment the window moves past it. A learner earns it in
 * January and by February it is gone, as though it had never happened.
 *
 * An achievement that time can take away is not an achievement. This records
 * the award once, when it is earned, with the event that caused it.
 *
 * ── WHY A REACTOR AND NOT A CHECK ON READ ───────────────────────────────────
 *
 * The read path cannot know WHEN a criterion first became true — only that it
 * is true now. `course.completed` carries that moment, and it now fires
 * (nothing emitted it until the completion work in the previous sprint). So the
 * badge is stamped at the point the fact occurred, not at the point somebody
 * happened to look.
 *
 * Idempotent three ways, because an award is a claim about a person:
 *   1. `g2g_event_delivery` — this event, this consumer, once.
 *   2. UNIQUE (sub_institute_id, user_id, achievement_id) on the award table.
 *   3. insertOrIgnore, so a race loses quietly rather than throwing.
 */
class AchievementAwarder
{
    use DrivesFromEventStore;

    public const CONSUMER = 'achievement_awarder';

    public const HANDLES = ['course.completed'];

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    public function dispatch(object $event): void
    {
        // FIRST LINE. A badge is a claim about a person that outlives this
        // system; a rebuild must never award one.
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (!$this->handles((string) $event->type)) {
            return;
        }

        $done = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($done) {
            return;
        }

        $tenant = (int) $event->sub_institute_id;
        $payload = $this->payload($event);
        $userId = (int) ($payload['user_id'] ?? 0);

        if ($userId <= 0) {
            $this->ledger($event, 'skipped', 'no user_id in the payload');

            return;
        }

        /*
         * Definitions available to this organisation.
         *
         * NULL sub_institute_id means "every organisation" — which is what the
         * one existing row is, since the column was only just added. A tenant
         * with its own badges gets those as well.
         */
        $definitions = DB::table('lms_achievements')
            ->where(function ($q) use ($tenant) {
                $q->whereNull('sub_institute_id')->orWhere('sub_institute_id', $tenant);
            })
            ->get();

        if ($definitions->isEmpty()) {
            $this->ledger($event, 'skipped', 'no achievement definitions');

            return;
        }

        $awarded = [];

        foreach ($definitions as $definition) {
            if (!$this->qualifies($definition, $userId, $tenant)) {
                continue;
            }

            $written = DB::table('lms_user_achievement')->insertOrIgnore([
                'sub_institute_id' => $tenant,
                'user_id' => $userId,
                'achievement_id' => (int) $definition->id,
                'earned_at' => now(),
                'source_event_id' => (int) $event->id,
                'note' => 'Awarded on course completion.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($written > 0) {
                $awarded[] = $definition->title;
            }
        }

        $this->ledger($event, 'done', null);

        if ($awarded !== []) {
            Log::channel('single')->info('achievement.awarded', [
                'event_id' => $event->id,
                'user_id' => $userId,
                'badges' => $awarded,
            ]);
        }
    }

    /**
     * Does this learner meet the definition's criterion right now?
     *
     * Deliberately the SAME criteria the read path evaluates, so a badge cannot
     * be awarded here that the progress display would call unearned. An unknown
     * criterion_type returns false rather than true: a badge nobody has defined
     * the rule for must not be handed out by default.
     */
    private function qualifies(object $definition, int $userId, int $tenant): bool
    {
        $target = (int) $definition->criteria_value;

        switch ($definition->criteria_type) {
            case 'courses_completed_month':
                return DB::table('lms_course_enroll')
                    ->where('user_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->where('status', 'completed')
                    ->whereNull('deleted_at')
                    ->whereRaw("DATE_FORMAT(end_date, '%Y-%m') = ?", [now()->format('Y-m')])
                    ->count() >= $target;

            case 'courses_completed_total':
                return DB::table('lms_course_enroll')
                    ->where('user_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->where('status', 'completed')
                    ->whereNull('deleted_at')
                    ->count() >= $target;

            case 'quiz_passed':
                return DB::table('lms_quiz_attempt')
                    ->where('user_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->where('passed', 1)
                    ->whereNull('deleted_at')
                    ->count() >= $target;

            case 'certificates_earned':
                return DB::table('lms_certificates')
                    ->where('user_id', $userId)
                    ->where('sub_institute_id', $tenant)
                    ->whereNull('deleted_at')
                    ->count() >= $target;

            default:
                return false;
        }
    }

    private function payload(object $event): array
    {
        $decoded = json_decode((string) ($event->payload ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ledger(object $event, string $status, ?string $error): void
    {
        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            [
                'status'       => $status,
                'attempts'     => DB::raw('attempts + 1'),
                'last_error'   => $error,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
