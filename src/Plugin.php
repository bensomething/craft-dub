<?php

namespace bensomething\craftdub;

use bensomething\craftdub\models\Settings;
use bensomething\craftdub\services\DubService;
use bensomething\craftdub\twigextensions\DubTwigExtension;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\base\Event;

/**
 * Dub plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property DubService $dub
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;

    /**
     * @return array{components: array<string, class-string>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'dub' => DubService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->getView()->registerTwigExtension(new DubTwigExtension());

        $this->attachEventHandlers();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Renders the settings as a full CP page (rather than the default fragment) so it can
     * declare native tabs via the `tabs` variable — Craft renders and wires those itself,
     * no custom JS. The field inputs are namespaced under `settings` to match how Craft's
     * default plugin-settings response posts them.
     */
    public function getSettingsResponse(): mixed
    {
        $settings = $this->getSettings();
        $domains = [];

        if (!empty(Craft::parseEnv($settings->apiKey))) {
            $domains = $this->dub->getDomains();
        }

        $sectionOptions = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getSiteSettings() as $siteSetting) {
                if ($siteSetting->hasUrls) {
                    $sectionOptions[] = ['label' => $section->name, 'value' => $section->handle];
                    break;
                }
            }
        }

        $sectionsEnv = App::env('DUB_SECTIONS');

        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('dub/_settings.twig', [
            'plugin' => $this,
            'settings' => $settings,
            'domains' => $domains,
            'sectionOptions' => $sectionOptions,
            'enabledSections' => $this->getEnabledSections(),
            'sectionsOverridden' => $sectionsEnv !== null && $sectionsEnv !== '',
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(Entry::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->getIsDraft() || $entry->getIsRevision()) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry)) {
                return;
            }

            if ($entry->getStatus() !== Entry::STATUS_LIVE) {
                return;
            }

            $readsRequest = self::readsLinkFields(
                Craft::$app->getRequest()->getIsConsoleRequest(),
                $entry->propagating,
            );
            $customKey = $readsRequest ? Craft::$app->getRequest()->getBodyParam('dubCustomKey') ?: null : null;
            $shortLinkPresent = $readsRequest ? Craft::$app->getRequest()->getBodyParam('dubShortLinkPresent') : null;
            $hasExistingLink = Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId(), $entry->siteId) !== null;

            // Cleared slug with existing link = schedule deletion
            if ($shortLinkPresent && $customKey === null && $hasExistingLink) {
                Plugin::getInstance()->dub->scheduleDeletion($entry);
                return;
            }

            if ($customKey !== null || $hasExistingLink) {
                $error = Plugin::getInstance()->dub->prepareLink($entry, $customKey);
                if ($error) {
                    $entry->addError('dub', Craft::t('dub', 'Dub · {error}', ['error' => $error]));
                    $event->isValid = false;
                }
            }
        });

        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->getIsDraft() || $entry->getIsRevision()) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry)) {
                return;
            }

            if (Plugin::getInstance()->dub->isPendingDeletion($entry)) {
                Plugin::getInstance()->dub->commitDeletion($entry);
            } elseif ($entry->getStatus() === Entry::STATUS_LIVE) {
                Plugin::getInstance()->dub->commitLink($entry);
            } else {
                Plugin::getInstance()->dub->deactivateLink($entry);
            }
        });

        Event::on(Entry::class, Element::EVENT_BEFORE_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if (!self::actsOnLifecycleOf($entry->getIsDraft(), $entry->getIsRevision())) {
                return;
            }

            // Only a hard delete needs this. A trash leaves the rows alone, so the delete
            // handler can still read them; a hard delete cascades them away before it runs.
            if ($entry->hardDelete) {
                Plugin::getInstance()->dub->rememberLinksForDeletion($entry);
            }
        });

        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if (!self::actsOnLifecycleOf($entry->getIsDraft(), $entry->getIsRevision())) {
                return;
            }

            // Craft sets dateDeleted unconditionally just before firing this event, so it
            // can't tell a trash from a hard delete here. $hardDelete is assigned earlier,
            // before beforeDelete(), and is the only reliable signal. Reading dateDeleted
            // instead meant every trash took the delete branch, and trashing an entry — a
            // reversible action — destroyed its Dub link and every QR code pointing at it.
            if (!$entry->hardDelete) {
                Plugin::getInstance()->dub->deactivateLinks($entry);
                return;
            }

            Plugin::getInstance()->dub->deleteLink($entry);
        });

        Event::on(Entry::class, Element::EVENT_AFTER_RESTORE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if (!self::actsOnLifecycleOf($entry->getIsDraft(), $entry->getIsRevision())) {
                return;
            }

            $entryId = $entry->getCanonicalId();
            if (!$entryId) {
                return;
            }

            // afterRestore() fires once for the entry, not once per site, so the other sites'
            // links have to be found here. Only the sites it comes back live on are
            // un-archived — a site it's still disabled on stays archived, matching what the
            // after-save handler would do. Craft resets $entry->trashed after this event, so
            // the status has to come from a fresh query rather than the sender.
            $liveSiteIds = [];
            foreach (Entry::find()->id($entryId)->siteId('*')->status(Entry::STATUS_LIVE)->all() as $siteEntry) {
                $liveSiteIds[] = $siteEntry->siteId;
            }

            Plugin::getInstance()->dub->restoreLinks($entry, $liveSiteIds);
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->getIsRevision()) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry, false)) {
                return;
            }

            $sectionEnabled = $this->entrySectionHasUrls($entry, true);
            $settings = Plugin::getInstance()->getSettings();
            $hasApiKey = !empty(Craft::parseEnv($settings->apiKey));
            $domain = Craft::parseEnv($settings->domain);
            $hasDomain = !empty($domain);
            $shortLink = Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId(), $entry->siteId);

            // Only show sidebar if section is enabled or entry already has a short link
            if (!$sectionEnabled && !$shortLink) {
                return;
            }

            // Link to settings whenever setup is incomplete (no API key, or no domain).
            $settingsUrl = (!$hasApiKey || !$hasDomain) ? UrlHelper::cpUrl('settings/plugins/dub') : null;

            $currentKey = $shortLink ? ltrim(parse_url($shortLink, PHP_URL_PATH), '/') : null;
            $shortLinkDomain = $shortLink ? parse_url($shortLink, PHP_URL_HOST) : null;
            $isAdmin = Craft::$app->getUser()->getIsAdmin();
            $workspaceId = ($isAdmin && $shortLink) ? Plugin::getInstance()->dub->getWorkspaceId() : null;

            $dubDashboardUrl = null;
            if ($workspaceId && $shortLinkDomain && $currentKey) {
                $dubDashboardUrl = 'https://app.dub.co/' . $workspaceId . '/links/' . $shortLinkDomain . '/' . $currentKey;
            }

            $qrViewMode = $settings->qrViewMode;
            $qrUrl = ($shortLink && $qrViewMode !== 'none') ? Plugin::getInstance()->dub->getQrUrl($entry->getCanonicalId(), $entry->siteId) : null;
            $clicks = ($shortLink && $settings->showClicks) ? Plugin::getInstance()->dub->getClicks($entry->getCanonicalId(), $entry->siteId) : null;

            $row = Craft::$app->getView()->renderTemplate('dub/_entry-sidebar.twig', [
                'shortLink' => $shortLink,
                'currentKey' => $currentKey,
                'hasApiKey' => $hasApiKey,
                'hasDomain' => $hasDomain,
                'domain' => $domain,
                'settingsUrl' => $settingsUrl,
                'sectionEnabled' => $sectionEnabled,
                'isLive' => $entry->getStatus() === Entry::STATUS_LIVE,
                'dubDashboardUrl' => $dubDashboardUrl,
                'qrUrl' => $qrUrl,
                'qrViewMode' => $qrViewMode,
                'clicks' => $clicks,
            ]);
            $event->html = $this->injectSidebarRow($event->html, $row);
        });
    }

    /**
     * Whether a delete or restore of this element should touch the canonical entry's links.
     *
     * Only the canonical entry owns a link. Drafts and revisions must be ignored, and on the
     * delete path that is not cosmetic: Craft hard-deletes the provisional draft after every
     * CP save, which fires afterDelete() with hardDelete = true. Since the delete path
     * resolves getCanonicalId() — a draft's canonical id is the real entry — an unguarded
     * handler answers that routine cleanup by deleting the entry's live Dub link and its
     * local row, moments after the save that created them.
     */
    private static function actsOnLifecycleOf(bool $isDraft, bool $isRevision): bool
    {
        return !$isDraft && !$isRevision;
    }

    /**
     * Whether the Dub form fields in the request body apply to this save.
     *
     * They don't on a console save, which has no request behind it. And they don't on a
     * propagated site element: propagation re-enters the before-save handler inside the same
     * request, so the params are still readable, but they describe the site the editor was
     * actually on. Reusing the custom key would PATCH this site's link with a key Dub has
     * already assigned to the originating site on the same domain, and the resulting 4xx
     * fails the entire entry save through Craft's cross-site validation.
     */
    private static function readsLinkFields(bool $isConsoleRequest, bool $isPropagating): bool
    {
        return !$isConsoleRequest && !$isPropagating;
    }

    /**
     * Inserts the short-link row into the entry sidebar so it sits after the main metadata
     * (slug, dates, …) but before the "Notes about your changes" field. Falls back to the
     * first metadata fieldset, then to appending, for sidebars shaped differently — e.g. a
     * Single with its meta fields hidden renders only the notes field.
     */
    private function injectSidebarRow(string $html, string $row): string
    {
        // Prefer just before the notes field. Its wrapper carries a random id, but the
        // textarea's name="notes" is stable; walk back to the enclosing `.field` wrapper
        // (the nested elements between them are `.heading`/`.input`, never `.field`).
        // Match the wrapper by class token rather than by literal `<div class="field`:
        // Yii sorts `id` ahead of `class`, so Craft's own rows render as
        // `<div id="…" class="field …">` and a literal match would only ever land on
        // hand-rolled markup from other plugins further up the sidebar.
        $notesPos = strpos($html, 'name="notes"');
        if ($notesPos !== false) {
            $before = substr($html, 0, $notesPos);
            if (preg_match_all('/<div\b[^>]*\bclass="(?:[^"]*\s)?field(?:\s[^"]*)?"/', $before, $matches, PREG_OFFSET_CAPTURE)) {
                $wrapperPos = end($matches[0])[1];
                return substr($html, 0, $wrapperPos) . $row . substr($html, $wrapperPos);
            }
        }

        // No notes field (e.g. a revision): sit above the first metadata fieldset instead.
        // Spliced by offset rather than preg_replace, since $row contains JS with `$`
        // sequences that would be read as backreferences in a replacement string.
        $fieldsetPos = strpos($html, '<fieldset>');
        if ($fieldsetPos !== false) {
            return substr($html, 0, $fieldsetPos) . $row . substr($html, $fieldsetPos);
        }

        return $html . $row;
    }

    private function entrySectionHasUrls(Entry $entry, bool $checkSectionFilter = true): bool
    {
        $section = $entry->getSection();
        if (!$section) {
            return false;
        }
        $siteSettings = $section->getSiteSettings();
        if (empty($siteSettings[$entry->siteId]) || !$siteSettings[$entry->siteId]->hasUrls) {
            return false;
        }
        if ($checkSectionFilter) {
            // Match on section handle (used by the settings UI and the DUB_SECTIONS env var).
            // UIDs are still accepted for robustness against handle renames.
            $allowedSections = $this->getEnabledSections();
            if (
                !in_array('*', $allowedSections, true) &&
                !in_array($section->uid, $allowedSections, true) &&
                !in_array($section->handle, $allowedSections, true)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolves the enabled sections: the DUB_SECTIONS env var (comma-separated section
     * handles) when set, otherwise the stored setting. Values may be '*', handles, or UIDs.
     *
     * @return list<string>
     */
    private function getEnabledSections(): array
    {
        $env = App::env('DUB_SECTIONS');
        if ($env !== null && $env !== '') {
            return array_map('trim', explode(',', $env));
        }
        return (array)$this->getSettings()->sections;
    }
}
