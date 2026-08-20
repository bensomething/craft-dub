<?php

namespace bensomething\craftdub\tests\support;

use bensomething\craftdub\services\DubService;

/**
 * A DubService with its three Craft-app seams stubbed out: the API key (normally read from
 * plugin settings) and the two local record helpers (normally Active Record queries).
 *
 * This lets the unit suite exercise the archive and delete fan-outs, which is where the site
 * scoping matters, without booting Craft or touching a database. The HTTP layer is still the
 * real one, driven by the mock handler the test installs, so the assertions are about the
 * requests that actually go out.
 */
class StubDubService extends DubService
{
    /** @var list<int> Site IDs to report as having a recorded link. */
    public array $recordedSiteIds = [];

    /** @var list<array{int, int|null}> Every forgetLinks() call, as [entryId, siteId]. */
    public array $forgotten = [];

    /** @var list<array{int, int, bool}> Every rememberArchived() call, as [entryId, siteId, archived]. */
    public array $archivedWrites = [];

    public ?string $stubApiKey = 'dub_test_key';

    protected function apiKey(): ?string
    {
        return $this->stubApiKey;
    }

    /**
     * @return list<int>
     */
    protected function linkedSiteIds(int $entryId): array
    {
        return $this->recordedSiteIds;
    }

    protected function forgetLinks(int $entryId, ?int $siteId = null): void
    {
        $this->forgotten[] = [$entryId, $siteId];
    }

    protected function rememberArchived(int $entryId, int $siteId, bool $archived): void
    {
        $this->archivedWrites[] = [$entryId, $siteId, $archived];
    }
}
