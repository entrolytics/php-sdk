<?php

declare(strict_types=1);

namespace Entrolytics;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Entrolytics\Exception\AuthenticationException;
use Entrolytics\Exception\EntrolyticsException;
use Entrolytics\Exception\NetworkException;
use Entrolytics\Exception\RateLimitException;
use Entrolytics\Exception\ValidationException;

/**
 * Entrolytics PHP Client
 *
 * @example
 * $client = new Entrolytics\Client('ent_xxx');
 *
 * $client->track([
 *     'website_id' => 'abc123',
 *     'event' => 'purchase',
 *     'data' => ['revenue' => 99.99]
 * ]);
 */
class Client
{
    private const DEFAULT_HOST = 'https://api.entrolytics.click';
    private const DEFAULT_TIMEOUT = 10.0;
    private const VERSION = '1.1.0';

    private string $apiKey;
    private string $host;
    private float $timeout;
    private HttpClient $http;

    /**
     * Create a new Entrolytics client.
     *
     * @param string $apiKey Your Entrolytics API key
     * @param array{host?: string, timeout?: float} $options Configuration options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (empty($apiKey)) {
            throw new AuthenticationException('API key is required');
        }

        $this->apiKey = $apiKey;
        $this->host = rtrim($options['host'] ?? self::DEFAULT_HOST, '/');
        $this->timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;

        $this->http = new HttpClient([
            'base_uri' => $this->host,
            'timeout' => $this->timeout,
            'headers' => [
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'entrolytics-php/' . self::VERSION,
            ],
        ]);
    }

    /**
     * Track a custom event.
     *
     * @param array{
     *     website_id: string,
     *     event: string,
     *     data?: array<string, mixed>,
     *     url?: string,
     *     referrer?: string,
     *     user_id?: string,
     *     session_id?: string,
    *     visitor_id?: string,
     *     user_agent?: string,
     *     ip_address?: string
     * } $params Event parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function track(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $event = $params['event'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($event)) {
            throw new ValidationException('event is required');
        }

        $properties = $params['data'] ?? [];
        if (!empty($params['user_id'])) {
            $properties['distinctId'] = $params['user_id'];
        }

        $payload = [
            'websiteId' => $websiteId,
            'eventId' => $this->generateUuid(),
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'sessionId' => $params['session_id'] ?? $this->generateUuid(),
            'visitorId' => $params['visitor_id'] ?? $this->generateUuid(),
            'url' => $this->normalizeUrl($params['url'] ?? null, '/track'),
            'eventType' => 'custom_event',
            'eventName' => $event,
            'properties' => $properties,
        ];

        $normalizedReferrer = $this->normalizeReferrer($params['referrer'] ?? null);
        if ($normalizedReferrer !== null) {
            $payload['referrer'] = $normalizedReferrer;
        }

        $headers = [];
        if (!empty($params['user_agent'])) {
            $headers['User-Agent'] = $params['user_agent'];
        }
        if (!empty($params['ip_address'])) {
            $headers['X-Forwarded-For'] = $params['ip_address'];
        }

        return $this->send($payload, $headers);
    }

    /**
     * Track a page view.
     *
     * @param array{
     *     website_id: string,
     *     url: string,
     *     referrer?: string,
     *     title?: string,
     *     user_id?: string,
     *     session_id?: string,
    *     visitor_id?: string,
     *     user_agent?: string,
     *     ip_address?: string
     * } $params Page view parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function pageView(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $url = $params['url'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($url)) {
            throw new ValidationException('url is required');
        }

        $data = [];
        if (!empty($params['title'])) {
            $data['title'] = $params['title'];
        }

        if (!empty($params['user_id'])) {
            $data['distinctId'] = $params['user_id'];
        }

        $payload = [
            'websiteId' => $websiteId,
            'eventId' => $this->generateUuid(),
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'sessionId' => $params['session_id'] ?? $this->generateUuid(),
            'visitorId' => $params['visitor_id'] ?? $this->generateUuid(),
            'url' => $this->normalizeUrl($url, '/'),
            'eventType' => 'pageview',
            'properties' => $data,
        ];

        $normalizedReferrer = $this->normalizeReferrer($params['referrer'] ?? null);
        if ($normalizedReferrer !== null) {
            $payload['referrer'] = $normalizedReferrer;
        }

        $headers = [];
        if (!empty($params['user_agent'])) {
            $headers['User-Agent'] = $params['user_agent'];
        }
        if (!empty($params['ip_address'])) {
            $headers['X-Forwarded-For'] = $params['ip_address'];
        }

        return $this->send($payload, $headers);
    }

    /**
     * Identify a user with traits.
     *
     * @param array{
     *     website_id: string,
     *     user_id: string,
     *     traits?: array<string, mixed>
     * } $params Identification parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function identify(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $userId = $params['user_id'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($userId)) {
            throw new ValidationException('user_id is required');
        }

        $payload = [
            'websiteId' => $websiteId,
            'eventId' => $this->generateUuid(),
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'sessionId' => $this->generateUuid(),
            'visitorId' => $this->generateUuid(),
            'url' => $this->normalizeUrl('/identify', '/identify'),
            'eventType' => 'custom_event',
            'eventName' => 'identify',
            'properties' => array_merge(
                ['distinctId' => $userId],
                $params['traits'] ?? [],
            ),
        ];

        return $this->send($payload);
    }

    // ========================================================================
    // Phase 2: Web Vitals (requires entrolytics)
    // ========================================================================

    /**
     * Track a Web Vital metric.
     * Note: This feature requires entrolytics.
     *
     * @param array{
     *     website_id: string,
     *     metric: string,
     *     value: float,
     *     rating: string,
     *     delta?: float,
     *     id?: string,
     *     navigation_type?: string,
     *     attribution?: array<string, mixed>,
     *     url?: string,
     *     path?: string,
    *     session_id?: string,
    *     visitor_id?: string
     * } $params Web Vital parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function trackVital(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $metric = $params['metric'] ?? null;
        $rating = $params['rating'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($metric)) {
            throw new ValidationException('metric is required (LCP, INP, CLS, TTFB, or FCP)');
        }
        if (empty($rating)) {
            throw new ValidationException('rating is required (good, needs-improvement, or poor)');
        }

        $payload = [
            'websiteId' => $websiteId,
            'visitorId' => $params['visitor_id'] ?? $this->generateUuid(),
            'sessionId' => $params['session_id'] ?? $this->generateUuid(),
            'url' => $this->normalizeUrl($params['url'] ?? null, '/vital'),
            'path' => $params['path'] ?? '/vital',
            'metricName' => $metric,
            'metricValue' => $params['value'] ?? 0,
        ];

        return $this->sendToEndpoint('/api/collect/vitals', $payload);
    }

    // ========================================================================
    // Phase 2: Form Analytics (requires entrolytics)
    // ========================================================================

    /**
     * Track a form interaction event.
     * Note: This feature requires entrolytics.
     *
     * @param array{
     *     website_id: string,
     *     event_type: string,
     *     form_id: string,
     *     url_path: string,
     *     form_name?: string,
     *     field_name?: string,
     *     field_type?: string,
     *     field_index?: int,
     *     time_on_field?: int,
     *     time_since_start?: int,
     *     error_message?: string,
     *     success?: bool,
    *     session_id?: string,
    *     visitor_id?: string
     * } $params Form event parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function trackFormEvent(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $eventType = $params['event_type'] ?? null;
        $formId = $params['form_id'] ?? null;
        $urlPath = $params['url_path'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($eventType)) {
            throw new ValidationException('event_type is required');
        }
        if (empty($formId)) {
            throw new ValidationException('form_id is required');
        }
        if (empty($urlPath)) {
            throw new ValidationException('url_path is required');
        }

        $payload = [
            'websiteId' => $websiteId,
            'visitorId' => $params['visitor_id'] ?? $this->generateUuid(),
            'sessionId' => $params['session_id'] ?? $this->generateUuid(),
            'eventType' => $eventType,
            'formId' => $formId,
            'urlPath' => $urlPath,
        ];

        if (!empty($params['form_name'])) {
            $payload['formName'] = $params['form_name'];
        }
        if (!empty($params['field_name'])) {
            $payload['fieldName'] = $params['field_name'];
        }
        if (!empty($params['field_type'])) {
            $payload['fieldType'] = $params['field_type'];
        }
        if (isset($params['field_index'])) {
            $payload['fieldIndex'] = $params['field_index'];
        }
        if (isset($params['time_on_field'])) {
            $payload['timeOnField'] = $params['time_on_field'];
        }
        if (isset($params['time_since_start'])) {
            $payload['timeSinceStart'] = $params['time_since_start'];
        }
        if (!empty($params['error_message'])) {
            $payload['errorMessage'] = $params['error_message'];
        }
        if (isset($params['success'])) {
            $payload['success'] = $params['success'];
        }
        return $this->sendToEndpoint('/api/collect/forms', $payload);
    }

    // ========================================================================
    // Phase 2: Deployment Tracking (requires entrolytics)
    // ========================================================================

    /**
     * Register deployment context.
     * Note: This feature requires entrolytics.
     *
     * @param array{
     *     website_id: string,
     *     deploy_id: string,
     *     git_sha?: string,
     *     git_branch?: string,
     *     deploy_url?: string,
     *     source?: string
     * } $params Deployment parameters
     * @return bool True on success
     * @throws EntrolyticsException
     */
    public function setDeployment(array $params): bool
    {
        $websiteId = $params['website_id'] ?? null;
        $deployId = $params['deploy_id'] ?? null;

        if (empty($websiteId)) {
            throw new ValidationException('website_id is required');
        }
        if (empty($deployId)) {
            throw new ValidationException('deploy_id is required');
        }

        $payload = [
            'website' => $websiteId,
            'deployId' => $deployId,
        ];

        if (!empty($params['git_sha'])) {
            $payload['gitSha'] = $params['git_sha'];
        }
        if (!empty($params['git_branch'])) {
            $payload['gitBranch'] = $params['git_branch'];
        }
        if (!empty($params['deploy_url'])) {
            $payload['deployUrl'] = $params['deploy_url'];
        }
        if (!empty($params['source'])) {
            $payload['source'] = $params['source'];
        }

        return $this->sendToEndpoint("/api/websites/{$websiteId}/deployments", $payload);
    }

