<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;

/** Fixed-host HTTPS webhook delivery with public-IP pinning and no redirects. */
final class ProductHookDelivery
{
    public function __construct(private readonly ?\Closure $resolve = null) {}

    public function send(string $url, array $payload): void
    {
        if (! function_exists('curl_init')) { throw new \RuntimeException('product_hook_transport_unavailable'); }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\x00-\x20\\\\]/', $url)
            || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*\z/iD', $host)) {
            throw new \RuntimeException('product_hook_destination_rejected');
        }
        $address = $this->address($host);
        try {
            $response = Http::connectTimeout(5)->timeout(15)->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'force_ip_resolve' => 'v4',
                'proxy' => '',
                'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS],
            ])->post($url, $payload);
            if (! $response->successful()) { throw new \RuntimeException('product_hook_http_failed'); }
        } catch (\Throwable) {
            throw new \RuntimeException('product_hook_delivery_failed');
        }
    }

    /** Shared public IPv4 pin for HTTPS callbacks, Bark and SMTP test connections. */
    public function address(string $host): string
    {
        $addresses = $this->resolve ? ($this->resolve)($host) : $this->resolvePublicHost($host);
        if (! $addresses) { throw new \RuntimeException('product_hook_resolution_failed'); }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_contains($address, ':')
                || \Symfony\Component\HttpFoundation\IpUtils::checkIp($address, [
                    '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                    '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                    '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
                ])) {
                // IPv4 only until the full IPv6 special-purpose space is covered by policy.
                throw new \RuntimeException('product_hook_destination_rejected');
            }
        }
        return $addresses[0];
    }

    private function resolvePublicHost(string $host): array
    {
        return array_values(array_unique(@gethostbynamel($host) ?: []));
    }
}
