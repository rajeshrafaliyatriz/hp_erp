<?php

namespace App\Domain\OrgAccounts;

/**
 * A source row named in a link does not exist, is deleted, or belongs to another organisation. Nothing was written. The controller turns it into a 404,
 * which is also what another organisation's id gets, so a probe learns nothing.
 */
final class OrgLinkNotFound extends \RuntimeException {}