    /**
     * Send a request to the Entrolytics API.
     *
     * @param array<string, mixed> $payload Request payload
     * @param array<string, string> $headers Additional headers
     * @return bool True on success
     * @throws EntrolyticsException
     */
    private function send(array $payload, array $headers = []): bool
    {
        return $this->sendToEndpoint('/collect', $payload, $headers);
    }

    /**
     * Send a request to a specific Entrolytics API endpoint.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $payload Request payload
     * @param array<string, string> $headers Additional headers
     * @return bool True on success
     * @throws EntrolyticsException
     */
    private function sendToEndpoint(string $endpoint, array $payload, array $headers = []): bool
    {
        try {
            $response = $this->http->post($endpoint, [
                'json' => $payload,
                'headers' => $headers,
            ]);

            $statusCode = $response->getStatusCode();
            return $statusCode === 200 || $statusCode === 201 || $statusCode === 202;
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response === null) {
                throw new NetworkException('Request failed: ' . $e->getMessage(), $e);
            }

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            match ($statusCode) {
                401 => throw new AuthenticationException(),
                400 => throw new ValidationException(
                    $this->extractErrorMessage($body) ?? 'Invalid request'
                ),
                429 => throw new RateLimitException(
                    'Rate limit exceeded',
                    $this->extractRetryAfter($response)
                ),
                default => throw new EntrolyticsException(
                    "Request failed with status $statusCode",
                    $statusCode
                ),
            };
        } catch (GuzzleException $e) {
            throw new NetworkException('Request failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Extract error message from response body.
     */
    private function extractErrorMessage(string $body): ?string
    {
        $data = json_decode($body, true);
        return $data['error'] ?? null;
    }

    /**
     * Extract Retry-After header value.
     */
    private function extractRetryAfter($response): ?int
    {
        $header = $response->getHeader('Retry-After');
        if (!empty($header)) {
            return (int) $header[0];
        }
        return null;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function normalizeUrl(?string $url, string $fallbackPath): string
    {
        $raw = $url ?: $fallbackPath;
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return $raw;
        }

        if (!str_starts_with($raw, '/')) {
            $raw = '/' . $raw;
        }

        return $this->host . $raw;
    }

    private function normalizeReferrer(?string $referrer): ?string
    {
        if (empty($referrer)) {
            return null;
        }

        if (str_starts_with($referrer, 'http://') || str_starts_with($referrer, 'https://')) {
            return $referrer;
        }

        return null;
    }
}
