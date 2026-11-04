<?php

namespace Tests\Feature;

use App\Domain\Facts\SocialUrl;
use Tests\TestCase;

/**
 * The golden tests of the profile-address reader (slice 4), ported from Comp AI's socials.spec.ts and the profile rules of people.spec.ts (MIT licence).
 * One deliberate difference, pinned below: LinkedIn addresses ARE read here (they are the value of crm_contacts.linkedin_url). Reading an address is
 * parsing text; nothing in this domain fetches it.
 */
class FactsSocialUrlTest extends TestCase
{
    public function test_a_profile_on_either_x_hostname_is_read(): void
    {
        $this->assertSame(['network' => 'x', 'handle' => 'lewiscarhart', 'url' => 'https://x.com/lewiscarhart', 'kind' => 'person'], SocialUrl::parse('https://x.com/lewiscarhart'));
        $this->assertSame('https://x.com/LewisCarhart', SocialUrl::parse('https://twitter.com/LewisCarhart')['url']);
        $this->assertSame('lewis', SocialUrl::parse('https://mobile.twitter.com/@lewis')['handle']);
        $this->assertSame('lewiscarhart', SocialUrl::parse('github.com/lewiscarhart')['handle'], 'a bare address without a scheme is read too');
    }

    public function test_a_deep_link_on_x_or_github_is_refused_because_that_is_where_wrong_handles_come_from(): void
    {
        $this->assertNull(SocialUrl::parse('https://x.com/someone/status/1234'));
        $this->assertNull(SocialUrl::parse('https://github.com/someone/some-repo'));
    }

    public function test_site_paths_that_parse_like_a_username_are_refused(): void
    {
        foreach (['https://github.com/pricing', 'https://github.com/orgs', 'https://x.com/settings', 'https://x.com/i', 'https://x.com/Home'] as $url) {
            $this->assertNull(SocialUrl::parse($url), $url);
        }
    }

    public function test_handles_neither_network_could_issue_are_refused(): void
    {
        $this->assertNull(SocialUrl::parse('https://x.com/waytoolongforanxhandle'));
        $this->assertNull(SocialUrl::parse('https://github.com/-lewis'));
        $this->assertNull(SocialUrl::parse('https://github.com/lewis--carhart'));
        $this->assertNull(SocialUrl::parse('https://github.com/lewis-'), 'a GitHub handle cannot end with a hyphen');
    }

    public function test_anything_that_is_not_a_known_network_is_ignored(): void
    {
        $this->assertNull(SocialUrl::parse('not a url'));
        $this->assertNull(SocialUrl::parse(''));
        $this->assertNull(SocialUrl::parse('https://facebook.com/lewiscarhart'));
        $this->assertNull(SocialUrl::parse('https://x.com.evil.example/lewis'), 'a host that merely starts with the network name');
        $this->assertNull(SocialUrl::parse('https://evilx.com/lewis'));
        $this->assertNull(SocialUrl::parse('https://user:pass@github.com/lewis'), 'an address with credentials in it');
    }

    public function test_profiles_are_pulled_out_of_prose_and_citations_deduplicated(): void
    {
        $found = SocialUrl::extract([
            'You can find him at https://github.com/lewiscarhart and https://x.com/lewiscarhart.',
            'https://github.com/lewiscarhart',
            'https://github.com/lewiscarhart/crm',
        ]);

        $this->assertSame(['https://github.com/lewiscarhart', 'https://x.com/lewiscarhart'], array_column($found, 'url'));
    }

    public function test_nothing_is_found_in_an_answer_that_cites_nothing(): void
    {
        $this->assertSame([], SocialUrl::extract(['I could not find a GitHub profile.']));
    }

    // ---- LinkedIn (the G2G difference) ----

    public function test_a_linkedin_person_address_is_read_where_the_original_parser_refused_it(): void
    {
        $this->assertSame(['network' => 'linkedin', 'handle' => 'lewiscarhart', 'url' => 'https://www.linkedin.com/in/lewiscarhart', 'kind' => 'person'], SocialUrl::parse('https://linkedin.com/in/lewiscarhart'));
        $this->assertSame('https://www.linkedin.com/in/lewiscarhart', SocialUrl::parse('https://in.linkedin.com/in/lewiscarhart/')['url'], 'a country subdomain and a trailing slash');
    }

    public function test_a_linkedin_organisation_or_school_address_is_read_as_an_organisation(): void
    {
        $this->assertSame('organization', SocialUrl::parse('https://www.linkedin.com/company/fernhill')['kind']);
        $this->assertSame('organization', SocialUrl::parse('https://www.linkedin.com/school/fernhill-high')['kind']);
    }

    public function test_a_deeper_linkedin_path_is_cut_back_to_the_profile_but_other_sections_are_refused(): void
    {
        $this->assertSame('https://www.linkedin.com/in/paula-marchetti', SocialUrl::parse('https://www.linkedin.com/in/paula-marchetti/details/experience/?x=1')['url']);
        foreach (['https://www.linkedin.com/feed/', 'https://www.linkedin.com/posts/paula_x-123', 'https://www.linkedin.com/in/', 'https://www.linkedin.com/in/a', 'https://www.linkedin.com/jobs/view/1'] as $url) {
            $this->assertNull(SocialUrl::parse($url), $url);
        }
    }

    public function test_a_host_that_only_ends_like_linkedin_is_not_linkedin(): void
    {
        $this->assertNull(SocialUrl::parse('https://linkedin.com.evil.example/in/paula'));
        $this->assertNull(SocialUrl::parse('https://notlinkedin.com/in/paula'));
    }

    public function test_the_profile_is_the_linkedin_address_never_another_social_page(): void
    {
        $this->assertSame('paulamarchetti', SocialUrl::linkedinSlug('https://www.linkedin.com/in/paulamarchetti'));
        $this->assertNull(SocialUrl::linkedinSlug('https://x.com/pmarchetti'), 'another social page is never treated as the profile');
        $this->assertNull(SocialUrl::linkedinSlug('https://www.linkedin.com/company/fernhill'), 'an organisation page is not a person');
        $this->assertNull(SocialUrl::linkedinSlug(null));
        $this->assertNull(SocialUrl::linkedinSlug(''));
    }

    public function test_the_first_linkedin_address_among_several_social_links_is_found_by_extract(): void
    {
        $found = SocialUrl::extract(['https://x.com/pmarchetti', 'https://www.linkedin.com/in/paulamarchetti']);

        $this->assertSame(['x', 'linkedin'], array_column($found, 'network'));
    }
}
