<?php

declare(strict_types=1);

namespace Entrolytics\Tests;

use Entrolytics\Client;
use Entrolytics\Exception\NetworkException;
use Entrolytics\Exception\RateLimitException;
use Entrolytics\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    private const WEBSITE_ID = '6f1c7c8e-5d3b-4f5a-9d1e-2b6f0c9a7e41';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh) Safari/605.1.15';
    private const IP = '203.0.113.7';

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    /** @param list<Response|\Throwable>|null $responses */
    private function client(?array $responses = null): Client
    {
        $client = new Client('ent_test');
        $http = new \ReflectionProperty(Client::class, 'http');
        $config = $http->getValue($client)->getConfig();
        $config['handler'] = HandlerStack::create(new MockHandler($responses ?? array_fill(0, 4, new Response(202))));
        $config['handler']->push(Middleware::history($this->sent));
        $http->setValue($client, new HttpClient($config));

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(int $index): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true);
    }

    /** @return array<string, mixed> */
    private static function composerJson(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
    }

    public function testClassesAndLaravelDiscoveryEntriesResolveThroughComposersAutoloader(): void
    {
        $loader = require __DIR__ . '/../vendor/autoload.php';
        $laravel = self::composerJson()['extra']['laravel'];
        $classes = [
            Client::class,
            \Entrolytics\Exception\EntrolyticsException::class,
            \Entrolytics\Exception\AuthenticationException::class,
            \Entrolytics\Exception\ValidationException::class,
            \Entrolytics\Exception\RateLimitException::class,
            \Entrolytics\Exception\NetworkException::class,
            ...$laravel['providers'],
            ...array_values($laravel['aliases']),
        ];

        foreach ($classes as $class) {
            $this->assertNotFalse($loader->findFile($class), "{$class} is not autoloadable");
        }
    }

    public function testTheUserAgentCarriesThePackageVersion(): void
    {
        $client = $this->client();
        $client->identify(['website_id' => self::WEBSITE_ID, 'user_id' => 'user-1']);

        $this->assertSame(
            'entrolytics-php/' . self::composerJson()['version'],
            $this->sent[0]['request']->getHeaderLine('User-Agent'),
        );
    }

    public function testDerivesOneVisitorPerIpAndUserAgentAndForwardsThem(): void
    {
        $client = $this->client();
        $visitor = ['website_id' => self::WEBSITE_ID, 'ip_address' => self::IP, 'user_agent' => self::USER_AGENT];

        $client->pageView($visitor + ['url' => 'https://example.test/']);
        $client->track($visitor + ['event' => 'signup']);

        $this->assertSame($this->payload(0)['visitorId'], $this->payload(1)['visitorId']);
        $request = $this->sent[0]['request'];
        $this->assertSame(self::USER_AGENT, $request->getHeaderLine('X-Forwarded-User-Agent'));
        $this->assertSame(self::IP, $request->getHeaderLine('X-Forwarded-For'));
        $this->assertStringStartsWith('entrolytics-php/', $request->getHeaderLine('User-Agent'));
    }

    public function testDerivationMatchesTheOtherServerSdks(): void
    {
        $derive = new \ReflectionMethod(Client::class, 'deriveVisitorId');
        $now = new \DateTimeImmutable('2026-09-24T08:00:00Z');

        // Same inputs as the Next.js and Python SDKs' deriveVisitorId / _derive_visitor_id.
        $this->assertSame(
            'cb36ddfc-3c30-41b4-8d6a-f3928d890509',
            $derive->invoke(new Client('ent_test'), self::WEBSITE_ID, self::IP, self::USER_AGENT, $now),
        );
    }

    public function testSessionDerivationMatchesTheOtherServerSdks(): void
    {
        $derive = new \ReflectionMethod(Client::class, 'deriveSessionId');
        $client = new Client('ent_test');
        $session = fn(string $at): string => $derive->invoke(
            $client,
            self::WEBSITE_ID,
            self::IP,
            self::USER_AGENT,
            new \DateTimeImmutable($at),
        );

        // One 30-minute UTC window: 14:30 up to, but not including, 15:00.
        foreach (['2026-09-24T14:30:00Z', '2026-09-24T14:47:00Z', '2026-09-24T14:59:59Z'] as $at) {
            $this->assertSame('6e75928f-813d-49ee-a4dc-5d2e299d7eb2', $session($at), $at);
        }
        $this->assertSame('f656564a-ee6c-4cc1-8309-0b69c73bfd3d', $session('2026-09-24T15:00:00Z'));
    }

    public function testDerivesOneSessionPerVisitorFromTheEventTime(): void
    {
        $client = $this->client();
        $visitor = ['website_id' => self::WEBSITE_ID, 'ip_address' => self::IP, 'user_agent' => self::USER_AGENT];

        $client->pageView($visitor + ['url' => 'https://example.test/']);
        $client->track($visitor + ['event' => 'signup']);

        $derive = new \ReflectionMethod(Client::class, 'deriveSessionId');
        $first = $this->payload(0);
        $this->assertSame(
            $derive->invoke(new Client('ent_test'), self::WEBSITE_ID, self::IP, self::USER_AGENT, new \DateTimeImmutable($first['timestamp'])),
            $first['sessionId'],
        );
        $this->assertSame($first['sessionId'], $this->payload(1)['sessionId']);
    }

    public function testAnExplicitSessionIdWins(): void
    {
        $client = $this->client();
        $sessionId = '1c9d7e3a-5b2f-4c8d-9e1f-3a4b5c6d7e8f';

        $client->pageView([
            'website_id' => self::WEBSITE_ID,
            'url' => 'https://example.test/',
            'session_id' => $sessionId,
            'ip_address' => self::IP,
            'user_agent' => self::USER_AGENT,
        ]);

        $this->assertSame($sessionId, $this->payload(0)['sessionId']);
    }

    public function testAnExplicitVisitorIdWins(): void
    {
        $client = $this->client();
        $visitorId = '0b8f6a52-3c1d-4e7f-8a9b-1c2d3e4f5a6b';

        $client->track([
            'website_id' => self::WEBSITE_ID,
            'event' => 'signup',
            'visitor_id' => $visitorId,
            'ip_address' => self::IP,
        ]);

        $this->assertSame($visitorId, $this->payload(0)['visitorId']);
    }

    public function testVitalsAndFormEventsCarryTheRequiredEventIdAndTimestamp(): void
    {
        $client = $this->client();

        $client->trackVital(['website_id' => self::WEBSITE_ID, 'metric' => 'LCP', 'value' => 1200.0]);
        $client->trackFormEvent([
            'website_id' => self::WEBSITE_ID,
            'event_type' => 'start',
            'form_id' => 'signup',
            'url_path' => '/signup',
        ]);

        foreach ([0, 1] as $index) {
            $payload = $this->payload($index);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $payload['eventId'] ?? '');
            $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $payload['timestamp'] ?? ''));
        }
    }

    public function testAVitalSendsOnlyTheFieldsTheVitalsEndpointAccepts(): void
    {
        $client = $this->client();

        // The API rates the value itself, so no rating is needed.
        $client->trackVital([
            'website_id' => self::WEBSITE_ID,
            'metric' => 'CLS',
            'value' => 0.12,
            'url' => 'https://example.test/pricing',
            'path' => '/pricing',
        ]);

        $payload = $this->payload(0);
        $keys = array_keys($payload);
        sort($keys);
        $this->assertSame('/api/collect/vitals', $this->sent[0]['request']->getUri()->getPath());
        $this->assertSame(
            ['eventId', 'metricName', 'metricValue', 'path', 'sessionId', 'timestamp', 'url', 'visitorId', 'websiteId'],
            $keys,
        );
        $this->assertSame('CLS', $payload['metricName']);
        $this->assertSame(0.12, $payload['metricValue']);
        $this->assertSame('/pricing', $payload['path']);
    }

    public function testAVitalWithoutAValueIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->client()->trackVital(['website_id' => self::WEBSITE_ID, 'metric' => 'LCP']);
    }

    public function testApiErrorsMapToTheSdksExceptions(): void
    {
        $event = ['website_id' => self::WEBSITE_ID, 'event' => 'signup'];
        $client = $this->client([
            new Response(400, [], '{"error":"Invalid payload"}'),
            new Response(429, ['Retry-After' => '30']),
            new ConnectException('Connection refused', new Request('POST', '/collect')),
        ]);

        try {
            $client->track($event);
            $this->fail('A 400 response must throw');
        } catch (ValidationException $e) {
            $this->assertSame('Invalid payload', $e->getMessage());
        }

        try {
            $client->track($event);
            $this->fail('A 429 response must throw');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
        }

        $this->expectException(NetworkException::class);
        $client->track($event);
    }
}
