<?php

namespace App\Support;

/**
 * Decides whether the proxy is allowed to fetch a URL.
 *
 * Without this, `/proxy` is an unauthenticated request-forgery primitive: any
 * visitor could reach cloud metadata endpoints, internal admin panels, or
 * anything else the server can see. Every hop of a redirect chain has to pass
 * the same checks, because a public host can redirect to a private one.
 */
class UrlGuard
{
    /**
     * IPv4 ranges that are never routable on the public internet.
     *
     * filter_var()'s NO_PRIV_RANGE/NO_RES_RANGE flags cover most of these; the
     * list is repeated explicitly so the policy is auditable and so ranges PHP
     * misses (carrier-grade NAT, benchmarking, documentation nets) are covered.
     *
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // RFC 1918 private
        '100.64.0.0/10',      // RFC 6598 carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local — cloud instance metadata lives here
        '172.16.0.0/12',      // RFC 1918 private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // RFC 1918 private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved (includes 255.255.255.255)
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::ffff:0:0/96',      // IPv4-mapped — the classic bypass
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    public function __construct(
        private readonly bool $allowPrivateNetworks = false,
        /** @var list<string> */
        private readonly array $allowedHosts = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (bool) config('editor.proxy.allow_private_networks', false),
            (array) config('editor.proxy.allowed_hosts', []),
        );
    }

    /**
     * Validate a URL and return it normalised.
     *
     * @throws BlockedUrlException
     */
    public function validate(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new BlockedUrlException('That does not look like a complete URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedUrlException('Only http:// and https:// URLs can be loaded.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '') {
            throw new BlockedUrlException('That URL has no hostname.');
        }

        // Strip the brackets PHP keeps around a literal IPv6 host.
        $bareHost = trim($host, '[]');

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException('URLs with embedded credentials are not allowed.');
        }

        $this->assertHostAllowed($bareHost);
        $this->assertResolvesPublicly($bareHost);

        return $url;
    }

    /**
     * Re-check a redirect target. Called for every hop, so a public host
     * cannot bounce the fetch into the private network.
     *
     * @throws BlockedUrlException
     */
    public function validateRedirect(string $url): void
    {
        $this->validate($url);
    }

    /**
     * @throws BlockedUrlException
     */
    private function assertHostAllowed(string $host): void
    {
        if ($this->allowedHosts === []) {
            return;
        }

        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed, ". \t\n\r"));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return;
            }
        }

        throw new BlockedUrlException('This instance is configured to only load an allow-listed set of sites.');
    }

    /**
     * Resolve the host and require every address to be publicly routable.
     *
     * A hostname can resolve to several addresses; if any one of them is
     * private the fetch is refused rather than gambling on which one the HTTP
     * client will pick.
     *
     * @throws BlockedUrlException
     */
    private function assertResolvesPublicly(string $host): void
    {
        if ($this->allowPrivateNetworks) {
            return;
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw new BlockedUrlException('That hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new BlockedUrlException(
                    'That address is on a private or reserved network, which this editor will not fetch.'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $range) {
                if ($this->inRange($address, $range, 4)) {
                    return false;
                }
            }

            return true;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as $range) {
                if ($this->inRange($address, $range, 6)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Binary CIDR containment check that works for both address families.
     */
    private function inRange(string $address, string $cidr, int $version): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $addressBytes = @inet_pton($address);
        $subnetBytes = @inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false) {
            return false;
        }

        $expected = $version === 4 ? 4 : 16;
        if (strlen($addressBytes) !== $expected || strlen($subnetBytes) !== $expected) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBytes, $subnetBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($subnetBytes[$wholeBytes]) & $mask);
    }
}
