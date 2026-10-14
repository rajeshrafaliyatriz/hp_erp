<?php

namespace App\Console\Commands;

use App\Domain\Signals\Scholar\IdentityResolver;
use App\Domain\Signals\Support\HtmlText;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Checks, safely and read-only, whether the website a CSV gives for each school is real and is that school.
 *
 *   php artisan signals:verify-websites schools.csv --crm=LEA35,LEA59 [--report=path.json]
 *
 * The CSV needs the columns crm_ref, name, city, website (extra columns are ignored). NOTHING is written to the CRM, to
 * g2g_target_accounts or to any other table: the result is printed and saved as a JSON report. A correction is never proposed from a
 * guess: when a site redirects, the final address is reported as evidence, and that is all.
 *
 * Every request goes through SafeUrlFetcher::validate (public addresses only, ports 80/443, the resolved IP pinned, each redirect
 * re-checked), with short timeouts, one request at a time and a body cap.
 *
 * Verdicts
 *   verified            the page loads and every distinguishing word of the school's name is on it
 *   resolves_unconfirmed  the page loads but the school's name is not on it (a parked page, a group site, or someone else's)
 *   blocked             the server refused automated access (401/403/406/429, or a bot wall): says nothing about the domain
 *   temporary_failure   timeout, connection refused/reset or a 5xx: try again later, not evidence the domain is wrong

 *   verified_group      the page names part of the school and the CRM contact's email is on the same (non-free) domain: the site is the organisation's
 *   verified_group_tls_warning  as verified_group, with the certificate not verifiable from this machine
 *   verified_tls_warning  as verified, but the certificate could not be verified from this machine (the site, or this machine CA bundle)
 *   tls_error           the certificate is invalid and the page could not be read either
 *   invalid_domain      the name does not resolve, confirmed twice and against a control domain, so it is not our resolver failing
 */
class VerifyAccountWebsites extends Command
{
    protected $signature = 'signals:verify-websites {file : CSV with crm_ref,name,city,website}
        {--crm= : comma-separated crm_ref values to check (default: every row, max --limit)}
        {--limit=10 : most rows to check}
        {--record : save each result on the target account (attributes.website_verification) so research can use a VERIFIED site; needs --tenant}
        {--tenant= : sub_institute_id the accounts belong to (with --record)}
        {--report= : where to save the JSON report (default storage/app)}';

    protected $description = 'Check the websites listed for target schools. Read-only unless --record, which saves only attributes.website_verification on the target account (never the CRM, never the website column).';

    private const BLOCK_STATUSES = [401, 403, 406, 429, 451];

