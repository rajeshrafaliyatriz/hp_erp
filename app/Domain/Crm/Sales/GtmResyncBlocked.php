<?php

namespace App\Domain\Crm\Sales;

/** The GTM resync cannot run on this connection (a table it needs is not there). */
final class GtmResyncBlocked extends \RuntimeException {}
