<?php

namespace Tests\Unit;

use App\Models\CrawlSite;
use App\Support\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape gate that stops "anything without a slash" from becoming a
 * crawlable website. See the prod row `santoshvarma.water@gmail.com`.
 */
class DomainNameTest extends TestCase
{
    public static function validDomains(): array
    {
        return [
            ['example.com'],
            ['sub.example.com'],
            ['deep.sub.example.co.uk'],
            ['my-shop.example.io'],
            ['xn--80akhbyknj4f.xn--p1ai'],   // punycode IDN
            ['münchen.de'],                   // unencoded IDN
            ['123numbers.com'],               // digits are fine outside the TLD
        ];
    }

    public static function invalidDomains(): array
    {
        return [
            'empty' => [''],
            'email' => ['santoshvarma.water@gmail.com'],
            'no dot' => ['localhost'],
            'sentence' => ['my website'],
            'bare ipv4' => ['203.0.113.9'],
            'numeric tld' => ['site.123'],
            'single-char tld' => ['site.c'],
            'leading dot' => ['.example.com'],
            'double dot' => ['exa..mple.com'],
            'leading hyphen label' => ['-example.com'],
            'trailing hyphen label' => ['example-.com'],
            'underscore' => ['my_site.com'],
            'still has a path' => ['example.com/pricing'],
            'still has a port' => ['example.com:8080'],
            'wildcard' => ['*.example.com'],
        ];
    }

    #[DataProvider('validDomains')]
    public function test_accepts_real_hostnames(string $domain): void
    {
        $this->assertTrue(DomainName::isValid($domain), $domain.' should be valid');
    }

    #[DataProvider('invalidDomains')]
    public function test_rejects_non_hostnames(string $domain): void
    {
        $this->assertFalse(DomainName::isValid($domain), $domain.' should be rejected');
    }

    public function test_normalize_strips_userinfo_so_an_email_cannot_pass_as_a_host(): void
    {
        // The whole bug in one line: normalization used to return the email
        // unchanged, and "not empty" was the only check anyone ran.
        $this->assertSame('gmail.com', CrawlSite::normalizeDomain('santoshvarma.water@gmail.com'));
        $this->assertSame('gmail.com', CrawlSite::normalizeDomain('https://me@gmail.com'));
        $this->assertSame('example.com', CrawlSite::normalizeDomain('https://user:pass@www.example.com:8443/path?q=1'));
    }

    public function test_normalize_still_handles_the_ordinary_cases(): void
    {
        $this->assertSame('example.com', CrawlSite::normalizeDomain('https://www.example.com/'));
        $this->assertSame('example.com', CrawlSite::normalizeDomain('EXAMPLE.com.'));
        $this->assertSame('sub.example.com', CrawlSite::normalizeDomain('http://sub.example.com/a/b?c=d#e'));
    }

    public function test_detects_email_input_for_a_useful_error_message(): void
    {
        $this->assertTrue(DomainName::looksLikeEmail('santoshvarma.water@gmail.com'));
        $this->assertTrue(DomainName::looksLikeEmail(' Me@Example.co.uk '));
        $this->assertFalse(DomainName::looksLikeEmail('example.com'));
        $this->assertFalse(DomainName::looksLikeEmail('https://example.com/contact'));
    }

    public function test_site_scope_covers_the_site_and_its_subdomains_only(): void
    {
        $this->assertTrue(DomainName::urlBelongsToSite('https://serfix.io/pricing', 'serfix.io'));
        $this->assertTrue(DomainName::urlBelongsToSite('https://www.serfix.io/pricing', 'serfix.io'));
        $this->assertTrue(DomainName::urlBelongsToSite('https://blog.serfix.io/x', 'serfix.io'));

        // The exact leak: an off-domain redirect re-anchored the parser to
        // Google, and every link on Google's page counted as "internal".
        $this->assertFalse(DomainName::urlBelongsToSite('https://policies.google.com/privacy', 'serfix.io'));
        $this->assertFalse(DomainName::urlBelongsToSite('https://accounts.google.com/TOS', 'serfix.io'));

        // Suffix confusion must not read as a subdomain.
        $this->assertFalse(DomainName::urlBelongsToSite('https://notserfix.io/x', 'serfix.io'));
        $this->assertFalse(DomainName::urlBelongsToSite('https://serfix.io.evil.com/x', 'serfix.io'));

        $this->assertFalse(DomainName::urlBelongsToSite('https://example.com', ''));
        $this->assertFalse(DomainName::urlBelongsToSite('not a url', 'serfix.io'));
    }
}
