<?php

namespace bensomething\craftdub\services;

use bensomething\craftdub\Plugin;
use bensomething\craftdub\records\DubLink;
use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Throwable;
use yii\base\Component;

/**
 * @phpstan-type EntryCandidate array{id: int|null, uid: string|null, siteId: int, title: string|null}
 * @phpstan-type PathMap array<string, list<EntryCandidate>>
 * @phpstan-type Rewrites list<array{0: string, 1: string}>
 * @phpstan-type AdoptSummary array{adopted: int, skipped: int, ambiguous: int, unmatched: list<string>, failed: int, error: string|null}
 */
class DubService extends Component
{
    private const API_BASE = 'https://api.dub.co';
    private const CLICKS_CACHE_TTL = 300;
    private const WORKSPACE_CACHE_KEY = 'dub_workspace_id';

    private ?string $lastError = null;
    private ?Client $client = null;

    /** @var array<string,array<string,mixed>> Cached API results awaiting commit, keyed by entry uid + site. */
    private array $pendingLinks = [];

    /** @var array<string,Entry> Entries scheduled for deletion, keyed by entry uid + site. */
    private array $pendingDeletes = [];

    /**
     * A stable key for the pending-state maps. Uses the entry uid (assigned before
     * EVENT_BEFORE_SAVE, unlike the numeric id on new entries) plus the site, so nested
     * resave/multi-site saves each track their own state instead of clobbering a singleton.
     */
    private function pendingKey(Entry $entry): string
    {
        return $entry->uid . '_' . $entry->siteId;
    }

    /**
     * Schedules a link deletion to be committed in EVENT_AFTER_SAVE.
     */
    public function scheduleDeletion(Entry $entry): void
    {
        $this->pendingDeletes[$this->pendingKey($entry)] = $entry;
    }

    /**
     * Deletes the link from Dub and removes the local DB record.
     */
    public function commitDeletion(Entry $entry): void
    {
        $key = $this->pendingKey($entry);
        if (isset($this->pendingDeletes[$key])) {
            $pending = $this->pendingDeletes[$key];
            $this->deleteLinkForSite($pending, $pending->siteId);
            unset($this->pendingDeletes[$key]);
        }
    }

    public function isPendingDeletion(Entry $entry): bool
    {
        return isset($this->pendingDeletes[$this->pendingKey($entry)]);
    }

    /**
     * Makes the Dub API call and caches the result. Returns an error string on failure.
     * Call commitLink() in EVENT_AFTER_SAVE to persist the result to the DB.
     */
    public function prepareLink(Entry $entry, ?string $customKey = null): ?string
    {
        $url = $this->resolveDestinationUrl($entry);
        if (!$url) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!Craft::parseEnv($settings->apiKey)) {
            return null;
        }

        $domain = Craft::parseEnv($settings->domain);
        $externalId = $entry->uid . '_' . $entry->siteId;

        $optionals = [];
        if ($customKey) {
            $optionals['key'] = $customKey;
        }
        if ($domain) {
            $optionals['domain'] = $domain;
        }

        // Try to update existing link; create if not found
        $result = $this->makeRequest('PATCH', '/links/ext_' . $externalId, array_merge(['url' => $url, 'archived' => false], $optionals));

        if ($result === null) {
            if ($this->lastError) {
                return $this->lastError;
            }

            $createBody = array_merge(['url' => $url, 'externalId' => $externalId], $optionals);

            $result = $this->makeRequest('POST', '/links', $createBody);

            if ($result === null) {
                return $this->lastError;
            }
        }

        $this->pendingLinks[$this->pendingKey($entry)] = $result;

