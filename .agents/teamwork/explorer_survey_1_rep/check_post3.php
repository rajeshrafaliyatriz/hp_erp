<?php

$html = file_get_contents(__DIR__ . "/../explorer_survey_1/post3.html");
preg_match_all('/anjuli[^"<>\n]+/i', $html, $m);
print_r(array_unique($m[0]));

// Let's also check if there is an author headline
preg_match_all('/<span class="update-components-actor__[^>]+>(.*?)<\/span>/is', $html, $m2);
print_r($m2[0]);

preg_match_all('/<div class="update-components-actor__container[^>]*>(.*?)<\/div>/is', $html, $m3);
print_r($m3[0]);
