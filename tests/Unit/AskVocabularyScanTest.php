<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The vocabulary guard of the Ask chat (slice 15, track WPB, G-E6 of the products parity audit). The Product Portfolio merge retired four words from every screen:
 * "Product line", "Catalogue", "Offer" and "Item". What Ask says reaches a person through the model, so no string of `app/Domain/Ask` (the text the tools describe
 * themselves with, the kinds and the facts they hand to the model, the prompts) may use them; the words are Product and Sub-product (a service is a Sub-product of type
 * Service).
 *
 * How it reads: the PHP tokens of every file of `app/Domain/Ask` and `app/Domain/Ask/Tools`; only STRING LITERALS and heredocs count (comments are for the developers, not
 * for the person). A string that is a code identifier on purpose is in ALLOWED with its reason, and a row of ALLOWED that no source holds any more fails the test, so the
 * list cannot go stale. Plain PHPUnit, no database.
 */
class AskVocabularyScanTest extends TestCase
{
    /**
     * The retired words, as noun phrases a person would read. "offer" is matched only as the noun (the verb in "Never offer to send" is not the retired word).
     *
     * @var array<string, string> label => regular expression
     */
    private const RETIRED = [
        'product line' => '/product[ -]lines?/i',
        'catalogue' => '/catalogue/i',
        'item' => '/\bitems?\b/i',
        'offer (noun)' => '/\b(?:portfolio|product|sub-product|the|an?|each|its|of|per) offers?\b|\boffers\b|\boffer[ _-]?(?:id|row)\b/i',
    ];

    /**
     * Strings that contain a retired word and stay, each with the reason. They are KEYS of the list_products answer, kept as they were because the answer's shape is not
     * changed by this track (WPB, item 3: "keep every other key of its answer"); nothing reads them but the model. Renaming them is a decision for the owner.
     *
     * @var array<string, string> the literal => why it stays
     */
    private const ALLOWED = [
        "'sold_in_the_crm_catalogue'" => 'a key of the list_products answer: whether a CRM catalogue row stands behind the record; the shape of the answer is kept',
    ];

    /** @return list<string> the source files of the Ask chat */
    private function files(): array
    {
        $root = dirname(__DIR__, 2) . '/app/Domain/Ask';
        $files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/Tools/*.php') ?: [], [dirname(__DIR__, 2) . '/app/Http/Controllers/Api/Agent/AskController.php']);
        $this->assertGreaterThanOrEqual(20, count($files), 'the scan sees the whole Ask code');

        return $files;
    }

    /** @return list<array{file: string, line: int, text: string}> every string literal and heredoc piece of the source */
    private function strings(string $source, string $file): array
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = ['file' => basename($file), 'line' => $token[2], 'text' => $token[1]];
            }
        }

        return $out;
    }

    /** @return list<string> "file:line label: text" of every retired word found in a string that is not allowed */
    private function findings(array $strings): array
    {
        $found = [];
        foreach ($strings as $s) {
            if (isset(self::ALLOWED[trim($s['text'])])) {
                continue;
            }
            foreach (self::RETIRED as $label => $regex) {
                if (preg_match($regex, $s['text']) === 1) {
                    $found[] = "{$s['file']}:{$s['line']} [{$label}] " . substr(str_replace(["\r", "\n"], ' ', trim($s['text'])), 0, 120);
                }
            }
        }

        return $found;
    }

    public function test_no_string_of_the_ask_code_speaks_a_retired_word(): void
    {
        $strings = [];
        foreach ($this->files() as $file) {
            $strings = array_merge($strings, $this->strings((string) file_get_contents($file), $file));
        }

        $this->assertSame([], $this->findings($strings), 'Ask says Product and Sub-product, never Product line, Catalogue, Offer or Item');
    }

    public function test_the_scan_sees_each_retired_word_so_a_pass_means_something(): void
    {
        foreach (['Lists the product lines.' => 'product line', 'a catalogue item' => 'catalogue', 'one item' => 'item', 'the Portfolio offer' => 'offer (noun)', 'offers sold together' => 'offer (noun)', 'an offer id' => 'offer (noun)'] as $text => $label) {
            $found = $this->findings([['file' => 'X.php', 'line' => 1, 'text' => "'{$text}'"]]);
            $this->assertNotSame([], $found, "'{$text}' must be caught");
            $this->assertStringContainsString("[{$label}]", implode(' ', $found));
        }
        // The words that are fine: the verb, the new vocabulary, and a word that only contains a retired one.
        foreach (['Never offer to send a message.', 'Lists the Products and their Sub-products.', 'a Sub-product of type Service', 'the itemised total'] as $text) {
            $this->assertSame([], $this->findings([['file' => 'X.php', 'line' => 1, 'text' => "'{$text}'"]]), "'{$text}'");
        }
    }

    public function test_every_allowed_string_is_still_in_the_source_and_still_holds_a_retired_word(): void
    {
        $all = [];
        foreach ($this->files() as $file) {
            foreach ($this->strings((string) file_get_contents($file), $file) as $s) {
                $all[trim($s['text'])] = true;
            }
        }
        foreach (self::ALLOWED as $literal => $reason) {
            $this->assertArrayHasKey($literal, $all, "ALLOWED holds {$literal}, which no file of the Ask code has any more: remove it ({$reason})");
            $holds = false;
            foreach (self::RETIRED as $regex) {
                $holds = $holds || preg_match($regex, $literal) === 1;
            }
            $this->assertTrue($holds, "{$literal} holds no retired word, so it does not need to be allowed");
            $this->assertGreaterThan(30, strlen($reason), 'a reason is a sentence');
        }
    }

    public function test_the_list_products_tool_describes_itself_in_the_new_words(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Ask/Tools/ListProductsTool.php');

        $this->assertStringContainsString("'Product'", $source);
        $this->assertStringContainsString("'Sub-product'", $source);
        $this->assertStringContainsString('/portfolio/products/', $source);
        $this->assertStringNotContainsString('/module/crm/sales/', $source, 'the old module addresses are gone from the tool');
    }
}
