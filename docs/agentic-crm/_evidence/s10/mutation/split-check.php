<?php
// standalone: does the new sentence splitter behave?
$re = '/(?<=[.!?])(?<![Nn][Oo]\.)\s+|(?<=[Nn][Oo]\.)(?!\s*\d)\s+|[\r\n]+/u';
$cases = [
    'We are No. 1 in India. Next one.' => ['We are No. 1 in India.', 'Next one.'],
    'We are no.  1 in India.' => ['We are no.  1 in India.'],
    'The answer is no. Next sentence.' => ['The answer is no.', 'Next sentence.'],
    'A casino. Next.' => ['A casino.', 'Next.'],
    "See example.org/page now. It cut errors by 60.5%! Really?\nYes" => ['See example.org/page now.', 'It cut errors by 60.5%!', 'Really?', 'Yes'],
    'Say no. 12 people came.' => ['Say no. 12 people came.'],
    "No.\n1 thing" => ['No.', '1 thing'],
];
$bad = 0;
foreach ($cases as $in => $want) {
    $got = array_values(array_filter(array_map('trim', preg_split($re, $in) ?: []), fn ($p) => $p !== ''));
    if ($got !== $want) {
        $bad++;
        echo 'MISMATCH ' . json_encode($in) . ' got ' . json_encode($got) . ' want ' . json_encode($want) . "\n";
    }
}
echo $bad === 0 ? "all " . count($cases) . " splitter cases behave\n" : "{$bad} mismatches\n";
