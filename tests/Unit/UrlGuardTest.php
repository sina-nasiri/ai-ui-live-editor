<?php

namespace Tests\Unit;

use App\Support\BlockedUrlException;
use App\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The proxy is the one place this app can be turned into a weapon, so the
 * address checks get the most direct tests in the suite.
 */
class UrlGuardTest extends TestCase
{
    private function guard(bool $allowPrivate = false, array $allowedHosts = []): UrlGuard
    {
        return new UrlGuard($allowPrivate, $allowedHosts);
    }

    #[DataProvider('privateAddresses')]
    public function test_private_and_reserved_addresses_are_not_public(string $address): void
    {
        $this->assertFalse(
            $this->guard()->isPublicAddress($address),
            $address.' should be treated as unreachable'
        );
    }

    public static function privateAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback range' => ['127.99.1.5'],
            'any' => ['0.0.0.0'],
            'rfc1918 /8' => ['10.1.2.3'],
            'rfc1918 /12' => ['172.16.0.1'],
            'rfc1918 /12 upper' => ['172.31.255.254'],
            'rfc1918 /16' => ['192.168.1.1'],
            'cloud metadata' => ['169.254.169.254'],
            'carrier grade nat' => ['100.64.0.1'],
            'benchmarking' => ['198.18.0.1'],
            'documentation' => ['203.0.113.5'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unspecified' => ['::'],
            'ipv6 unique local' => ['fd00::1'],
            'ipv6 link local' => ['fe80::1'],
            // The classic bypass: an IPv4 loopback wearing an IPv6 costume.
            'ipv4 mapped loopback' => ['::ffff:127.0.0.1'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed(string $address): void
    {
        $this->assertTrue($this->guard()->isPublicAddress($address), $address.' should be reachable');
    }

    public static function publicAddresses(): array
    {
        return [
            'v4' => ['93.184.216.34'],
            'v4 edge of rfc1918' => ['172.32.0.1'],
            'v4 below rfc1918' => ['172.15.255.255'],
            'v6' => ['2606:2800:220:1:248:1893:25c8:1946'],
        ];
    }

    public function test_non_http_schemes_are_rejected(): void
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/x', 'gopher://example.com'] as $url) {
            $this->expectExceptionOnValidate($url);
        }
    }

    public function test_literal_private_ip_is_rejected(): void
    {
        $this->expectExceptionOnValidate('http://169.254.169.254/latest/meta-data/');
    }

    public function test_embedded_credentials_are_rejected(): void
    {
        $this->expectExceptionOnValidate('http://user:pass@example.com/');
    }

    public function test_private_addresses_are_allowed_when_explicitly_enabled(): void
    {
        $this->assertSame(
            'http://127.0.0.1:8080/',
            $this->guard(allowPrivate: true)->validate('http://127.0.0.1:8080/')
        );
    }

    public function test_allow_list_blocks_everything_else(): void
    {
        $guard = $this->guard(allowPrivate: true, allowedHosts: ['example.com']);

        $this->assertSame('https://example.com/a', $guard->validate('https://example.com/a'));
        $this->assertSame('https://www.example.com/a', $guard->validate('https://www.example.com/a'));

        $this->expectException(BlockedUrlException::class);
        $guard->validate('https://evil.test/a');
    }

    public function test_allow_list_does_not_match_a_lookalike_suffix(): void
    {
        $guard = $this->guard(allowPrivate: true, allowedHosts: ['example.com']);

        $this->expectException(BlockedUrlException::class);
        $guard->validate('https://notexample.com/a');
    }

    private function expectExceptionOnValidate(string $url): void
    {
        try {
            $this->guard()->validate($url);
            $this->fail($url.' should have been blocked');
        } catch (BlockedUrlException) {
            $this->addToAssertionCount(1);
        }
    }
}
