<?php

namespace App\Domain\Campaigns;

/** The connection lacks the columns slice 9 adds (its three migrations have not run there): nothing can be linked. */
final class CampaignLinkBackfillBlocked extends \RuntimeException {}
