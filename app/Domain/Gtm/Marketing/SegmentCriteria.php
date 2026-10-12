<?php

namespace App\Domain\Gtm\Marketing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A segment is a saved filter. This class is the only place its criteria are interpreted, and it does
 * two jobs: reject anything that is not a known, well-typed key (a criteria blob is user input that
 * ends up in a query, so it is whitelisted, never passed through) and turn valid criteria into a
 * tenant-scoped query. Matching is re-evaluated on every read, so a segment never goes stale.
 */
final class SegmentCriteria
{
    public const ACCOUNT_STAGES = ['target', 'engaged', 'opportunity', 'customer', 'churned', 'disqualified'];
    public const CONTACT_ROLES = ['champion', 'economic_buyer', 'decision_maker', 'influencer', 'user', 'blocker'];
    public const CONTACT_STATUSES = ['active', 'bounced', 'unsubscribed', 'do_not_contact'];
    public const SOURCES = ['manual', 'research', 'import'];
    public const PRIORITIES = ['High', 'Medium', 'Low'];

    private const ACCOUNT_KEYS = ['stages', 'industry_contains', 'location_contains', 'min_icp', 'max_icp', 'has_contacts', 'min_signal_priority', 'sources', 'owner_user_id', 'not_in_campaign_id'];
    private const CONTACT_KEYS = ['roles', 'statuses', 'has_email', 'account_stages', 'industry_contains', 'not_in_campaign_id'];

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{criteria: array<string, mixed>, errors: list<string>}
     */
    public static function validate(string $kind, array $criteria): array
    {
        $errors = [];
        $allowed = $kind === 'contact' ? self::CONTACT_KEYS : self::ACCOUNT_KEYS;
        $out = [];

        foreach ($criteria as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = "'{$key}' is not a filter a {$kind} segment supports.";

                continue;
            }
            switch ($key) {
                case 'stages':
                case 'account_stages':
                    self::enumList($key, $value, self::ACCOUNT_STAGES, $out, $errors);
                    break;
                case 'roles':
                    self::enumList($key, $value, self::CONTACT_ROLES, $out, $errors);
                    break;
                case 'statuses':
                    self::enumList($key, $value, self::CONTACT_STATUSES, $out, $errors);
                    break;
                case 'sources':
                    self::enumList($key, $value, self::SOURCES, $out, $errors);
                    break;
                case 'industry_contains':
                case 'location_contains':
                    if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
                        $errors[] = "'{$key}' must be text of 1-100 characters.";
                    } else {
                        $out[$key] = trim($value);
                    }
                    break;
                case 'min_icp':
                case 'max_icp':
                    if (! is_numeric($value) || $value < 0 || $value > 100) {
                        $errors[] = "'{$key}' must be a number from 0 to 100.";
                    } else {
                        $out[$key] = (int) $value;
                    }
                    break;
                case 'has_contacts':
                case 'has_email':
                    if (! is_bool($value)) {
                        $errors[] = "'{$key}' must be true or false.";
                    } else {
                        $out[$key] = $value;
                    }
                    break;
                case 'min_signal_priority':
                    if (! in_array($value, self::PRIORITIES, true)) {
                        $errors[] = "'min_signal_priority' must be High, Medium or Low.";
                    } else {
                        $out[$key] = $value;
                    }
                    break;
                case 'owner_user_id':
                case 'not_in_campaign_id':
                    if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                        $errors[] = "'{$key}' must be an id.";
                    } else {
                        $out[$key] = (int) $value;
                    }
                    break;
            }
        }
        if (isset($out['min_icp'], $out['max_icp']) && $out['min_icp'] > $out['max_icp']) {
            $errors[] = 'min_icp cannot be greater than max_icp.';
        }
        if ($out === [] && $errors === []) {
            $errors[] = 'Add at least one filter. A segment with none would match everything.';
        }

        return ['criteria' => $out, 'errors' => $errors];
    }

    /** Accounts matching the criteria, in this tenant only. @param array<string, mixed> $c */
    public static function accounts(int $tenant, array $c): Builder
    {
        $q = DB::table('gtm_accounts as a')->where('a.sub_institute_id', $tenant)->whereNull('a.deleted_at');
        if (! empty($c['stages'])) {
            $q->whereIn('a.stage', $c['stages']);
        }
        if (! empty($c['sources'])) {
            $q->whereIn('a.source', $c['sources']);
        }
        foreach (['industry_contains' => 'a.industry', 'location_contains' => 'a.location'] as $key => $col) {
            if (! empty($c[$key])) {
                $q->where($col, 'like', self::like($c[$key]));
            }
        }
        if (isset($c['min_icp'])) {
            $q->where('a.icp_fit_score', '>=', $c['min_icp']);
        }
        if (isset($c['max_icp'])) {
            $q->where('a.icp_fit_score', '<=', $c['max_icp']);
        }
        if (! empty($c['owner_user_id'])) {
            $q->where('a.owner_user_id', $c['owner_user_id']);
        }
        if (array_key_exists('has_contacts', $c)) {
            $exists = fn ($s) => $s->select(DB::raw(1))->from('gtm_contacts as c')->whereColumn('c.account_id', 'a.id')->where('c.sub_institute_id', $tenant)->whereNull('c.deleted_at');
            $c['has_contacts'] ? $q->whereExists($exists) : $q->whereNotExists($exists);
        }
        if (! empty($c['min_signal_priority'])) {
            $levels = array_slice(self::PRIORITIES, 0, array_search($c['min_signal_priority'], self::PRIORITIES, true) + 1);
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('g2g_company_opportunities as o')->whereColumn('o.company_id', 'a.company_id')
                ->where('o.sub_institute_id', $tenant)->where('o.review_status', '!=', 'Dismissed')->whereIn('o.priority', $levels));
        }
        if (! empty($c['not_in_campaign_id'])) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('gtm_campaign_members as m')->whereColumn('m.account_id', 'a.id')
                ->where('m.sub_institute_id', $tenant)->where('m.campaign_id', $c['not_in_campaign_id']));
        }

        return $q;
    }

    /** Contacts matching the criteria, in this tenant only. @param array<string, mixed> $c */
    public static function contacts(int $tenant, array $c): Builder
    {
        $q = DB::table('gtm_contacts as c')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'c.account_id')->where('a.sub_institute_id', '=', $tenant)->whereNull('a.deleted_at'))
            ->where('c.sub_institute_id', $tenant)->whereNull('c.deleted_at');
        $q->whereIn('c.status', ! empty($c['statuses']) ? $c['statuses'] : ['active']);
        if (! empty($c['roles'])) {
            $q->whereIn('c.role_in_deal', $c['roles']);
        }
        if (! empty($c['account_stages'])) {
            $q->whereIn('a.stage', $c['account_stages']);
        }
        if (! empty($c['industry_contains'])) {
            $q->where('a.industry', 'like', self::like($c['industry_contains']));
        }
        if (! empty($c['has_email'])) {
            $q->whereNotNull('c.email')->where('c.email', '!=', '');
        }
        if (! empty($c['not_in_campaign_id'])) {
            $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('gtm_campaign_members as m')->whereColumn('m.contact_id', 'c.id')
                ->where('m.sub_institute_id', $tenant)->where('m.campaign_id', $c['not_in_campaign_id']));
        }

        return $q;
    }

    /** @param list<string> $allowed @param array<string, mixed> $out @param list<string> $errors */
    private static function enumList(string $key, mixed $value, array $allowed, array &$out, array &$errors): void
    {
        if (! is_array($value) || $value === [] || array_diff($value, $allowed) !== []) {
            $errors[] = "'{$key}' must be a non-empty list drawn from: ".implode(', ', $allowed).'.';

            return;
        }
        $out[$key] = array_values(array_unique($value));
    }

    private static function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
