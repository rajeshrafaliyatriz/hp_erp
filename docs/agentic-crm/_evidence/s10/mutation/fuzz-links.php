<?php
// Standalone (loads no application code): is the "blank out each kind once found" step of LinkCountCheck::links() ever observable?
// Compares the real algorithm (copied verbatim) with the same algorithm WITHOUT the blanking, on random strings built from link-like pieces.
$tlds = ['com', 'in', 'org', 'net', 'io', 'co', 'edu', 'ai', 'app', 'dev', 'me', 'ly', 'info', 'biz', 'tech', 'school', 'ac'];
$tld = implode('|', array_map(fn ($t) => preg_quote($t, '~'), $tlds));
$patterns = [
    '~https?://[^\s<>"\'\)\]]+~iu',
    '~(?<![\w@./:-])www\.[^\s<>"\'\)\]]+~iu',
    '~(?<![\w@./:-])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:' . $tld . ')(?![\w@-])(?:/[^\s<>"\'\)\]]*)?~iu',
];

function links(string $text, array $patterns, bool $blank): array
{
    $found = [];
    $rest = $text;
    foreach ($patterns as $regex) {
        if (preg_match_all($regex, $rest, $m)) {
            foreach ($m[0] as $hit) {
                $found[] = $hit;
            }
            if ($blank) {
                $rest = preg_replace($regex, ' ', $rest) ?? $rest;
            }
        }
    }
    $distinct = [];
    foreach ($found as $hit) {
        $link = mb_strtolower(rtrim($hit, '.,;:!?)]}>\'"'));
        $link = (string) preg_replace('~^https?://~', '', $link);
        $link = (string) preg_replace('~^www\.~', '', $link);
        $link = rtrim($link, '/');
        if ($link === '') {
            continue;
        }
        $distinct[$link] = true;
    }
    ksort($distinct);

    return array_keys($distinct);
}

$pieces = ['http://', 'https://', 'www.', 'example.org', 'a.example.com', 'school.in', 'x.y.z', '/path', '/a/b?c=d.e.com', '?u=b.example.org', ' ', ' ', ' and ', '(', ')', '.', ',', '@', 'name@school.com', 'foo', '-', '/', ':', 'sub-domain.co', 'test.xyz', 'Hello', "\n", '"', "'", 'e.g.', 'i.e.', '1.5', 'v2.app'];
mt_srand(20261104);
$diff = 0;
$n = 200000;
for ($i = 0; $i < $n; $i++) {
    $s = '';
    for ($k = mt_rand(1, 9); $k > 0; $k--) {
        $s .= $pieces[mt_rand(0, count($pieces) - 1)];
    }
    $a = links($s, $patterns, true);
    $b = links($s, $patterns, false);
    if ($a !== $b) {
        $diff++;
        if ($diff <= 5) {
            echo 'DIFFERENT for ' . json_encode($s) . ' real=' . json_encode($a) . ' without=' . json_encode($b) . "\n";
        }
    }
}
echo "strings tried: {$n}; strings where blanking out changes the answer: {$diff}\n";
