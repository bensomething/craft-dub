<?php

namespace bensomething\craftdub\services;

use bensomething\craftdub\Plugin;
use bensomething\craftdub\records\DubLink;
use Craft;
use craft\elements\Entry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Throwable;
use yii\base\Component;

class DubService extends Component
{
    private const API_BASE = 'https://api.dub.co';

    private ?string $lastError = null;
    private ?Client $client = null;
    private ?array $pendingLink = null;
    private ?int $pendingSiteId = null;
    private bool $pendingDelete = false;
    private ?Entry $pendingDeleteEntry = null;

    /**
     * Schedules a link deletion to be committed in EVENT_AFTER_SAVE.
     */
    public function scheduleDeletion(Entry $entry): void
    {
        $this->pendingDelete = true;
        $this->pendingDeleteEntry = $entry;
    }

    /**
     * Deletes the link from Dub and removes the local DB record.
     */
    public function commitDeletion(): void
    {
        if ($this->pendingDelete && $this->pendingDeleteEntry !== null) {
            $this->deleteLink($this->pendingDeleteEntry);
        }
        $this->pendingDelete = false;
        $this->pendingDeleteEntry = null;
    }

    public function isPendingDeletion(): bool
    {
        return $this->pendingDelete;
    }

    /**
     * Makes the Dub API call and caches the result. Returns an error string on failure.
     * Call commitLink() in EVENT_AFTER_SAVE to persist the result to the DB.
     */
    public function prepareLink(Entry $entry, ?string $customKey = null): ?string
    {
        $url = $entry->getUrl();
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

            $result = $this->makeRequest('POST', '/links', array_merge(['url' => $url, 'externalId' => $externalId], $optionals));

            if ($result === null) {
                return $this->lastError;
            }
        }

        $this->pendingLink = $result;
        $this->pendingSiteId = $entry->siteId;

        return null;
    }

    /**
     * Saves the cached API result to the DB. Call after a successful entry save.
     */
    public function commitLink(int $entryId): void
    {
        if ($this->pendingLink && isset($this->pendingLink['shortLink']) && $this->pendingSiteId !== null) {
            if (isset($this->pendingLink['workspaceId'])) {
                Craft::$app->cache->set('dub_workspace_id', $this->pendingLink['workspaceId']);
            }
            $this->saveLink($entryId, $this->pendingSiteId, $this->pendingLink['id'] ?? null, $this->pendingLink['shortLink']);
        }

        $this->pendingLink = null;
        $this->pendingSiteId = null;
    }

    public function deactivateLink(Entry $entry): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (Craft::parseEnv($settings->apiKey)) {
            $this->makeRequest('PATCH', '/links/ext_' . $entry->uid . '_' . $entry->siteId, ['archived' => true]);
        }
    }

    public function deleteLink(Entry $entry): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (Craft::parseEnv($settings->apiKey)) {
            $records = DubLink::findAll(['entryId' => $entry->id]);
            foreach ($records as $record) {
                $this->makeRequest('DELETE', '/links/ext_' . $entry->uid . '_' . $record->siteId);
            }
        }
        DubLink::deleteAll(['entryId' => $entry->id]);
    }

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
        return Craft::$app->cache->get('dub_workspace_id') ?: null;
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
     * @param callable|null $onResult Invoked per link as ($status, $message); $status is one
     *   of: adopted, skipped, ambiguous, unmatched, error.
     * @return array{adopted:int,skipped:int,ambiguous:int,unmatched:array<string>,failed:int,error:?string}
     */
    public function adoptLinks(bool $dryRun = false, ?callable $onResult = null): array
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
                $this->adoptOne($link, $pathMap, $dryRun, $summary, $onResult);
            }

            $page++;
        } while (count($links) === $pageSize);

        return $summary;
    }

    /**
     * Matches a single Dub link to an entry and adopts it. Mutates $summary in place.
     */
    private function adoptOne(array $link, array $pathMap, bool $dryRun, array &$summary, ?callable $onResult): void
    {
        $dubId = $link['id'] ?? null;
        $shortLink = $link['shortLink'] ?? null;
        $destUrl = $link['url'] ?? null;
        if (!$dubId || !$shortLink || !$destUrl) {
            return;
        }

        $candidates = $pathMap[$this->urlPath($destUrl)] ?? [];

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

        if (isset($link['workspaceId'])) {
            Craft::$app->cache->set('dub_workspace_id', $link['workspaceId']);
        }

        $this->saveLink($entry['id'], $entry['siteId'], $dubId, $shortLink);
        $summary['adopted']++;
        $onResult && $onResult('adopted', $label . ' → ' . $shortLink);
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
