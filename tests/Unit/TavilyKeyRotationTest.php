<?php

namespace Tests\Unit;

use App\Domain\Signals\Opportunities\TavilySearchProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Several Tavily keys: a key whose limit is used up is left alone and the next is used; when every key is resting only one request is made;
 * once a rest ends the first key is tried again. No network (Http::fake), no database, and keys are fake test values.
 */
class TavilyKeyRotationTest extends TestCase
{
    private array $used = [];
    /** @var array<string, int> */
    private array $status = [];
    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['signals.web_search.tavily_key' => 'tvly-key-one', 'signals.web_search.tavily_keys' => 'tvly-key-two, tvly-key-three', 'signals.web_search.tavily_key_rest_hours' => 6]);
        $this->used = [];
    }

    /** @param array<string, int> $status the HTTP status each key answers with (200 for any other); can be changed between searches */
    private function fake(array $status): void
    {
        $this->status = $status;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function ($request) {
            $key = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            $this->used[] = $key;
            $code = $this->status[$key] ?? 200;

            return Http::response($code === 200 ? ['results' => [['url' => 'https://a.example', 'title' => 't', 'content' => 'c']]] : ['detail' => 'limit'], $code);
        });
    }

    private function search(): array
    {
        return (new TavilySearchProvider())->search('x', 30, 3);
    }

    public function test_a_key_over_its_limit_is_skipped_and_the_next_key_answers_in_the_same_search(): void
    {
        $this->fake(['tvly-key-one' => 432]);
        $this->assertCount(1, $this->search());
        $this->assertSame(['tvly-key-one', 'tvly-key-two'], $this->used);

        $this->used = [];
        $this->search();
        $this->assertSame(['tvly-key-two'], $this->used, 'the exhausted first key is not tried again while it rests');
    }

    public function test_when_every_key_is_used_up_the_search_fails_and_later_searches_cost_one_request_not_one_per_key(): void
    {
        $this->fake(['tvly-key-one' => 432, 'tvly-key-two' => 432, 'tvly-key-three' => 433]);
        try {
            $this->search();
            $this->fail('expected the search to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tavily returned HTTP 433', $e->getMessage());
        }
        $this->assertSame(['tvly-key-one', 'tvly-key-two', 'tvly-key-three'], $this->used);

        $this->used = [];
        try {
            $this->search();
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $this->used, 'all keys resting: only the one that is free soonest is tried');
    }

    public function test_after_the_last_key_the_first_is_used_again_once_its_rest_has_ended(): void
    {
        $this->fake(['tvly-key-one' => 432, 'tvly-key-two' => 432, 'tvly-key-three' => 432]);
        try {
            $this->search();
        } catch (\RuntimeException) {
        }
        // the limits refill: key one answers again, and its rest is over
        $rest = Cache::get('signals:tavily:rest');
        foreach ($rest as $fp => $until) {
            $rest[$fp] = time() - 1;
        }
        Cache::put('signals:tavily:rest', $rest);
        $this->fake([]);
        $this->used = [];
        $this->assertCount(1, $this->search());
        $this->assertSame(['tvly-key-one'], $this->used, 'back to the first key');
    }

    public function test_a_single_key_behaves_exactly_as_before(): void
    {
        config(['signals.web_search.tavily_keys' => null]);
        $this->fake(['tvly-key-one' => 432]);
        try {
            $this->search();
            $this->fail('expected the search to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Tavily returned HTTP 432', $e->getMessage());
        }
        $this->assertSame(['tvly-key-one'], $this->used);
    }

    public function test_other_errors_are_not_rotated_and_the_extra_keys_alone_make_it_configured(): void
    {
        $this->fake(['tvly-key-one' => 500]);
        try {
            $this->search();
        } catch (\RuntimeException $e) {
            $this->assertSame('Tavily returned HTTP 500', $e->getMessage());
        }
        $this->assertSame(['tvly-key-one'], $this->used);

        config(['signals.web_search.tavily_key' => null, 'signals.web_search.tavily_keys' => 'tvly-only-extra']);
        $this->assertTrue((new TavilySearchProvider())->isConfigured());
        config(['signals.web_search.tavily_keys' => 'YOUR_TAVILY_KEY']);
        $this->assertFalse((new TavilySearchProvider())->isConfigured(), 'a template value is not a key');
    }
}
