<?php

declare(strict_types=1);

namespace EntrolyticsNG\Tests;

use Entrolytics\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

// The package's classes are not reachable through its PSR-4 autoload map.
require_once __DIR__ . '/../src/Exception/EntrolyticsException.php';
require_once __DIR__ . '/../src/Client.php';

final class ClientTest extends TestCase
{
    private const WEBSITE_ID = '6f1c7c8e-5d3b-4f5a-9d1e-2b6f0c9a7e41';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh) Safari/605.1.15';
    private const IP = '203.0.113.7';

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private function client(): Client
    {
        $client = new Client('ent_test');
        $http = new \ReflectionProperty(Client::class, 'http');
        $config = $http->getValue($client)->getConfig();
        $config['handler'] = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(202))));
        $config['handler']->push(Middleware::history($this->sent));
        $http->setValue($client, new HttpClient($config));

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(int $index): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true);
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

        $client->trackVital(['website_id' => self::WEBSITE_ID, 'metric' => 'LCP', 'value' => 1200.0, 'rating' => 'good']);
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
}
