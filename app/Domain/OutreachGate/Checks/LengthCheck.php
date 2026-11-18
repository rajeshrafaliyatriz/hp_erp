<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\Draft;

/** Fails when the body is empty, or the subject or body is longer than the cap in config (characters, not bytes). Pure. */
final class LengthCheck implements Check
{
    public function __construct(private readonly int $maxSubject, private readonly int $maxBody)
    {
    }

    public function key(): string
    {
        return 'length';
    }

    public function label(): string
    {
        return 'Short enough';
    }

    public function run(Draft $draft): CheckResult
    {
        $subject = mb_strlen(trim($draft->subject));
        $body = mb_strlen(trim($draft->body));
        $why = [];
        if ($body === 0) {
            $why[] = 'The body is empty.';
        }
        if ($subject === 0) {
            $why[] = 'The subject is empty.';
        }
        if ($subject > $this->maxSubject) {
            $why[] = "The subject is {$subject} characters; the limit is {$this->maxSubject}.";
        }
        if ($body > $this->maxBody) {
            $why[] = "The body is {$body} characters; the limit is {$this->maxBody}.";
        }

        return $why === []
            ? CheckResult::pass($this->key(), $this->label(), "Subject {$subject} of {$this->maxSubject}, body {$body} of {$this->maxBody} characters.")
            : CheckResult::fail($this->key(), $this->label(), implode(' ', $why));
    }
}
