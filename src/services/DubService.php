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
     * Executes any pending deletion and resets state.
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
    private function makeRequest(string $method, string $path, array $body = []): ?array
    {
        $this->lastError = null;

        try {
            $options = [];
            if (!empty($body)) {
                $options['json'] = $body;
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