        return null;
    }

    /**
     * Saves the cached API result to the DB. Call after a successful entry save.
     */
    public function commitLink(Entry $entry): void
    {
        $key = $this->pendingKey($entry);
        $link = $this->pendingLinks[$key] ?? null;

        if ($link && isset($link['shortLink'])) {
            $this->rememberWorkspaceId($link);
            $this->saveLink($entry->id, $entry->siteId, $link['id'] ?? null, $link['shortLink']);
        }

        unset($this->pendingLinks[$key]);
    }

    /**
     * Resolves the entry's final destination URL.
     *
     * At EVENT_BEFORE_SAVE the entry's own `uri` isn't settled yet — it's null for brand-new
     * entries and stale when the slug changed in the same save (Craft regenerates it during
     * validation, which runs after before-save). Running setUniqueUri on a throwaway clone
     * computes the URI Craft is about to assign without touching the real entry or its slug,
     * so the Dub destination is correct on first save and on slug changes. Falls back to the
     * entry's current URL if resolution isn't possible.
     */
    private function resolveDestinationUrl(Entry $entry): ?string
    {
        try {
            $clone = clone $entry;
            ElementHelper::setUniqueUri($clone);
            return $clone->getUrl() ?? $entry->getUrl();
        } catch (Throwable $e) {
            return $entry->getUrl();
        }
    }

    /**
     * Archives the entry's link for its own site. Used when an entry stops being live.
     */
    public function deactivateLink(Entry $entry): void
    {
        $this->setArchived($entry, [$entry->siteId], true);
    }

    /**
     * Archives every site's link for the entry. Used when an entry is trashed — that's
     * reversible, so the links are archived rather than deleted and restoreLinks() brings
     * them back if the entry does.
     */
    public function deactivateLinks(Entry $entry): void
    {
        $this->setArchived($entry, null, true);
    }

    /**
     * Un-archives the entry's links for the given sites, reversing deactivateLinks().
     *
     * The caller passes the sites explicitly because a restored entry keeps whatever enabled
     * state each site had when it was trashed — the ones it comes back disabled on should
     * stay archived.
     *
     * @param list<int> $siteIds
     */
    public function restoreLinks(Entry $entry, array $siteIds): void
    {
        $this->setArchived($entry, $siteIds, false);
    }

    /**
     * Deletes the entry's link for one site, from Dub and locally. This is the cleared-slug
     * path, so it must stay scoped: the other sites' links are separate links.
     */
    public function deleteLinkForSite(Entry $entry, int $siteId): void
    {
        $this->removeLinks($entry, $siteId);
    }

    /**
     * Deletes every site's link for the entry, from Dub and locally. Only correct for a hard
     * delete — see the EVENT_AFTER_DELETE handler.
     */
    public function deleteLink(Entry $entry): void
    {
        $this->removeLinks($entry, null);
    }

    /**
     * Flips the archived flag on the entry's links, for the given sites or (with a null
     * $siteIds) all of them. Sites with no recorded link are skipped rather than PATCHed
     * blindly: the call would only 404, and with the default `sections` setting that would
     * mean an HTTP round-trip on every save of every non-live entry on the site.
     *
     * @param list<int>|null $siteIds
     */
    private function setArchived(Entry $entry, ?array $siteIds, bool $archived): void
    {
        $entryId = $entry->getCanonicalId();
        if (!$entryId || !$this->apiKey()) {
            return;
        }

        foreach ($this->targetSiteIds($entryId, $siteIds) as $target) {
            $this->makeRequest('PATCH', '/links/ext_' . $entry->uid . '_' . $target, ['archived' => $archived]);
        }
    }

    /**
     * Deletes the entry's links, for one site or (with a null $siteId) all of them. The local
     * records go regardless of whether the API calls could be made, so an unconfigured or
     * failing API key can't leave rows pointing at links the CP will keep rendering.
     */
    private function removeLinks(Entry $entry, ?int $siteId): void
    {
        $entryId = $entry->getCanonicalId();
        if (!$entryId) {
            return;
        }

        if ($this->apiKey()) {
            foreach ($this->targetSiteIds($entryId, $siteId === null ? null : [$siteId]) as $target) {
                $this->makeRequest('DELETE', '/links/ext_' . $entry->uid . '_' . $target);
            }
        }

        $this->forgetLinks($entryId, $siteId);
    }

    /**
     * The recorded site IDs a fan-out should act on: all of them, or the intersection with
     * the ones requested.
     *
     * @param list<int>|null $siteIds
     * @return list<int>
     */
    private function targetSiteIds(int $entryId, ?array $siteIds): array
    {
        $linked = $this->linkedSiteIds($entryId);

        return $siteIds === null ? $linked : array_values(array_intersect($linked, $siteIds));
    }

    /**
     * @return list<array{slug?: string}> The workspace's domains as returned by Dub.
     */
    public function getDomains(): array
    {
        $result = $this->makeRequest('GET', '/domains');
        return $result ?? [];
    }

    public function getShortLink(?int $entryId, int $siteId): ?string
    {
        if (!$entryId) {
            return null;
        }
        return $this->findRecord($entryId, $siteId)?->shortLink;
    }

    public function getWorkspaceId(): ?string
    {
        $cached = Craft::$app->cache->get(self::WORKSPACE_CACHE_KEY) ?: null;
        if ($cached) {
            return $cached;
        }

        // Self-heal after a cache flush: the workspace id is normally cached on save/adopt,
        // but a cleared cache would otherwise hide the "View in Dub" link until the next save.
        // Derive it once from any known link and re-cache it.
        $settings = Plugin::getInstance()->getSettings();
        if (!Craft::parseEnv($settings->apiKey)) {
            return null;
        }

        $record = DubLink::find()->where(['not', ['dubLinkId' => null]])->one();
        if (!$record instanceof DubLink) {
            return null;
        }

        $link = $this->makeRequest('GET', '/links/info', [], ['linkId' => $record->dubLinkId]);
        return $this->rememberWorkspaceId($link);
    }

    /**
     * Caches the workspace id from an API link payload (if present) and returns it.
     *
     * @param array<string,mixed>|null $link
     */
    private function rememberWorkspaceId(?array $link): ?string
    {
        if (is_array($link) && isset($link['workspaceId'])) {
            Craft::$app->cache->set(self::WORKSPACE_CACHE_KEY, $link['workspaceId']);
            return $link['workspaceId'];
        }
        return null;
    }

    /**
     * Returns the URL of Dub's QR code image for an entry's short link, or null if it has none.
     * Dub's /qr endpoint is unauthenticated and renders a PNG for the given short link.
     */
    public function getQrUrl(?int $entryId, int $siteId): ?string
    {
        $shortLink = $this->getShortLink($entryId, $siteId);
        if (!$shortLink) {
            return null;
        }

        // Always hide the centre logo; the logo is a Dub-side concern we don't expose.
        // Blank style cells are omitted, so Dub falls back to its own defaults for them.
        $query = array_merge(
            ['url' => $shortLink, 'hideLogo' => 'true'],
            Plugin::getInstance()->getSettings()->getQrStyle(),
        );

        return self::API_BASE . '/qr?' . http_build_query($query);
    }

    /**
     * Returns the click count for an entry's short link, or null if there's no link or the
     * count can't be fetched. Cached briefly so rendering the entry sidebar doesn't hit the
     * Dub API on every page load.
     */
    public function getClicks(?int $entryId, int $siteId): ?int
    {
        if (!$entryId) {
            return null;
        }

        $record = $this->findRecord($entryId, $siteId);
        if (!$record || !$record->dubLinkId) {
            return null;
        }

        $cacheKey = 'dub_clicks_' . $record->dubLinkId;
        $cached = Craft::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!Craft::parseEnv($settings->apiKey)) {
            return null;
        }

        $link = $this->makeRequest('GET', '/links/info', [], ['linkId' => $record->dubLinkId]);
        $this->rememberWorkspaceId($link);
        if (!is_array($link) || !isset($link['clicks'])) {
            return null;
        }

        $clicks = (int)$link['clicks'];
        Craft::$app->cache->set($cacheKey, $clicks, self::CLICKS_CACHE_TTL);
        return $clicks;
    }

    /**
     * Adopts pre-existing Dub links into the plugin.
     *
     * Paginates the whole workspace once, matches each link to a Craft entry by the
     * path of its destination URL (path-only, so it survives host differences between
     * environments), stamps the entry's externalId onto the link so the plugin can
     * manage it going forward, and records it locally.
     *
     * Intended for one-time onboarding via the `dub/adopt` console command — Dub has
     * no "look up a link by destination URL" endpoint, so we map from the Craft side.
     *
     * @param bool $dryRun When true, reports what would happen without touching the API or DB.
     * @param Rewrites $rewrites Prefix [from, to] pairs applied to a link's
     *   destination path as a fallback when the raw path matches no entry (e.g. ['/areas-stages/', '/venues/']).
     * @param callable(string, string): void|null $onResult Invoked per link as ($status, $message); $status is one
     *   of: adopted, skipped, ambiguous, unmatched, error.
     * @return AdoptSummary
     */
    public function adoptLinks(bool $dryRun = false, array $rewrites = [], ?callable $onResult = null): array
    {
        $summary = ['adopted' => 0, 'skipped' => 0, 'ambiguous' => 0, 'unmatched' => [], 'failed' => 0, 'error' => null];

        $settings = Plugin::getInstance()->getSettings();
        if (!Craft::parseEnv($settings->apiKey)) {
            $summary['error'] = 'No Dub API key configured.';
            return $summary;
        }
        $domain = Craft::parseEnv($settings->domain);

        // Build a path => [entry candidates] map across every site. Keying by path (not
        // full URL) keeps a match ambiguous only when two sites genuinely share a path.
        $pathMap = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            foreach (Entry::find()->siteId($site->id)->status(null)->each() as $entry) {
                $url = $entry->getUrl();
                if (!$url) {
                    continue;
                }
                $path = $this->urlPath($url);
                if ($path !== '') {
                    $pathMap[$path][] = ['id' => $entry->id, 'uid' => $entry->uid, 'siteId' => $site->id, 'title' => $entry->title];
                }
            }
        }

        // Walk the entire workspace, page by page.
        $page = 1;
        $pageSize = 100;
        do {
            $query = ['page' => $page, 'pageSize' => $pageSize];
            if ($domain) {
                $query['domain'] = $domain;
            }

            $links = $this->makeRequest('GET', '/links', [], $query);
            if ($links === null && $this->lastError) {
                $summary['error'] = $this->lastError;
                return $summary;
            }
            if (!is_array($links) || empty($links)) {
                break;
            }

            foreach ($links as $link) {
                $this->adoptOne($link, $pathMap, $rewrites, $dryRun, $summary, $onResult);
            }

            $page++;
        } while (count($links) === $pageSize);

        return $summary;
    }

    /**
     * Matches a single Dub link to an entry and adopts it. Mutates $summary in place.
     *
     * @param array<string,mixed> $link
     * @param PathMap $pathMap
     * @param Rewrites $rewrites
     * @param AdoptSummary $summary
     * @param callable(string, string): void|null $onResult
     * @param-out AdoptSummary $summary
     */
    private function adoptOne(array $link, array $pathMap, array $rewrites, bool $dryRun, array &$summary, ?callable $onResult): void
    {
        $dubId = $link['id'] ?? null;
        $shortLink = $link['shortLink'] ?? null;
        $destUrl = $link['url'] ?? null;
        if (!$dubId || !$shortLink || !$destUrl) {
            return;
        }

        $candidates = $this->matchCandidates($this->urlPath($destUrl), $pathMap, $rewrites);

        if (empty($candidates)) {
            $summary['unmatched'][] = $shortLink . ' → ' . $destUrl;
            $onResult && $onResult('unmatched', $shortLink . ' → ' . $destUrl);
            return;
        }

        // A path shared by more than one site can't be resolved safely — skip it.
        if (count($candidates) > 1) {
            $summary['ambiguous']++;
            $onResult && $onResult('ambiguous', $shortLink . ' → ' . $destUrl . ' (' . count($candidates) . ' entries share this path)');
            return;
        }

        $entry = $candidates[0];
        $label = $entry['title'] . ' [' . $entry['siteId'] . ']';

        if ($this->findRecord($entry['id'], $entry['siteId']) !== null) {
            $summary['skipped']++;
            $onResult && $onResult('skipped', $label . ' — already linked');
            return;
        }

        if ($dryRun) {
            $summary['adopted']++;
            $onResult && $onResult('adopted', $label . ' → ' . $shortLink);
            return;
        }

        // Stamp our externalId so subsequent entry saves target the link via PATCH.
        if (empty($link['externalId'])) {
            $claimed = $this->makeRequest('PATCH', '/links/' . $dubId, ['externalId' => $entry['uid'] . '_' . $entry['siteId']]);
            if ($claimed === null && $this->lastError) {
                $summary['failed']++;
                $onResult && $onResult('error', $label . ' — ' . $this->lastError);
                return;
            }
        }

        $this->rememberWorkspaceId($link);

        $this->saveLink($entry['id'], $entry['siteId'], $dubId, $shortLink);
        $summary['adopted']++;
        $onResult && $onResult('adopted', $label . ' → ' . $shortLink);
    }

    /**
     * Returns the entry candidates for a destination path, trying the raw path first and
     * falling back to each prefix rewrite (e.g. /areas-stages/ => /venues/) in turn.
     *
     * @param PathMap $pathMap
     * @param Rewrites $rewrites
     * @return list<EntryCandidate>
     */
    private function matchCandidates(string $path, array $pathMap, array $rewrites): array
    {
        if (!empty($pathMap[$path])) {
            return $pathMap[$path];
        }

        foreach ($rewrites as [$from, $to]) {
            if (str_starts_with($path, $from)) {
                $rewritten = rtrim($to . substr($path, strlen($from)), '/');
                if (!empty($pathMap[$rewritten])) {
                    return $pathMap[$rewritten];
                }
            }
        }

        return [];
    }

    /**
     * Normalises a URL to its lowercased, trailing-slash-trimmed path for matching.
     */
    private function urlPath(string $url): string
    {
        return rtrim(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '/');
    }

    private function findRecord(int $entryId, int $siteId): ?DubLink
    {
        return DubLink::findOne(['entryId' => $entryId, 'siteId' => $siteId]);
    }

    /**
     * The resolved API key, or null when the plugin isn't configured.
     *
     * This and the two record helpers below are the service's only Craft-app dependencies on
     * the archive/delete paths. They're protected so the unit suite can stub them: the site
     * scoping in those fan-outs is where the bugs were, and it's worth testing without a
     * database behind it.
     */
    protected function apiKey(): ?string
    {
        $key = Craft::parseEnv(Plugin::getInstance()->getSettings()->apiKey);

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * The site IDs with a recorded link for this entry.
     *
     * @return list<int>
     */
    protected function linkedSiteIds(int $entryId): array
    {
        $siteIds = [];

        /** @var DubLink $record */
        foreach (DubLink::findAll(['entryId' => $entryId]) as $record) {
            $siteIds[] = (int)$record->siteId;
        }

        return $siteIds;
    }

    /**
     * Removes the local link records for an entry, scoped to one site when given.
     */
    protected function forgetLinks(int $entryId, ?int $siteId = null): void
    {
        $condition = ['entryId' => $entryId];
        if ($siteId !== null) {
            $condition['siteId'] = $siteId;
        }

        DubLink::deleteAll($condition);
    }

    private function saveLink(int $entryId, int $siteId, ?string $dubLinkId, string $shortLink): void
    {
        $record = $this->findRecord($entryId, $siteId) ?? new DubLink();
        $record->entryId = $entryId;
        $record->siteId = $siteId;
        $record->dubLinkId = $dubLinkId;
        $record->shortLink = $shortLink;
        if (!$record->save()) {
            Craft::error('Dub: saveLink failed – ' . json_encode($record->getErrors()), __METHOD__);
        }
    }

    /**
     * Overrides the HTTP client used for Dub API calls. Only useful for swapping in a
     * mock handler under test — in normal use the client is built lazily from the settings.
     */
    public function setClient(?Client $client): void
    {
        $this->client = $client;
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $settings = Plugin::getInstance()->getSettings();
            $this->client = Craft::createGuzzleClient([
                'base_uri' => self::API_BASE,
                'headers' => [
                    'Authorization' => 'Bearer ' . Craft::parseEnv($settings->apiKey),
                    'Content-Type' => 'application/json',
                ],
            ]);
        }
        return $this->client;
    }

    /**
     * Returns the decoded response body, or null on failure.
     * Sets $this->lastError for non-404 client errors.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @return array<array-key,mixed>|null
     */
    private function makeRequest(string $method, string $path, array $body = [], array $query = []): ?array
    {
        $this->lastError = null;

        try {
            $options = [];
            if (!empty($body)) {
                $options['json'] = $body;
            }
            if (!empty($query)) {
                $options['query'] = $query;
            }

            $response = $this->getClient()->request($method, $path, $options);
            $contents = $response->getBody()->getContents();

            return $contents ? json_decode($contents, true) : null;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }

            $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            $this->lastError = $errorBody['error']['message'] ?? $errorBody['message'] ?? 'An error occurred with the Dub API.';
            Craft::error('Dub API error: ' . $e->getMessage(), __METHOD__);
            return null;
        } catch (Throwable $e) {
            Craft::error('Dub API error: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
