<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\services\DubService;
use bensomething\craftdub\tests\support\StubDubService;
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

    /**
     * Returns a stubbed service whose record layer reports the given sites as linked, so the
     * archive/delete fan-outs can be driven without a database.
     *
     * @param list<Response|\Throwable> $responses
     * @param list<int> $recordedSiteIds
     */
    private function stub(array $responses = [], array $recordedSiteIds = []): StubDubService
    {
        $this->sentRequests = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sentRequests));

        $service = new StubDubService();
        $service->recordedSiteIds = $recordedSiteIds;
        $service->setClient(new Client(['base_uri' => 'https://api.dub.co', 'handler' => $stack]));

        return $service;
    }

    private function entry(string $uid, int $siteId): Entry
    {
        $entry = $this->createMock(Entry::class);
        $entry->uid = $uid;
        $entry->siteId = $siteId;

        return $entry;
    }

    /**
     * As entry(), but with a canonical id — needed by anything that touches the record layer.
     */
    private function savedEntry(string $uid, int $siteId, int $canonicalId): Entry
    {
        $entry = $this->entry($uid, $siteId);
        $entry->method('getCanonicalId')->willReturn($canonicalId);

        return $entry;
    }

    /**
     * The path of each request the mock client received, in order.
     *
     * @return list<string>
     */
    private function sentPaths(): array
    {
        return array_map(
            static fn(array $sent) => $sent['request']->getUri()->getPath(),
            $this->sentRequests,
        );
    }

    /**
     * The decoded JSON body of one captured request.
     *
     * @return array<string, mixed>
     */
    private function sentBody(int $index): array
    {
        return json_decode((string)$this->sentRequests[$index]['request']->getBody(), true) ?: [];
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

    #[DataProvider('urlHostProvider')]
    public function testUrlHostNormalisesForMatching(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate($this->service(), 'urlHost', $url));
    }

    /** @return array<string, array{string, string}> */
    public static function urlHostProvider(): array
    {
        return [
            'plain host' => ['https://example.com/news', 'example.com'],
            'drops www' => ['https://www.example.com/news', 'example.com'],
            'lowercases' => ['https://Example.COM/news', 'example.com'],
            'keeps a subdomain that is not www' => ['https://fr.example.com/news', 'fr.example.com'],
            'keeps a host that merely starts with www' => ['https://wwwfoo.example.com/news', 'wwwfoo.example.com'],
            'no host at all' => ['/news/hello', ''],
        ];
    }

    #[DataProvider('absoluteUrlProvider')]
    public function testOnlyAnAbsoluteUrlIsWorthSendingToDub(string $url, bool $expected): void
    {
        $this->assertSame($expected, $this->invokePrivate($this->service(), 'isAbsoluteUrl', $url));
    }

    /** @return array<string, array{string, bool}> */
    public static function absoluteUrlProvider(): array
    {
        return [
            'a full url' => ['https://example.com/news/hello', true],
            'http is fine too' => ['http://example.com/news/hello', true],
            'the bare homepage' => ['https://example.com/', true],
            // What Craft returns outside a web request when the site's baseUrl can't be
            // resolved — an undefined environment variable, usually. Sending it can only 4xx.
            'a bare path' => ['/test/projects/test2', false],
            'a path with no leading slash' => ['test/projects/test2', false],
            'protocol relative, with no scheme to redirect on' => ['//example.com/news', false],
            'empty' => ['', false],
        ];
    }

    // Candidate matching
    // -------------------------------------------------------------------------

    public function testMatchCandidatesPrefersTheHostAndPathTogether(): void
    {
        $maps = [
            'url' => [
                'example.com/news' => [['id' => 1, 'siteId' => 1]],
                'example.fr/news' => [['id' => 2, 'siteId' => 2]],
            ],
            'path' => ['/news' => [['id' => 1, 'siteId' => 1], ['id' => 2, 'siteId' => 2]]],
        ];

        // Matching on path alone reported this pair as ambiguous and adopted neither.
        $this->assertSame(
            $maps['url']['example.fr/news'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.fr/news', $maps, []),
        );
    }

    public function testMatchCandidatesIgnoresWwwWhenComparingHosts(): void
    {
        $maps = ['url' => ['example.com/news' => [['id' => 1, 'siteId' => 1]]], 'path' => []];

        $this->assertSame(
            $maps['url']['example.com/news'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://www.example.com/news', $maps, []),
        );
    }

    public function testAHomepageIsMatchedByHostAlone(): void
    {
        // A site root has no path, so it only ever lives in the url map, keyed by bare host.
        $maps = [
            'url' => [
                'example.com' => [['id' => 1, 'siteId' => 1]],
                'example.fr' => [['id' => 2, 'siteId' => 2]],
            ],
            'path' => [],
        ];

        $this->assertSame(
            $maps['url']['example.fr'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.fr/', $maps, []),
        );
    }

    public function testMatchCandidatesFallsBackToThePathWhenTheHostIsUnknown(): void
    {
        // A link recorded against a staging hostname still matches the entry it points at.
        $maps = ['url' => ['example.com/news' => [['id' => 1, 'siteId' => 1]]], 'path' => ['/news' => [['id' => 1, 'siteId' => 1]]]];

        $this->assertSame(
            $maps['path']['/news'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://staging.example.test/news', $maps, []),
        );
    }

    public function testMatchCandidatesPrefersTheRawPath(): void
    {
        $maps = ['url' => [], 'path' => ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]]];

        $this->assertSame(
            $maps['path']['/venues/pyramid'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/venues/pyramid', $maps, []),
        );
    }

    public function testMatchCandidatesFallsBackToAPrefixRewrite(): void
    {
        $maps = ['url' => [], 'path' => ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]]];
        $rewrites = [['/areas-stages/', '/venues/']];

        $this->assertSame(
            $maps['path']['/venues/pyramid'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/areas-stages/pyramid', $maps, $rewrites),
        );
    }

    public function testARewriteResolvesToTheRightSiteWhenTwoShareThePath(): void
    {
        $maps = [
            'url' => [
                'example.com/venues/pyramid' => [['id' => 1, 'siteId' => 1]],
                'example.fr/venues/pyramid' => [['id' => 2, 'siteId' => 2]],
            ],
            'path' => ['/venues/pyramid' => [['id' => 1, 'siteId' => 1], ['id' => 2, 'siteId' => 2]]],
        ];
        $rewrites = [['/areas-stages/', '/venues/']];

        $this->assertSame(
            $maps['url']['example.fr/venues/pyramid'],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.fr/areas-stages/pyramid', $maps, $rewrites),
        );
    }

    public function testMatchCandidatesTriesEachRewriteInTurn(): void
    {
        $maps = ['url' => [], 'path' => ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]]];
        $rewrites = [['/stages/', '/nowhere/'], ['/areas-stages/', '/venues/']];

        $this->assertNotEmpty(
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/areas-stages/pyramid', $maps, $rewrites),
        );
    }

    public function testARewriteThatLandsNowhereDoesNotMatch(): void
    {
        $maps = ['url' => [], 'path' => ['/venues/pyramid' => [['id' => 1, 'siteId' => 1]]]];
        $rewrites = [['/areas-stages/', '/places/']];

        $this->assertSame(
            [],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/areas-stages/pyramid', $maps, $rewrites),
        );
    }

    public function testAPathSharedByTwoSitesOnOneHostStillReturnsBothCandidates(): void
    {
        // Nothing distinguishes these, so adoption should still decline to guess.
        $maps = ['url' => [], 'path' => ['/news' => [['id' => 1, 'siteId' => 1], ['id' => 2, 'siteId' => 2]]]];

        $this->assertCount(
            2,
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/news', $maps, []),
        );
    }

    public function testAnUnknownPathMatchesNothing(): void
    {
        $maps = ['url' => [], 'path' => ['/here' => [['id' => 1]]]];

        $this->assertSame(
            [],
            $this->invokePrivate($this->service(), 'matchCandidates', 'https://example.com/gone', $maps, []),
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

    // Link teardown
    // -------------------------------------------------------------------------

    public function testClearingOneSitesSlugOnlyDeletesThatSitesLink(): void
    {
        // Regression: both the API call and the local delete were keyed on entryId alone, so
        // clearing the slug on the English site destroyed the German and French links too.
        $service = $this->stub([new Response(200, [], '{}')], [1, 2, 3]);

        $service->deleteLinkForSite($this->savedEntry('abc-123', 1, 55), 1);

        $this->assertSame(['/links/ext_abc-123_1'], $this->sentPaths());
        $this->assertSame('DELETE', $this->sentRequests[0]['request']->getMethod());
        $this->assertSame([[55, 1]], $service->forgotten);
    }

    public function testDeletingAnEntryDeletesEveryRecordedSitesLink(): void
    {
        $service = $this->stub(array_fill(0, 3, new Response(200, [], '{}')), [1, 2, 3]);

        $service->deleteLink($this->savedEntry('abc-123', 1, 55));

        $this->assertSame(
            ['/links/ext_abc-123_1', '/links/ext_abc-123_2', '/links/ext_abc-123_3'],
            $this->sentPaths(),
        );
        $this->assertSame([[55, null]], $service->forgotten);
    }

    public function testDeletingASiteWithNoRecordedLinkMakesNoApiCall(): void
    {
        $service = $this->stub([], [2]);

        $service->deleteLinkForSite($this->savedEntry('abc-123', 1, 55), 1);

        $this->assertSame([], $this->sentPaths());
    }

    public function testLocalRecordsAreForgottenEvenWithoutAnApiKey(): void
    {
        $service = $this->stub([], [1]);
        $service->stubApiKey = null;

        $service->deleteLink($this->savedEntry('abc-123', 1, 55));

        $this->assertSame([], $this->sentPaths());
        $this->assertSame([[55, null]], $service->forgotten);
    }

    public function testTrashingAnEntryArchivesEverySitesLinkRatherThanDeletingIt(): void
    {
        // Regression: the trash branch was dead code (Craft sets dateDeleted before firing
        // afterDelete), so a reversible trash issued a real DELETE to Dub.
        $service = $this->stub(array_fill(0, 2, new Response(200, [], '{}')), [1, 2]);

        $service->deactivateLinks($this->savedEntry('abc-123', 1, 55));

        $this->assertSame(['/links/ext_abc-123_1', '/links/ext_abc-123_2'], $this->sentPaths());
        $this->assertSame('PATCH', $this->sentRequests[0]['request']->getMethod());
        $this->assertTrue($this->sentBody(0)['archived']);
        $this->assertSame([], $service->forgotten);
    }

    public function testRestoringAnEntryUnarchivesTheLinksForTheSitesItCameBackLiveOn(): void
    {
        $service = $this->stub(array_fill(0, 2, new Response(200, [], '{}')), [1, 2]);

        $service->restoreLinks($this->savedEntry('abc-123', 1, 55), [1, 2]);

        $this->assertSame(['/links/ext_abc-123_1', '/links/ext_abc-123_2'], $this->sentPaths());
        $this->assertFalse($this->sentBody(0)['archived']);
        $this->assertFalse($this->sentBody(1)['archived']);
    }

    public function testRestoringLeavesASiteTheEntryIsStillDisabledOnArchived(): void
    {
        $service = $this->stub([new Response(200, [], '{}')], [1, 2]);

        $service->restoreLinks($this->savedEntry('abc-123', 1, 55), [1]);

        $this->assertSame(['/links/ext_abc-123_1'], $this->sentPaths());
    }

    public function testDeactivatingOnlyTouchesTheEntrysOwnSite(): void
    {
        $service = $this->stub([new Response(200, [], '{}')], [1, 2]);

        $service->deactivateLink($this->savedEntry('abc-123', 2, 55));

        $this->assertSame(['/links/ext_abc-123_2'], $this->sentPaths());
    }

    public function testAHardDeleteStillDeletesLinksAfterTheRowsHaveCascadedAway(): void
    {
        // Craft removes the elements row before calling afterDelete(), and the links table
        // cascades off it — so by the time the handler runs there is nothing left to read.
        // Without the ids captured in beforeDelete() no DELETE is sent and the links are
        // stranded at Dub.
        $service = $this->stub([new Response(200, [], '{}'), new Response(200, [], '{}')], [1, 2]);
        $entry = $this->savedEntry('abc-123', 1, 55);

        $service->rememberLinksForDeletion($entry);
        $service->recordedSiteIds = [];

        $service->deleteLink($entry);

        $this->assertSame(['/links/ext_abc-123_1', '/links/ext_abc-123_2'], $this->sentPaths());
    }

    public function testACapturedListIsNotReusedByASecondDelete(): void
    {
        $service = $this->stub([new Response(200, [], '{}')], [1]);
        $entry = $this->savedEntry('abc-123', 1, 55);

        $service->rememberLinksForDeletion($entry);
        $service->recordedSiteIds = [];
        $service->deleteLink($entry);
        $service->deleteLink($entry);

        // The second call finds nothing recorded and nothing captured, so it stays silent.
        $this->assertSame(['/links/ext_abc-123_1'], $this->sentPaths());
    }

    public function testDeactivatingAnEntryWithNoRecordedLinkMakesNoApiCall(): void
    {
        // Otherwise every save of every non-live entry costs a blocking round-trip that can
        // only 404, since `sections` defaults to all of them.
        $service = $this->stub([], []);

        $service->deactivateLink($this->savedEntry('abc-123', 1, 55));

        $this->assertSame([], $this->sentPaths());
    }

    public function testArchivingRecordsTheFlagAgainstEachSiteItTouched(): void
    {
        // The next save reads this back: without it the link stays archived at Dub, because
        // an otherwise-unchanged save now skips the PATCH that would carry archived: false.
        $service = $this->stub([new Response(200, [], '{}'), new Response(200, [], '{}')], [1, 2]);

        $service->deactivateLinks($this->savedEntry('abc-123', 1, 55));

        $this->assertSame([[55, 1, true], [55, 2, true]], $service->archivedWrites);
    }

    public function testRestoringClearsTheFlagOnTheSitesItBroughtBack(): void
    {
        $service = $this->stub([new Response(200, [], '{}')], [1, 2]);

        $service->restoreLinks($this->savedEntry('abc-123', 1, 55), [1]);

        $this->assertSame([[55, 1, false]], $service->archivedWrites);
    }

    #[DataProvider('linkCurrencyProvider')]
    public function testAnUnchangedSaveIsRecognised(
        ?string $recordedUrl,
        ?string $recordedShortLink,
        bool $recordedArchived,
        string $url,
        ?string $customKey,
        ?string $domain,
        bool $expected,
    ): void {
        $current = $this->invokePrivate(
            $this->service(),
            'linkIsCurrent',
            $recordedUrl,
            $recordedShortLink,
            $recordedArchived,
            $url,
            $customKey,
            $domain,
        );

        $this->assertSame($expected, $current);
    }

    /** @return array<string, array{?string, ?string, bool, string, ?string, ?string, bool}> */
    public static function linkCurrencyProvider(): array
    {
        $short = 'https://go.example.com/launch';

        return [
            'nothing moved' => ['https://example.com/posts/a', $short, false, 'https://example.com/posts/a', 'launch', 'go.example.com', true],
            'the slug changed, so the destination did' => ['https://example.com/posts/a', $short, false, 'https://example.com/posts/b', 'launch', 'go.example.com', false],
            'the editor typed a different key' => ['https://example.com/posts/a', $short, false, 'https://example.com/posts/a', 'launch-day', 'go.example.com', false],
            'the domain setting changed' => ['https://example.com/posts/a', $short, false, 'https://example.com/posts/a', 'launch', 'go2.example.com', false],
            // The PATCH is what carries archived: false, so it has to go out.
            'the link is archived and the entry is live again' => ['https://example.com/posts/a', $short, true, 'https://example.com/posts/a', 'launch', 'go.example.com', false],
            // Written before the state columns existed: unknown, not unchanged.
            'a row with no recorded destination' => [null, $short, false, 'https://example.com/posts/a', 'launch', 'go.example.com', false],
            // Neither is sent when null, so Dub keeps what it has and there is nothing to compare.
            'no custom key and no domain configured' => ['https://example.com/posts/a', $short, false, 'https://example.com/posts/a', null, null, true],
            'an unreadable short link with no key to check' => ['https://example.com/posts/a', null, false, 'https://example.com/posts/a', null, null, true],
            'an unreadable short link with a key to check' => ['https://example.com/posts/a', null, false, 'https://example.com/posts/a', 'launch', null, false],
        ];
    }

    // Check classification
    // -------------------------------------------------------------------------

    #[DataProvider('classifyProvider')]
    public function testARecordedLinkIsClassifiedAgainstWhatDubReturns(
        ?array $remote,
        bool $hadError,
        ?string $recordedShortLink,
        ?string $recordedUrl,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->invokePrivate(
            $this->service(),
            'classifyRemote',
            $remote,
            $hadError,
            $recordedShortLink,
            $recordedUrl,
        ));
    }

    /** @return array<string, array{?array<string, mixed>, bool, ?string, ?string, string}> */
    public static function classifyProvider(): array
    {
        $short = 'https://bms.so/launch';
        $url = 'https://example.com/posts/a';
        $remote = ['shortLink' => $short, 'url' => $url];

        return [
            'still there and unchanged' => [$remote, false, $short, $url, 'ok'],
            // makeRequest returns null for a 404 without setting lastError, which is the only
            // thing separating "deleted at Dub" from "the API call failed".
            'deleted at Dub' => [null, false, $short, $url, 'missing'],
            'the API call failed' => [null, true, $short, $url, 'failed'],
            'an error outranks a body' => [$remote, true, $short, $url, 'failed'],
            'renamed at Dub' => [['shortLink' => 'https://bms.so/other', 'url' => $url], false, $short, $url, 'drifted'],
            're-pointed at Dub' => [['shortLink' => $short, 'url' => 'https://example.com/posts/b'], false, $short, $url, 'drifted'],
            // Predates the state columns: nothing recorded to compare, so nothing is wrong.
            'no recorded destination' => [$remote, false, $short, null, 'ok'],
            'no recorded short link' => [$remote, false, null, $url, 'ok'],
        ];
    }
}
