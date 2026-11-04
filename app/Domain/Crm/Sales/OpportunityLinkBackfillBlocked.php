<?php

namespace App\Domain\Crm\Sales;

/** The opportunity link backfill cannot run on this connection (the columns of slice 11 are not there yet). */
final class OpportunityLinkBackfillBlocked extends \RuntimeException {}
