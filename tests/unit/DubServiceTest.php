<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\services\DubService;
use craft\elements\Entry;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

class DubServiceTest extends TestCase
{
    /** @var list<RequestInterface> Requests captured from the mock client. */
    private array $sentRequests = [];

    /**
     * Returns a service wired to a Guzzle client that replays the given responses,
     * so nothing in this suite touches the real Dub API.
     *
     * @param list<Response|\Throwable> $responses
     */
    private function service(array $responses = []): DubService
    {
        $this->sentRequests = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sentRequests));

        $service = new DubService();
        $service->setClient(new Client(['base_uri' => 'https://api.dub.co', 'handler' => $stack]));

        return $service;
    }

    /**
     * Calls one of the service's private matching helpers.
     */
    private function invokePrivate(DubService $service, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(DubService::class, $method))->invoke($service, ...$args);
    }

    private function entry(string $uid, int $siteId): Entry
    {
        $entry = $this->createMock(Entry::class);
        $entry->uid = $uid;
        $entry->siteId = $siteId;

        return $entry;
    }

    // URL normalisation
    // -------------------------------------------------------------------------

    #[DataProvider('urlPathProvider')]
    public function testUrlPathNormalisesForMatching(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate($this->service(), 'urlPath', $url));
    }

    /** @return array<string, array{string, string}> */
    public static function urlPathProvider(): array
    {
        return [
            'strips host' => ['https://example.com/news/hello', '/news/hello'],
            'ignores host differences between environments' => ['http://example.test/news/hello', '/news/hello'],
            'trims the trailing slash' => ['https://example.com/news/hello/', '/news/hello'],
            'lowercases' => ['https://example.com/News/Hello', '/news/hello'],
            'drops the query string' => ['https://example.com/news/hello?utm=x', '/news/hello'],
            'drops the fragment' => ['https://example.com/news/hello#top', '/news/hello'],
            'homepage becomes empty' => ['https://example.com/', ''],
            'no path at all' => ['https://example.com', ''],
        ];
    }

    // Candidate matching
    // -------------------------------------------------------------------------

    public function testMatchCandidatesPrefersTheRawPath(): void
    {
        $pathMap = ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]];

        $this->assertSame(
            $pathMap['/venues/pyramid'],
            $this->invokePrivate($this->service(), 'matchCandidates', '/venues/pyramid', $pathMap, []),
        );
    }

    public function testMatchCandidatesFallsBackToAPrefixRewrite(): void
    {
        $pathMap = ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]];
        $rewrites = [['/areas-stages/', '/venues/']];

        $this->assertSame(
            $pathMap['/venues/pyramid'],
            $this->invokePrivate($this->service(), 'matchCandidates', '/areas-stages/pyramid', $pathMap, $rewrites),
        );
    }

    public function testMatchCandidatesTriesEachRewriteInTurn(): void
    {
        $pathMap = ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]];
        $rewrites = [['/stages/', '/nowhere/'], ['/areas-stages/', '/venues/']];

        $this->assertNotEmpty(
            $this->invokePrivate($this->service(), 'matchCandidates', '/areas-stages/pyramid', $pathMap, $rewrites),
        );
    }

    public function testARewriteThatLandsNowhereDoesNotMatch(): void
    {
        $pathMap = ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]];
        $rewrites = [['/areas-stages/', '/places/']];

        $this->assertSame(
            [],
            $this->invokePrivate($this->service(), 'matchCandidates', '/areas-stages/pyramid', $pathMap, $rewrites),
        );
    }

    public function testAPathSharedByTwoSitesReturnsBothCandidates(): void
    {
        $pathMap = ['/news' => [['id' => 1, 'siteId' => 1], ['id' => 2, 'siteId' => 2]]];

        $this->assertCount(
            2,
            $this->invokePrivate($this->service(), 'matchCandidates', '/news', $pathMap, []),
        );
    }

    public function testAnUnknownPathMatchesNothing(): void
    {
        $this->assertSame(
            [],
            $this->invokePrivate($this->service(), 'matchCandidates', '/gone', ['/here' => [['id' => 1]]], []),
        );
    }

    // Pending save state
    // -------------------------------------------------------------------------

    public function testDeletionsAreTrackedPerEntryAndSite(): void
    {
        $service = $this->service();
        $siteOne = $this->entry('abc-123', 1);
        $siteTwo = $this->entry('abc-123', 2);

        $service->scheduleDeletion($siteOne);

        $this->assertTrue($service->isPendingDeletion($siteOne));
        $this->assertFalse($service->isPendingDeletion($siteTwo));
    }

    public function testAnUnscheduledEntryIsNotPendingDeletion(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isPendingDeletion($this->entry('abc-123', 1)));
    }

    // API layer
    // -------------------------------------------------------------------------

    public function testGetDomainsReturnsTheDecodedResponse(): void
    {
        $service = $this->service([
            new Response(200, [], json_encode([['slug' => 'go.example.com']])),
        ]);

        $this->assertSame([['slug' => 'go.example.com']], $service->getDomains());
        $this->assertSame('/domains', $this->sentRequests[0]['request']->getUri()->getPath());
    }

    public function testGetDomainsReturnsAnEmptyArrayOnAnEmptyBody(): void
    {
        $this->assertSame([], $this->service([new Response(200, [], '')])->getDomains());
    }

    public function testGetDomainsDegradesToAnEmptyArrayOnAnApiError(): void
    {
        $service = $this->service([
            new \GuzzleHttp\Exception\ClientException(
                'Unauthorized',
                new Request('GET', '/domains'),
                new Response(401, [], json_encode(['error' => ['message' => 'Invalid API key.']])),
            ),
        ]);

        $this->assertSame([], $service->getDomains());
    }

    public function testGetDomainsDegradesToAnEmptyArrayWhenTheApiIsUnreachable(): void
    {
        $service = $this->service([
            new \GuzzleHttp\Exception\ConnectException('Could not resolve host', new Request('GET', '/domains')),
        ]);

        $this->assertSame([], $service->getDomains());
    }

    public function testGetShortLinkIsNullWithoutAnEntryId(): void
    {
        $this->assertNull($this->service()->getShortLink(null, 1));
    }

    public function testGetClicksIsNullWithoutAnEntryId(): void
    {
        $this->assertNull($this->service()->getClicks(null, 1));
    }

    public function testGetQrUrlIsNullWithoutAnEntryId(): void
    {
        $this->assertNull($this->service()->getQrUrl(null, 1));
    }
}
