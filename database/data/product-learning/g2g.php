<?php

/*
 * G2G product website: the card shown on My Learning.
 *
 * The card opens the original G2G page (g2gf/public/learning/product-learning/g2g.html) as a normal browser page. It is not a course:
 * there are no lessons, quiz or progress, so this file holds only what the card needs. The frontend picks the page from the product
 * named in `name`, so keep "G2G" in it (and never "ScholarClone").
 */

return [
    'name' => 'G2G Sales',
    'legacy_names' => ['G2G Product Learning'],
];