    public function handle(SafeUrlFetcher $fetcher): int
    {
        $rows = $this->readCsv((string) $this->argument('file'));
        if ($rows === null) {
            $this->error('Could not read the file.');

            return self::FAILURE;
        }
        if ($this->option('crm')) {
            $want = array_map('trim', explode(',', (string) $this->option('crm')));
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['crm_ref'] ?? '', $want, true)));
        }
        $rows = array_slice($rows, 0, max(1, (int) $this->option('limit')));

        $report = [];
        foreach ($rows as $row) {
            $r = $this->check($fetcher, $row);
            $report[] = $r;
            $this->line(sprintf('%-9s %-42s %-22s %s', $r['crm_ref'], mb_substr($r['name'], 0, 41), $r['verdict'], $r['detail']));
        }

        if ($this->option('record')) {
            $this->record((int) $this->option('tenant'), $report);
        }

        $path = (string) ($this->option('report') ?: storage_path('app/website_verification_' . now()->format('Ymd_His') . '.json'));
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode(['checked_at' => now()->toIso8601String(), 'results' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Report saved: {$path}");
        $this->line(json_encode(array_count_values(array_column($report, 'verdict'))));

        return self::SUCCESS;
    }

    /**
     * Save what was observed on the target account, and nothing else: `attributes.website_verification`. The CRM, the account's own
     * `website` column and every other fact are untouched. `scope` is "campus" only when the page names the school AND its city; a page
     * that names the school but not the city proves the organisation or group, not the branch ("group").
     *
     * @param  list<array<string, mixed>>  $report
     */
    private function record(int $tenant, array $report): void
    {
        if ($tenant <= 0) {
            $this->error('--record needs --tenant.');

            return;
        }
        foreach ($report as $r) {
            $account = \App\Domain\Signals\Icp\TargetAccount::where('sub_institute_id', $tenant)->where('crm_ref', $r['crm_ref'])->first();
            if ($account === null) {
                $this->warn("{$r['crm_ref']}: no target account in tenant {$tenant}; not recorded.");

                continue;
            }
            $attr = (array) ($account->attributes ?? []);
            $attr['website_verification'] = [
                'given_url' => $r['given_website'], 'final_url' => $r['final_url'], 'verdict' => $r['verdict'], 'detail' => $r['detail'],
                'scope' => (($r['city_on_page'] ?? null) === true && str_starts_with((string) $r['verdict'], 'verified') && ! str_starts_with((string) $r['verdict'], 'verified_group')) ? 'campus' : 'group', 'name_words_found' => $r['name_words_found'] ?? null,
                'city_on_page' => $r['city_on_page'] ?? null, 'tls_verified' => $r['tls_verified'] ?? null, 'checked_at' => now()->toIso8601String(),
            ];
            $account->forceFill(['attributes' => $attr])->save();
            $this->line("{$r['crm_ref']}: recorded ({$r['verdict']}).");
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function check(SafeUrlFetcher $fetcher, array $row): array
    {
        $out = ['crm_email' => null, 'crm_ref' => $row['crm_ref'] ?? '', 'name' => $row['name'] ?? '', 'city' => $row['city'] ?? '', 'given_website' => $row['website'] ?? '',
            'verdict' => 'temporary_failure', 'detail' => '', 'final_url' => null, 'http_status' => null, 'title' => null, 'name_words_found' => null, 'city_on_page' => null];

        $out['crm_email'] = ($row['email'] ?? '') !== '' ? $this->emailDomain((string) $row['email']) : null;   // only the DOMAIN is kept
        $url = trim((string) ($row['website'] ?? ''));
        if ($url === '') {
            return ['verdict' => 'no_website_given', 'detail' => 'The CSV has no website for this school.'] + $out;
        }
        $url = str_contains($url, '://') ? $url : 'https://' . $url;

        // DNS first, so "this name does not exist" is told apart from "the site is slow".
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $this->resolves($host)) {
            return ['verdict' => $this->dnsFailureIsConfirmed($host) ? 'invalid_domain' : 'temporary_failure',
                'detail' => $this->dnsFailureIsConfirmed($host) ? "{$host} does not resolve (checked twice, and a control domain resolves)." : 'DNS did not answer; the resolver may be failing. Try again.'] + $out;
        }

        $probe = $this->probe($fetcher, $url);
        $out['http_status'] = $probe['status'];
        $out['final_url'] = $probe['final_url'];
        if ($probe['error'] !== null) {
            return ['verdict' => $probe['error'], 'detail' => $probe['message']] + $out;
        }
        if (in_array($probe['status'], self::BLOCK_STATUSES, true)) {
            return ['verdict' => 'blocked', 'detail' => "The server answered HTTP {$probe['status']} to an automated request: this says nothing about whether the domain is right."] + $out;
        }
        if ($probe['status'] >= 500) {
            return ['verdict' => 'temporary_failure', 'detail' => "The server answered HTTP {$probe['status']}."] + $out;
        }
        if ($probe['status'] < 200 || $probe['status'] >= 400) {
            return ['verdict' => 'resolves_unconfirmed', 'detail' => "HTTP {$probe['status']}: no page to read."] + $out;
        }

        $page = HtmlText::extract($probe['body']);
        $out['title'] = $page['title'] ? mb_substr($page['title'], 0, 120) : null;
        $haystack = mb_strtolower(($page['title'] ?? '') . ' ' . mb_substr($page['text'], 0, 20000));
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $haystack) ?? $haystack;

        $words = IdentityResolver::tokens((string) $out['name']);
        $found = array_values(array_filter($words, fn ($w) => str_contains($haystack, $w) || str_contains($compact, $w)));
        $out['name_words_found'] = count($found) . '/' . count($words);
        $city = mb_strtolower(trim((string) $out['city']));
        $out['city_on_page'] = $city !== '' ? str_contains($haystack, $city) : null;

        $redirectNote = $probe['final_url'] && parse_url($probe['final_url'], PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST) ? ' Redirected to ' . $probe['final_url'] . '.' : '';

        $tlsNote = $probe['tls_unverified'] ? ' The TLS certificate could NOT be verified from this machine.' : '';
        $out['tls_verified'] = ! $probe['tls_unverified'];
        if ($words !== [] && count($found) === count($words)) {
            $scope = $out['city_on_page'] === false ? ' The city is not on the page: this confirms the organisation or group, not necessarily this branch.' : '';

            return ['verdict' => $probe['tls_unverified'] ? 'verified_tls_warning' : 'verified', 'detail' => 'Page loads; every distinguishing word of the name is on it' . ($out['city_on_page'] ? ' and so is the city' : '') . '.' . $scope . $redirectNote . $tlsNote] + $out;
        }

        // The name is only partly on the page (typically a group home page that does not name this branch), but the CRM contact's own email
        // is on the SAME domain (not a free mail provider): independent evidence that the site belongs to this school's organisation.
        $mail = $out['crm_email'];
        $siteHost = preg_replace('/^www\./', '', strtolower((string) parse_url((string) ($probe['final_url'] ?? $url), PHP_URL_HOST)));
        if ($found !== [] && $mail !== null && ($siteHost === $mail || str_ends_with($siteHost, '.' . $mail))) {
            return ['verdict' => $probe['tls_unverified'] ? 'verified_group_tls_warning' : 'verified_group', 'detail' => 'The page names part of the school (' . $out['name_words_found'] . ' words) and the CRM contact email is on this same domain (' . $mail . '): the site belongs to the same organisation. It does not confirm this branch.' . $redirectNote . $tlsNote] + $out;
        }

        return ['verdict' => 'resolves_unconfirmed', 'detail' => 'Page loads but the school name is not on it (' . $out['name_words_found'] . ' words found).' . $redirectNote . $tlsNote] + $out;
    }

    /** One safe GET, following at most 4 redirects, re-validating every hop. @return array{status: ?int, body: string, final_url: ?string, error: ?string, message: string} */
    private function probe(SafeUrlFetcher $fetcher, string $url, bool $verifyTls = true): array
    {
        $none = ['status' => null, 'body' => '', 'final_url' => null, 'error' => null, 'message' => '', 'tls_unverified' => false];
        for ($hop = 0; $hop <= 4; $hop++) {
            try {
                [$host, $port, $ip] = $fetcher->validate($url);
            } catch (UnsafeUrlException $e) {
                return ['error' => 'temporary_failure', 'message' => $e->getMessage()] + $none;
            }
            try {
                $response = Http::withOptions(['allow_redirects' => false, 'stream' => true, 'verify' => $verifyTls, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                    ->timeout(10)->connectTimeout(6)
                    ->withHeaders(['User-Agent' => (string) config('signals.ingestion.user_agent'), 'Accept' => 'text/html,text/plain;q=0.9'])->get($url);
            } catch (ConnectionException $e) {
                // Laravel rewrites the message ("Connection refused for URI"); the cURL error code is on the previous exception.
                $m = $e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? '');
                $prev = $e->getPrevious();
                $errno = ($prev instanceof \GuzzleHttp\Exception\ConnectException) ? (int) ($prev->getHandlerContext()['errno'] ?? 0) : 0;
                $tls = in_array($errno, [35, 51, 58, 59, 60, 77, 83], true) || (bool) preg_match('/cURL error (35|51|58|59|60|77|83)\b/', $m);
                if ($verifyTls && ($tls || $errno === 0)) {
                    // Read the page anyway to check WHO it is, and say plainly that the certificate was not verified from this machine
                    // (it may be the site, or this machine's CA bundle: curl.exe and PHP can disagree).
                    $retry = $this->probe($fetcher, $url, false);
                    if ($retry['error'] === null) {
                        return $retry;
                    }
                }

                return ['error' => $tls ? 'tls_error' : 'temporary_failure', 'final_url' => $url,
                    'message' => $tls ? 'The TLS certificate could not be verified.' : ($errno === 28 || str_contains($m, 'cURL error 28') ? 'The request timed out.' : 'The connection failed or was refused.')] + $none;
            }

            $status = $response->status();
            if ($status >= 300 && $status < 400 && $response->header('Location')) {
                $next = $response->header('Location');
                $url = str_starts_with($next, 'http') ? $next : (parse_url($url, PHP_URL_SCHEME) . '://' . $host . (str_starts_with($next, '/') ? '' : '/') . $next);

                continue;
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= 400000) {
                $body .= $stream->read(8192);
            }

            return ['status' => $status, 'body' => substr($body, 0, 400000), 'final_url' => $url, 'error' => null, 'message' => '', 'tls_unverified' => ! $verifyTls];
        }

        return ['error' => 'temporary_failure', 'message' => 'Too many redirects.'] + $none;
    }

    /** The domain of an email address, unless it is a free mail provider (which says nothing about the school). */
    private function emailDomain(string $email): ?string
    {
        $d = strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));
        if ($d === '' || preg_match('/(^|\.)(gmail|yahoo|ymail|rediffmail|hotmail|outlook|live|aol|icloud|protonmail|rocketmail)\./', '.' . $d . '.') === 1) {
            return null;
        }

        return $d;
    }

    private function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return $host !== '' && (gethostbyname($host) !== $host || (bool) @dns_get_record($host, DNS_A | DNS_AAAA));
    }

    /** A DNS failure counts as "invalid" only if it repeats, the bare domain fails too, and a control domain resolves. */
    private function dnsFailureIsConfirmed(string $host): bool
    {
        usleep(400000);
        $apex = preg_replace('/^www\./', '', $host);

        return ! $this->resolves($host) && ! $this->resolves((string) $apex) && $this->resolves('example.com');
    }

    /** @return list<array<string, string>>|null */
    private function readCsv(string $path): ?array
    {
        if (! is_file($path) || ($h = fopen($path, 'r')) === false) {
            return null;
        }
        $head = fgetcsv($h);
        if (! is_array($head)) {
            return null;
        }
        $head = array_map(fn ($c) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $head);
        $rows = [];
        while (($r = fgetcsv($h)) !== false) {
            if ($r !== [null] && count($r) >= 2) {
                $rows[] = array_combine($head, array_pad($r, count($head), '')) ?: [];
            }
        }
        fclose($h);

        return $rows;
    }
}
