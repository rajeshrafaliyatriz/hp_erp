<?php

/*
 * The products Signals can look for buyers of. The product RECORDS live in the Product Portfolio (g2g_product_offers, grouped by
 * `parent_product`) and each product's ICP in g2g_icp_profiles (`product_line`). This file only says which portfolio group and
 * which ICP product line belong to which product key, and which stored value marks an opportunity as that product's
 * (`business_fit` on g2g_company_opportunities). It holds no profile, ICP, contact or price.
 */
return [
    'scholarclone' => [
        'label' => 'ScholarClone',
        'business_fit' => 'scholar',
        'portfolio_names' => ['Scholar K-12'],
        'icp_product_line' => 'scholar',
        // Used only while the organisation has no ICP of its own for this product.
        'icp_template' => 'scholar_k12',
    ],
    'enterprise_brain' => [
        'label' => 'Enterprise Brain',
        'business_fit' => 'eb',
        'portfolio_names' => ['EB'],
        'icp_product_line' => 'eb',
        'icp_template' => null,
    ],
    'g2g' => [
        'label' => 'G2G',
        'business_fit' => 'g2g',
        'portfolio_names' => ['G2G'],
        'icp_product_line' => 'g2g',
        'icp_template' => null,
    ],
];
