<?php
declare(strict_types=1);

namespace SlotsLaunch;

final class Client
{
    private string $apiKey;
    private string $apiSecret;
    private string $siteDomain;
    private string $baseUrl;
    private int $iframeTtl;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $siteDomain,
        string $baseUrl = 'https://slotslaunch.com',
        int $iframeTtl = 3600
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->siteDomain = str_replace('www.', '', trim($siteDomain));
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->iframeTtl = $iframeTtl;
    }

    public function iframeUrl(int $gameId, ?int $ttl = null): string
    {
        $exp = time() + ($ttl ?? $this->iframeTtl);
        return $this->baseUrl . '/iframe/' . $gameId
            . '?token=' . rawurlencode($this->apiKey)
            . '&exp=' . $exp
            . '&sig=' . $this->signEmbed($gameId, $exp);
    }

    public function apiHeaders(string $method, string $path, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $path = '/' . ltrim($path, '/');
        $signature = hash_hmac('sha256', $timestamp . "\n" . strtoupper($method) . "\n" . $path, $this->apiSecret);
        return ['X-SL-Timestamp' => (string) $timestamp, 'X-SL-Signature' => $signature];
    }

    public function signEmbed(int $gameId, int $exp): string
    {
        return hash_hmac('sha256', $gameId . "\n" . $exp . "\n" . $this->siteDomain, $this->apiSecret);
    }
}
