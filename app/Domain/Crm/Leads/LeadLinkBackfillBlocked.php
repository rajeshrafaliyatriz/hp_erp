<?php

namespace App\Domain\Crm\Leads;

/** The lead-link backfill cannot run on this connection (a table or column it needs is not there). The message says which. */
final class LeadLinkBackfillBlocked extends \RuntimeException {}
