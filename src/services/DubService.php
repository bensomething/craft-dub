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
 * @phpstan-type CandidateMaps array{url: PathMap, path: PathMap}
 * @phpstan-type Rewrites list<array{0: string, 1: string}>
 * @phpstan-type TestStep array{step: string, ok: bool, detail: string}
 * @phpstan-type CheckSummary array{checked: int, ok: int, missing: int, drifted: int, stale: int, unreadable: int, repaired: int, unrepaired: int, error: string|null}
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

    /** @var array<int,list<int>> Site ids captured before a hard delete cascades the rows away. */
    private array $doomedSiteIds = [];

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

        // Dub needs somewhere it can actually redirect to. A site whose baseUrl can't be
        // resolved — an undefined environment variable, most often — yields a bare path here,
        // and only outside a web request: in one, Craft falls back to the current request's
        // host, so the same entry saves fine in the control panel and fails under a console
        // command, a queue job or cron. Sending the path can only 4xx, and erroring would
        // block the save, so skip it and say why.
        if (!$this->isAbsoluteUrl($url)) {
            Craft::warning(sprintf(
                'Dub: skipping entry %s on site %s — its URL resolved to "%s", which is not absolute. '
                . "Check the site's Base URL, and that any environment variable it refers to is set here.",
                $entry->getCanonicalId() ?? 'new',
                $entry->siteId,
                $url,
            ), __METHOD__);

            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!Craft::parseEnv($settings->apiKey)) {
            return null;
        }

        $domain = $settings->domainForSite($entry->siteId);
        $externalId = $entry->uid . '_' . $entry->siteId;

        $optionals = [];
        if ($customKey) {
            $optionals['key'] = $customKey;
        }
        if ($domain) {
            $optionals['domain'] = $domain;
        }

        // Nothing has moved since the last save, so there's nothing to send.
        if ($this->isLinkCurrent($entry, $url, $customKey, $domain !== '' ? $domain : null)) {
            return null;
        }

        // Try to update existing link; create if not found
        $result = $this->makeRequest('PATCH', '/links/ext_' . $externalId, array_merge(['url' => $url, 'archived' => false], $optionals));

        if ($result === null) {
            if ($this->lastError) {
                return $this->lastError;
            }

            // A 404 here means the link was deleted at Dub while the local record survived.
            // Creating without a key lets Dub mint a random one, so the entry silently ends up
            // on a different short URL from the one already in circulation — a QR code or a
            // printed link stops working, and nothing says so. Fall back to the recorded slug,
            // which is exactly what dub/check --fix does when it recreates a missing link.
            $createBody = array_merge(['url' => $url, 'externalId' => $externalId], $optionals);
            if (!isset($createBody['key'])) {
                $recordedKey = $this->shortLinkPart($this->recordedShortLink($entry), PHP_URL_PATH);
                if ($recordedKey !== null && $recordedKey !== '') {
                    $createBody['key'] = $recordedKey;
                }
            }

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
            $this->saveLink(
                $entry->id,
                $entry->siteId,
                $link['id'] ?? null,
                $link['shortLink'],
                is_string($link['url'] ?? null) ? $link['url'] : null,
                (bool)($link['archived'] ?? false),
            );
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
     * Records which sites have links for an entry, before its rows can disappear.
     *
     * Craft deletes the row from `elements` and only then calls afterDelete(), and the links
     * table has a cascading foreign key onto it — so on a hard delete the rows are already
     * gone by the time the delete handler runs, taking with them the only record of which
     * sites had links. Without this the DELETEs are never sent and the links are stranded at
     * Dub forever, archived by the trash that preceded the delete.
     *
     * Call from EVENT_BEFORE_DELETE. $entry->hardDelete is assigned before beforeDelete(), so
     * the caller can tell a trash from a delete by then.
     */
    public function rememberLinksForDeletion(Entry $entry): void
    {
        $entryId = $entry->getCanonicalId();
        if ($entryId) {
            $this->doomedSiteIds[$entryId] = $this->linkedSiteIds($entryId);
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
            $this->rememberArchived($entryId, $target, $archived);
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

        // Prefer what was captured before the delete: on a hard delete the rows this would
        // otherwise read are already gone, cascaded away with the element.
        $linked = $this->doomedSiteIds[$entryId] ?? $this->linkedSiteIds($entryId);
        unset($this->doomedSiteIds[$entryId]);

        $targets = $siteId === null ? $linked : array_values(array_intersect($linked, [$siteId]));

        if ($this->apiKey()) {
            foreach ($targets as $target) {
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
        // Two maps of entry candidates across every site: one keyed by host + path, one by
        // path alone. Host + path identifies a single site's entry even when the sites share
        // their paths, which is what a domain- or subdomain-per-site install looks like
        // whenever the slug isn't translated. Path alone is the fallback, and it has to stay:
        // it's what lets --rewrite work, and what absorbs a destination recorded against a
        // different environment's hostname.
        $maps = ['url' => [], 'path' => []];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            foreach (Entry::find()->siteId($site->id)->status(null)->each() as $entry) {
                $url = $entry->getUrl();
                if (!$url) {
                    continue;
                }
                $path = $this->urlPath($url);
                $host = $this->urlHost($url);
                $candidate = ['id' => $entry->id, 'uid' => $entry->uid, 'siteId' => $site->id, 'title' => $entry->title];

                // A homepage's path trims to '', which is useless as a path-map key — it would
                // match every site's homepage at once. Host and path together stay specific
                // even then ('example.com'), so homepages go into the url map only. Without
                // this a link pointing at a site root could never be adopted, and showed up in
                // the report as unmatched even when it was already managed.
                if ($path !== '') {
                    $maps['path'][$path][] = $candidate;
                }

                if ($host !== '') {
                    $maps['url'][$host . $path][] = $candidate;
                }
            }
        }

        // Once per configured domain rather than once over the whole workspace. A workspace
        // can hold domains this install knows nothing about, and scanning unfiltered would
        // adopt links on them purely because their destination happens to match an entry.
        // With no domain configured at all there is nothing to filter by, so the single
        // unfiltered pass is the only thing left to do.
        $domains = $settings->configuredDomains() ?: [null];

        foreach ($domains as $domain) {
            $page = 1;
            $pageSize = 100;

            do {
                $query = ['page' => $page, 'pageSize' => $pageSize];
                if ($domain !== null) {
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
                    $this->adoptOne($link, $maps, $rewrites, $dryRun, $summary, $onResult);
                }

                $page++;
            } while (count($links) === $pageSize);
        }

        return $summary;
    }

    /**
     * Matches a single Dub link to an entry and adopts it. Mutates $summary in place.
     *
     * @param array<string,mixed> $link
     * @param CandidateMaps $maps
     * @param Rewrites $rewrites
     * @param AdoptSummary $summary
     * @param callable(string, string): void|null $onResult
     * @param-out AdoptSummary $summary
     */
    private function adoptOne(array $link, array $maps, array $rewrites, bool $dryRun, array &$summary, ?callable $onResult): void
    {
        $dubId = $link['id'] ?? null;
        $shortLink = $link['shortLink'] ?? null;
        $destUrl = $link['url'] ?? null;
        if (!$dubId || !$shortLink || !$destUrl) {
            return;
        }

        // A link this plugin created carries an externalId naming its entry and site exactly,
        // so there is nothing to work out: matching it by destination would be guessing at
        // something already known. It also keeps the report honest where several entries share
        // a path — those links were reported as ambiguous when they were simply already ours.
        $owned = $this->entryForExternalId(is_string($link['externalId'] ?? null) ? $link['externalId'] : null);

        $candidates = $owned !== null ? [$owned] : $this->matchCandidates($destUrl, $maps, $rewrites);

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
        // An entry can have no title — a Single often doesn't — and " [1] → https://…" tells
        // nobody which entry was adopted. Fall back to the id, as dub/check's labels do.
        $label = ($entry['title'] ?: '#' . $entry['id']) . ' [' . $entry['siteId'] . ']';

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

        $this->saveLink($entry['id'], $entry['siteId'], $dubId, $shortLink, $destUrl, (bool)($link['archived'] ?? false));
        $summary['adopted']++;
        $onResult && $onResult('adopted', $label . ' → ' . $shortLink);
    }

    /**
     * Runs a live round trip against Dub and reports what worked.
     *
     * The point is the write. A read-only probe would catch a bad key or a missing domain, but
     * the domain list failing to load already says the first and the settings screen already
     * warns about the second. What neither catches is a token without write scope, or a
     * workspace at its link limit: those pass every GET and fail the moment an editor saves.
     *
     * Each step is reported separately because "it failed" is much less useful than "created
     * but could not delete", which says the token is write-but-not-delete and that there is now
     * an orphan to clear up.
     *
     * @return list<TestStep>
     */
    public function runTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $steps = [];

        if (!$this->apiKey()) {
            return [['step' => 'API key', 'ok' => false, 'detail' => 'No API key configured']];
        }

        $domains = $this->getDomains();
        $available = array_column($domains, 'slug');

        if ($domains === []) {
            $steps[] = ['step' => 'API key', 'ok' => false, 'detail' => $this->lastError ?? 'Could not read your workspace'];

            return $steps;
        }

        $steps[] = ['step' => 'API key', 'ok' => true, 'detail' => 'Connected'];

        $domain = Craft::parseEnv($settings->domain);
        $domain = is_string($domain) ? $domain : '';

        if ($domain === '') {
            $steps[] = ['step' => 'Domain', 'ok' => false, 'detail' => 'No domain configured'];

            return $steps;
        }

        // Naming both halves when they differ, since on the command line there is no field to
        // look at and "Europe/London is not in the workspace" would not say where that came
        // from. The settings screen words it the same way.
        $configured = $settings->domain;
        $named = $configured !== $domain ? $configured . ' resolves to ' . $domain : $domain;

        $domainOk = in_array($domain, $available, true);
        $steps[] = [
            'step' => 'Domain',
            'ok' => $domainOk,
            'detail' => $domainOk ? $named : $named . ', which is not in the workspace',
        ];

        if (!$domainOk) {
            return $steps;
        }

        // Random, and stamped with an externalId nothing else uses, so a leftover from a failed
        // run is identifiable rather than looking like a link somebody made.
        $key = 'dub-test-' . bin2hex(random_bytes(4));
        $externalId = 'dub-plugin-test-' . $key;

        $created = $this->makeRequest('POST', '/links', [
            'url' => 'https://example.com/craft-dub-connection-test',
            'domain' => $domain,
            'key' => $key,
            'externalId' => $externalId,
        ]);

        if (!is_array($created)) {
            $steps[] = ['step' => 'Create link', 'ok' => false, 'detail' => $this->lastError ?? 'Dub refused to create a link'];

            return $steps;
        }

        // Not the URL. It is deleted two steps below, so showing it invites a click on a link
        // that will 404, and the only moment it matters is a failed delete, which names the key
        // itself.
        $steps[] = ['step' => 'Create link', 'ok' => true, 'detail' => 'Created'];

        $linkId = is_string($created['id'] ?? null) ? $created['id'] : null;

        $readBack = $linkId !== null ? $this->makeRequest('GET', '/links/info', [], ['linkId' => $linkId]) : null;
        $steps[] = [
            'step' => 'Read link',
            'ok' => is_array($readBack),
            'detail' => is_array($readBack) ? 'Found' : ($this->lastError ?? 'Could not read the link back'),
        ];

        $qr = $this->makeRequest('GET', '/qr', [], ['url' => $created['shortLink'] ?? '']);
        $steps[] = [
            'step' => 'Fetch QR',
            'ok' => $qr !== null || $this->lastError === null,
            'detail' => $this->lastError ?? 'Available',
        ];

        $deleted = $linkId !== null ? $this->makeRequest('DELETE', '/links/' . $linkId) : null;
        $goneOk = $deleted !== null || $this->lastError === null;
        $steps[] = [
            'step' => 'Delete link',
            'ok' => $goneOk,
            'detail' => $goneOk
                ? 'Removed'
                : ($this->lastError ?? 'Could not delete it') . '. Remove ' . $key . ' by hand',
        ];

        return $steps;
    }

    /**
     * Checks every recorded link against Dub, and optionally repairs what it finds.
     *
     * This exists because saving an entry no longer re-sends an unchanged link. That skip is
     * what makes a resave cheap, but it also means the plugin stops noticing when a link is
     * deleted or edited at Dub: the local row keeps rendering a short link that no longer
     * resolves. `dub/adopt` can't help — it walks the links Dub still has and skips entries
     * that already have a row, so a row pointing at a deleted link is invisible to it.
     *
     * @param callable(string, string): void|null $onResult
     * @return CheckSummary
     */
    public function checkLinks(bool $fix = false, ?callable $onResult = null): array
    {
        // Detections (ok/missing/drifted/unreadable) are mutually exclusive and sum to
        // `checked`. Outcomes (repaired/unrepaired) are a subset of missing + drifted, and only
        // move under --fix. Keeping them apart stops one bad row being counted twice.
        $summary = [
            'checked' => 0,
            'ok' => 0,
            'missing' => 0,
            'drifted' => 0,
            'stale' => 0,
            'unreadable' => 0,
            'repaired' => 0,
            'unrepaired' => 0,
            'error' => null,
        ];

        if (!$this->apiKey()) {
            $summary['error'] = 'No Dub API key configured.';
            return $summary;
        }

        /** @var DubLink $record */
        foreach (DubLink::find()->orderBy(['entryId' => SORT_ASC, 'siteId' => SORT_ASC])->all() as $record) {
            $label = $this->labelFor($record);

            $remote = $record->dubLinkId
                ? $this->makeRequest('GET', '/links/info', [], ['linkId' => $record->dubLinkId])
                : $this->makeRequest('GET', '/links/info', [], ['externalId' => 'ext_' . $this->externalIdFor($record)]);

            $summary['checked']++;
            $status = $this->classifyRemote($remote, $this->lastError !== null, $record->shortLink, $record->destinationUrl);

            if ($status === 'ok') {
                // Dub agrees with the record — but the record may itself have fallen behind
                // Craft. Nothing above compares either against where the entry now lives, so a
                // link can be perfectly consistent and still point somewhere the entry left.
                $stale = $this->staleAgainstCraft($record);
                if ($stale === null) {
                    $summary['ok']++;
                    continue;
                }

                $summary['stale']++;
                $onResult && $onResult('stale', $label . ' — ' . $stale);
                continue;
            }

            if ($status === 'failed') {
                $summary['unreadable']++;
                $onResult && $onResult('failed', $label . ' — ' . $this->lastError);
                continue;
            }

            $summary[$status]++;
            $detail = $status === 'missing'
                ? $label . ' — gone from Dub'
                : $label . ' — Dub has ' . ($remote['shortLink'] ?? '?') . ' → ' . ($remote['url'] ?? '?');
            $onResult && $onResult($status, $detail);

            if (!$fix) {
                continue;
            }

            if ($this->repairLink($record, $remote)) {
                $summary['repaired']++;
                // Re-labelled, not reused: a reconciled link may have taken on Dub's slug, and
                // reporting the old one would hide the very thing that changed.
                $onResult && $onResult('repaired', $this->labelFor($record));
            } else {
                $summary['unrepaired']++;
                $onResult && $onResult('failed', $label . ' — ' . ($this->lastError ?? 'could not repair'));
            }
        }

        return $summary;
    }

    /**
     * How one recorded link is named in the command's output.
     */
    private function labelFor(DubLink $record): string
    {
        return ($record->shortLink ?? '#' . $record->id)
            . ' (entry ' . $record->entryId . ', site ' . $record->siteId . ')';
    }

    /**
     * Why a record has fallen behind Craft, or null if it hasn't.
     *
     * Reuses the comparison a save makes: if `prepareLink()` would send something, the link is
     * stale. That happens without anything being wrong at Dub — change a site's Base URL and
     * every link on it points at the old host until its entry is next saved; change the Domain
     * setting and existing links stay on the old short domain the same way.
     *
     * Deliberately not repaired by --fix. The repair is `php craft resave/entries`, which since
     * change detection sends one request per link that has actually moved and stays silent for
     * the rest. Reimplementing that here would mean two code paths deciding what a link should
     * look like, which is how they come apart.
     */
    private function staleAgainstCraft(DubLink $record): ?string
    {
        $entry = $this->recordedEntry($record);
        if ($entry === null) {
            return null;
        }

        $url = $this->resolveDestinationUrl($entry);
        if ($url === null || !$this->isAbsoluteUrl($url)) {
            return null;
        }

        if ($record->destinationUrl !== null && $record->destinationUrl !== $url) {
            return 'entry now at ' . $url;
        }

        // The site's own domain, so changing one site's override makes only that site's links
        // stale rather than every link in the install.
        $domain = Plugin::getInstance()->getSettings()->domainForSite((int)$record->siteId);
        $recordedDomain = $this->shortLinkPart($record->shortLink, PHP_URL_HOST);

        if ($domain !== '' && $recordedDomain !== null && $recordedDomain !== $domain) {
            return 'on ' . $recordedDomain . ', but this site is configured for ' . $domain;
        }

        return null;
    }

    /**
     * Decides what a lookup says about a recorded link.
     *
     * makeRequest() returns null both for a 404 and for a real failure, and only the latter
     * sets lastError — so the two have to be told apart by the caller, not by the null.
     *
     * @param array<string, mixed>|null $remote
     * @return 'ok'|'missing'|'drifted'|'failed'
     */
    private function classifyRemote(?array $remote, bool $hadError, ?string $recordedShortLink, ?string $recordedUrl): string
    {
        if ($hadError) {
            return 'failed';
        }

        if ($remote === null) {
            return 'missing';
        }

        if ($recordedShortLink !== null && ($remote['shortLink'] ?? null) !== $recordedShortLink) {
            return 'drifted';
        }

        // A null recorded destination predates the state columns, so there's nothing to
        // compare and nothing wrong — the next save fills it in.
        if ($recordedUrl !== null && ($remote['url'] ?? null) !== $recordedUrl) {
            return 'drifted';
        }

        return 'ok';
    }

    /**
     * Reconciles one link, with each side owning what it actually controls.
     *
     * The destination belongs to Craft — it's derived from the entry, so Dub's copy of it is
     * only ever a stale mirror, and Craft's value is pushed back.
     *
     * The slug belongs to Dub. Renaming a link there is a deliberate act, and the renamed URL
     * is the one now in circulation; forcing the old one back would break whatever has been
     * shared. It can also simply fail — if anything else has claimed the old slug in the
     * meantime, Dub answers with a duplicate-key error and the record is stuck reporting a
     * failure that no amount of re-running can clear. So a rename is adopted into the record
     * rather than reversed.
     *
     * @param array<string, mixed>|null $remote Null when the link is gone from Dub.
     */
    private function repairLink(DubLink $record, ?array $remote): bool
    {
        return $remote === null ? $this->recreateLink($record) : $this->reconcileLink($record, $remote);
    }

    /**
     * Takes on Dub's slug, and pushes Craft's destination if Dub's has drifted from it.
     *
     * @param array<string, mixed> $remote
     */
    private function reconcileLink(DubLink $record, array $remote): bool
    {
        $remoteUrl = is_string($remote['url'] ?? null) ? $remote['url'] : null;
        $shortLink = is_string($remote['shortLink'] ?? null) ? $remote['shortLink'] : $record->shortLink;
        $linkId = is_string($remote['id'] ?? null) ? $remote['id'] : $record->dubLinkId;

        // A null recorded destination predates the state columns: nothing to push, and the
        // next save fills it in.
        if ($record->destinationUrl !== null && $remoteUrl !== $record->destinationUrl) {
            $result = $this->makeRequest('PATCH', '/links/ext_' . $this->externalIdFor($record), [
                'url' => $record->destinationUrl,
            ]);

            if (!is_array($result)) {
                return false;
            }

            $shortLink = is_string($result['shortLink'] ?? null) ? $result['shortLink'] : $shortLink;
            $linkId = is_string($result['id'] ?? null) ? $result['id'] : $linkId;
        }

        $record->dubLinkId = $linkId;
        $record->shortLink = $shortLink;
        $record->save();

        return true;
    }

    /**
     * Recreates a link that's gone from Dub, with the slug, destination and archived state the
     * record still holds. The new link is a new link: its click history doesn't come back.
     */
    private function recreateLink(DubLink $record): bool
    {
        $url = $record->destinationUrl ?? $this->recordedEntryUrl($record);
        if ($url === null) {
            $this->lastError = 'no destination recorded, and the entry has no URL';
            return false;
        }

        $body = ['url' => $url, 'archived' => (bool)$record->archived];
        $key = $this->shortLinkPart($record->shortLink, PHP_URL_PATH);
        $domain = $this->shortLinkPart($record->shortLink, PHP_URL_HOST);
        if ($key !== null && $key !== '') {
            $body['key'] = $key;
        }
        if ($domain !== null && $domain !== '') {
            $body['domain'] = $domain;
        }

        $body['externalId'] = $this->externalIdFor($record);
        $result = $this->makeRequest('POST', '/links', $body);

        if (!is_array($result)) {
            return false;
        }

        $record->dubLinkId = $result['id'] ?? $record->dubLinkId;
        $record->shortLink = $result['shortLink'] ?? $record->shortLink;
        $record->destinationUrl = is_string($result['url'] ?? null) ? $result['url'] : $record->destinationUrl;
        $record->save();

        return true;
    }

    /**
     * The externalId Dub holds for a record: the entry uid plus the site, as prepareLink()
     * builds it. Read from the entry rather than stored, so it stays a single definition.
     */
    private function externalIdFor(DubLink $record): string
    {
        return ($this->recordedEntry($record)?->uid ?? '') . '_' . $record->siteId;
    }

    private function recordedEntry(DubLink $record): ?Entry
    {
        return Entry::find()->id($record->entryId)->siteId($record->siteId)->status(null)->one();
    }

    private function recordedEntryUrl(DubLink $record): ?string
    {
        return $this->recordedEntry($record)?->getUrl();
    }

    /**
     * The entry a link's externalId names, if it still exists.
     *
     * Falls back to null — and so to destination matching — when the id can't be parsed or the
     * entry has since gone, rather than treating the link as unmatchable.
     *
     * @return EntryCandidate|null
     */
    private function entryForExternalId(?string $externalId): ?array
    {
        $parsed = $this->parseExternalId($externalId);
        if ($parsed === null) {
            return null;
        }

        [$uid, $siteId] = $parsed;

        $entry = Entry::find()->uid($uid)->siteId($siteId)->status(null)->one();

        return $entry ? [
            'id' => $entry->id,
            'uid' => $entry->uid,
            'siteId' => $siteId,
            'title' => $entry->title,
        ] : null;
    }

    /**
     * Splits an externalId into the entry uid and site id that prepareLink() joined.
     *
     * Split on the last underscore, not the first: a uid contains hyphens rather than
     * underscores, but nothing guarantees that of an id stamped by something else.
     *
     * @return array{0: string, 1: int}|null
     */
    private function parseExternalId(?string $externalId): ?array
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        $pos = strrpos($externalId, '_');
        if ($pos === false || $pos === 0) {
            return null;
        }

        $uid = substr($externalId, 0, $pos);
        $siteId = substr($externalId, $pos + 1);

        if ($siteId === '' || !ctype_digit($siteId)) {
            return null;
        }

        return [$uid, (int)$siteId];
    }

    /**
     * Returns the entry candidates for a link's destination URL.
     *
     * Host and path together are tried first: a link to example.fr/about and one to
     * example.com/about are different links pointing at different entries, and reporting
     * that pair as ambiguous — as matching on path alone did — left exactly the multi-site
     * installs that need adoption most unable to use it. Path alone is the fallback, then
     * each prefix rewrite (e.g. /areas-stages/ => /venues/) in turn, host-first again.
     *
     * @param CandidateMaps $maps
     * @param Rewrites $rewrites
     * @return list<EntryCandidate>
     */
    private function matchCandidates(string $destUrl, array $maps, array $rewrites): array
    {
        $host = $this->urlHost($destUrl);
        $path = $this->urlPath($destUrl);

        if ($host !== '' && !empty($maps['url'][$host . $path])) {
            return $maps['url'][$host . $path];
        }

        if (!empty($maps['path'][$path])) {
            return $maps['path'][$path];
        }

        foreach ($rewrites as [$from, $to]) {
            if (!str_starts_with($path, $from)) {
                continue;
            }

            $rewritten = rtrim($to . substr($path, strlen($from)), '/');

            if ($host !== '' && !empty($maps['url'][$host . $rewritten])) {
                return $maps['url'][$host . $rewritten];
            }

            if (!empty($maps['path'][$rewritten])) {
                return $maps['path'][$rewritten];
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

    /**
     * Whether a URL is absolute enough for Dub to redirect to: a scheme and a host.
     */
    private function isAbsoluteUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']);
    }

    /**
     * Normalises a URL to its lowercased host, with any leading www. dropped. Both sides of
     * a comparison go through this, so www.example.com and example.com are the same site.
     */
    private function urlHost(string $url): string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function findRecord(int $entryId, int $siteId): ?DubLink
    {
        return DubLink::findOne(['entryId' => $entryId, 'siteId' => $siteId]);
    }

    /**
     * The short link currently recorded for an entry's own site, if any.
     */
    private function recordedShortLink(Entry $entry): ?string
    {
        $entryId = $entry->getCanonicalId();

        return $entryId ? $this->findRecord($entryId, $entry->siteId)?->shortLink : null;
    }

    /**
     * Whether Dub already holds this link exactly as this save would leave it.
     *
     * The plugin used to PATCH on every save of every linked entry, so a `resave/entries`
     * across a section was one HTTP round-trip per entry per site for links that hadn't
     * moved. The comparison is against the local record, which only holds while that record
     * is accurate — see linkIsCurrent() for the cases deliberately left to fall through.
     */
    private function isLinkCurrent(Entry $entry, string $url, ?string $customKey, ?string $domain): bool
    {
        $entryId = $entry->getCanonicalId();
        $record = $entryId ? $this->findRecord($entryId, $entry->siteId) : null;

        if ($record === null) {
            return false;
        }

        return $this->linkIsCurrent(
            $record->destinationUrl,
            $record->shortLink,
            (bool)$record->archived,
            $url,
            $customKey,
            $domain,
        );
    }

    /**
     * The comparison behind isLinkCurrent(), over the recorded state rather than the record,
     * so it can be exercised without a database.
     *
     * Anything unknown falls through to the PATCH rather than being assumed unchanged: a null
     * destinationUrl means the row predates the state columns, and an archived link needs the
     * `archived: false` the PATCH carries. A null key or domain isn't sent at all, so Dub
     * keeps whatever it has and there is nothing to compare.
     */
    private function linkIsCurrent(
        ?string $recordedUrl,
        ?string $recordedShortLink,
        bool $recordedArchived,
        string $url,
        ?string $customKey,
        ?string $domain,
    ): bool {
        if ($recordedUrl === null || $recordedArchived) {
            return false;
        }

        if ($recordedUrl !== $url) {
            return false;
        }

        if ($customKey && $customKey !== $this->shortLinkPart($recordedShortLink, PHP_URL_PATH)) {
            return false;
        }

        if ($domain && $domain !== $this->shortLinkPart($recordedShortLink, PHP_URL_HOST)) {
            return false;
        }

        return true;
    }

    /**
     * The host or the key of a recorded short link, or null if it can't be read.
     */
    private function shortLinkPart(?string $shortLink, int $component): ?string
    {
        $part = $shortLink ? parse_url($shortLink, $component) : null;

        return is_string($part) ? ltrim($part, '/') : null;
    }

    /**
     * Records that the entry's link for a site has been archived or un-archived, so the next
     * save can tell whether the `archived: false` a PATCH carries is still needed.
     */
    protected function rememberArchived(int $entryId, int $siteId, bool $archived): void
    {
        DubLink::updateAll(['archived' => $archived], ['entryId' => $entryId, 'siteId' => $siteId]);
    }

    /**
     * The resolved API key, or null when the plugin isn't configured.
     *
     * This and the record helpers below are the service's only Craft-app dependencies on
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

    private function saveLink(
        int $entryId,
        int $siteId,
        ?string $dubLinkId,
        string $shortLink,
        ?string $destinationUrl = null,
        bool $archived = false,
    ): void {
        $record = $this->findRecord($entryId, $siteId) ?? new DubLink();
        $record->entryId = $entryId;
        $record->siteId = $siteId;
        $record->dubLinkId = $dubLinkId;
        $record->shortLink = $shortLink;
        $record->destinationUrl = $destinationUrl;
        $record->archived = $archived;
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
