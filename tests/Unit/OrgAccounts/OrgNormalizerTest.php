<?php

namespace Tests\Unit\OrgAccounts;

use App\Domain\OrgAccounts\OrgNormalizer;
use Tests\TestCase;

/** The name and domain rules of Organisation 360 (slice 3). Pure text, no database. */
class OrgNormalizerTest extends TestCase
{
    public function test_a_name_is_lower_case_words_without_the_stop_words_sorted_and_unique(): void
    {
        $this->assertSame('academy ryan', OrgNormalizer::name('The Ryan Academy Pvt. Ltd.'));
        $this->assertSame('mary s st', OrgNormalizer::name('St. Mary\'s School'), 'punctuation, even an apostrophe, becomes a separator; "school" is a stop word');
        $this->assertSame('delhi public', OrgNormalizer::name('Delhi Public Schools Private Limited'));
        $this->assertSame('a b', OrgNormalizer::name('A and B of the LLP Inc'));
        $this->assertSame('delhi public', OrgNormalizer::name('Delhi Public Delhi Public'), 'a repeated word counts once');
    }

    public function test_word_order_does_not_matter(): void
    {
        $this->assertSame(OrgNormalizer::name('Podar World School'), OrgNormalizer::name('World Podar'));
        $this->assertSame(OrgNormalizer::name('Vidya Vihar Nirma'), OrgNormalizer::name('Nirma, Vidya-Vihar'));
    }

    public function test_normalising_twice_changes_nothing(): void
    {
        foreach (['The St. Xavier\'s High School, Ahmedabad (Pvt) Ltd', '  ', 'ગુજરાત પબ્લિક સ્કૂલ', 'Kendriya VIDYALAYA No.1', 'A&B', 'Ünïcode Académie'] as $in) {
            $once = OrgNormalizer::name($in);
            $this->assertSame($once, OrgNormalizer::name($once), $in);
        }
    }

    public function test_an_empty_or_all_stop_word_name_is_empty_and_digits_count(): void
    {
        $this->assertSame('', OrgNormalizer::name(null));
        $this->assertSame('', OrgNormalizer::name('   '));
        $this->assertSame('', OrgNormalizer::name('The School of the Schools Pvt Ltd'));
        $this->assertSame('', OrgNormalizer::name('---'));
        $this->assertSame('1 kendriya no vidyalaya', OrgNormalizer::name('Kendriya Vidyalaya No. 1'), 'digits are kept as words');
        $this->assertNotSame(OrgNormalizer::name('Kendriya Vidyalaya No. 1'), OrgNormalizer::name('Kendriya Vidyalaya No. 2'));
    }

    public function test_letters_of_other_scripts_survive_including_their_vowel_signs(): void
    {
        $gujarati = 'ગુજરાત પબ્લિક સ્કૂલ';
        $this->assertNotSame('', OrgNormalizer::name($gujarati));
        $this->assertCount(3, OrgNormalizer::tokens($gujarati), 'three words: the vowel signs and the virama are not separators');
        $this->assertContains('ગુજરાત', OrgNormalizer::tokens($gujarati));

        $hindi = 'दिल्ली पब्लिक स्कूल';
        $this->assertCount(3, OrgNormalizer::tokens($hindi));
        $this->assertNotSame(OrgNormalizer::name($gujarati), OrgNormalizer::name($hindi), 'two different names do not collapse to one');
        $this->assertSame(OrgNormalizer::name('સ્કૂલ ગુજરાત પબ્લિક'), OrgNormalizer::name($gujarati), 'order does not matter in any script');
    }

    public function test_invalid_utf8_does_not_crash_or_empty_the_name(): void
    {
        $this->assertSame('international ryan', OrgNormalizer::name("Ryan \xFF International"), 'the bad byte becomes a separator');
        $this->assertSame('abc xyz', OrgNormalizer::name("Abc \xC3\x28 Xyz"));
    }

    public function test_tokens_are_unique_sorted_and_without_stop_words(): void
    {
        $this->assertSame(['academy', 'ryan'], OrgNormalizer::tokens('Ryan Academy ryan School'));
        $this->assertSame([], OrgNormalizer::tokens(''));
        $this->assertSame([], OrgNormalizer::tokens(null));
    }

    public function test_a_domain_loses_protocol_www_path_query_port_and_trailing_dot(): void
    {
        foreach ([
            'https://www.ryangroup.org/' => 'ryangroup.org',
            'HTTP://WWW.RyanGroup.ORG/about/us?x=1#top' => 'ryangroup.org',
            'ryangroup.org' => 'ryangroup.org',
            'www.ryangroup.org:8080/path' => 'ryangroup.org',
            'ryangroup.org.' => 'ryangroup.org',
            '  https://ryangroup.org?utm=1 ' => 'ryangroup.org',
            'https://user:pw@www.ryangroup.org/x' => 'ryangroup.org',
            '//cdn.example.in/a' => 'cdn.example.in',
            'https://school.example.co.in/home.php' => 'school.example.co.in',
        ] as $in => $want) {
            $this->assertSame($want, OrgNormalizer::domain($in), $in);
        }
        $this->assertSame(OrgNormalizer::domain('https://www.Podar.org/'), OrgNormalizer::domain('podar.org'), 'two spellings, one domain');
    }

    public function test_empty_or_non_host_input_has_no_domain(): void
    {
        foreach ([null, '', '   ', 'N/A', '-', 'none', 'http://', 'https:///path', 'www.', 'not a domain', 'localhost', '.com'] as $in) {
            $this->assertNull(OrgNormalizer::domain($in), var_export($in, true));
        }
    }

    public function test_shared_hosts_are_recognised_with_their_subdomains_and_nothing_else(): void
    {
        $this->assertTrue(OrgNormalizer::isSharedHost('facebook.com'));
        $this->assertTrue(OrgNormalizer::isSharedHost('m.facebook.com'));
        $this->assertTrue(OrgNormalizer::isSharedHost('sites.google.com'));
        $this->assertFalse(OrgNormalizer::isSharedHost('ryangroup.org'));
        $this->assertFalse(OrgNormalizer::isSharedHost('myfacebook.com'), 'a different host that merely ends alike');
        $this->assertFalse(OrgNormalizer::isSharedHost('box.com'));
        $this->assertFalse(OrgNormalizer::isSharedHost(null));
    }

    public function test_overlap_is_jaccard_and_zero_when_either_side_is_empty(): void
    {
        $this->assertSame(1.0, OrgNormalizer::overlap(['a', 'b'], ['b', 'a']));
        $this->assertSame(0.5, OrgNormalizer::overlap(['a', 'b'], ['b', 'c', 'a', 'd']));
        $this->assertEqualsWithDelta(1 / 3, OrgNormalizer::overlap(['a', 'b'], ['b', 'c']), 1e-9);
        $this->assertSame(0.0, OrgNormalizer::overlap(['a'], ['b']));
        $this->assertSame(0.0, OrgNormalizer::overlap([], ['a']));
        $this->assertSame(0.0, OrgNormalizer::overlap(['a'], []));
        $this->assertSame(0.0, OrgNormalizer::overlap([], []));
        $this->assertSame(1.0, OrgNormalizer::overlap(['a', 'a', 'b'], ['b', 'a']), 'repeats count once');
    }
}
