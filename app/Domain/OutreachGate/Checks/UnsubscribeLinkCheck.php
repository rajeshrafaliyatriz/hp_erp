<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\Gtm\OutreachMailer;
use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;
use App\Services\Crm\CrmOutreachMailer;

/**
 * Whether the unsubscribe link every email carries would work for a real recipient. It REUSES the answer each mailer already gives (their public unsubscribeProblem()),
 * so there is one rule and no second copy: the check only asks, and a mailer that is sending has passed it already. It is here so a PREVIEW tells the whole truth.
 */
final class UnsubscribeLinkCheck implements Check
{
    public function key(): string
    {
        return 'unsubscribe_link';
    }

    public function label(): string
    {
        return 'Unsubscribe link reachable';
    }

    public function run(Draft $draft): CheckResult
    {
        $problem = $draft->table === Draft::GTM ? app(OutreachMailer::class)->unsubscribeProblem() : (new CrmOutreachMailer())->unsubscribeProblem();

        return $problem === null
            ? CheckResult::pass($this->key(), $this->label(), 'The unsubscribe link every email carries can be reached.')
            : CheckResult::fail($this->key(), $this->label(), $problem);
    }
}
