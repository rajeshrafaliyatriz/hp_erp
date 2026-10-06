<?php

namespace App\Domain\Signals\Support;

/**
 * A small robots.txt reader. Honours `Disallow` / `Allow` (longest match wins) for the
 * group that names our bot, else for `*`. An unreachable robots.txt means "allowed",
 * which is what the convention says; a reachable one that forbids the path stops us.
 */
class RobotsPolicy
{
    public function __construct(private readonly SafeUrlFetcher $fetcher)
    {
    }

    public function allows(string $url): bool
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        try {
            $robots = $this->fetcher->fetch(
                $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/robots.txt',
                65536,
                6
            );
        } catch (\Throwable) {
            return true;
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return self::evaluate($robots['body'], $path, strtolower(explode('/', (string) config('signals.ingestion.user_agent'))[0]));
    }

    public static function evaluate(string $robots, string $path, string $botName): bool
    {
        $groups = [];
        $agents = [];
        $lastWasAgent = false;

        foreach (preg_split('/\R/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $agents = [];
                }
                $agents[] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;

            if (in_array($field, ['allow', 'disallow'], true)) {
                foreach ($agents as $agent) {
                    $groups[$agent][] = [$field, $value];
                }
            }
        }

        $rules = $groups[$botName] ?? $groups['*'] ?? [];
        $verdict = true;
        $best = -1;
        foreach ($rules as [$type, $prefix]) {
            if ($prefix === '' || ! str_starts_with($path, $prefix)) {
                continue;
            }
            if (strlen($prefix) > $best || (strlen($prefix) === $best && $type === 'allow')) {
                $best = strlen($prefix);
                $verdict = $type === 'allow';
            }
        }

        return $verdict;
    }
}
