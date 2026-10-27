<?php

namespace Tests\Unit\Crm;

use App\Domain\Crm\Leads\LeadReplyProcessor;
use PHPUnit\Framework\TestCase;

/** The two pure rules the reply handler rests on: what counts as the customer's own words, and what counts as "stop contacting me". */
class LeadReplyProcessorTest extends TestCase
{
    public function test_quoted_history_is_not_the_customers_words(): void
    {
        $text = "Yes, please send the pricing.\n\nThanks,\nAsha\n\nOn Tue, 20 Oct 2026 at 10:00, Sales <sales@example.com> wrote:\n> Hello, would you like to unsubscribe?\n> Regards";
        $this->assertSame("Yes, please send the pricing.\n\nThanks,\nAsha", LeadReplyProcessor::stripQuoted($text));
    }

    public function test_quoted_lines_and_forward_markers_end_the_reply(): void
    {
        $this->assertSame('Sounds good.', LeadReplyProcessor::stripQuoted("Sounds good.\n> earlier text\nmore"));
        $this->assertSame('Interested.', LeadReplyProcessor::stripQuoted("Interested.\n-----Original Message-----\nFrom: x\nstop emailing"));
        $this->assertSame('', LeadReplyProcessor::stripQuoted("> only quoted\n> text"));
    }

    public function test_a_from_line_inside_the_reply_only_ends_it_after_some_text(): void
    {
        $this->assertSame('Hello', LeadReplyProcessor::stripQuoted("Hello\nFrom: Asha <a@b.co>\nSent: today"));
    }

    public function test_clear_requests_to_stop_are_recognised(): void
    {
        foreach (['Please unsubscribe me', 'REMOVE ME from your list', 'Stop emailing me.', 'do not contact us again', "Don't email me", 'No further contact please', 'take me off this list', 'I want to opt out', 'Please stop sending these'] as $t) {
            $this->assertTrue(LeadReplyProcessor::asksToStop($t), $t);
        }
    }

    public function test_ordinary_replies_are_not_opt_outs(): void
    {
        foreach (['Yes, send the pricing', 'Can we meet Thursday?', 'Not interested right now, maybe next quarter', 'How do I subscribe to updates?', 'We stopped using the old system last year'] as $t) {
            $this->assertFalse(LeadReplyProcessor::asksToStop($t), $t);
        }
    }

    public function test_an_opt_out_phrase_that_is_only_in_the_quoted_history_is_ignored(): void
    {
        $this->assertFalse(LeadReplyProcessor::asksToStop("Sure, call me.\n\nOn Mon, 19 Oct 2026, Sales wrote:\n> reply STOP to unsubscribe"));
    }
}
