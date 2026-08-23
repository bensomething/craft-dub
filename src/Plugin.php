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
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
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
    /**
     * Whether a user may see an entry's short link at all.
     *
     * Declared as constants rather than written out where they're used: a mistyped literal
     * passes silently for admins, who hold every permission, and denies everyone else, so the
     * one person likely to test it is the one person who cannot see the bug.
     */
    public const PERMISSION_VIEW_LINKS = 'dub:view-links';

    /**
     * Whether a user may set, change or clear an entry's short link slug.
     */
    public const PERMISSION_MANAGE_LINKS = 'dub:manage-links';

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

        $this->registerPermissions();
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

        // Only sites that can actually hold a link. A site without its own base URL has no
        // entry URLs at all, so prepareLink() never gets as far as a domain for it, and a row
        // offering one would be dead. By the same measure the whole section is pointless
        // unless more than one such site exists, since the default covers the only one.
        $sites = array_values(array_filter(
            Craft::$app->getSites()->getAllSites(),
            static fn($site): bool => $site->hasUrls,
        ));
        $overrides = $settings->getSiteDomains();

        // One list for every check below. getDomains() is a live API call with no cache, so
        // asking per site would be a request per row on every render of this screen.
        $available = array_column($domains, 'slug');

        $domainWarning = $settings->domainWarning($settings->domain, $available);

        // Resolved, so a row left blank shows the domain it will actually use rather than the
        // variable name standing in for it.
        $defaultDomain = Craft::parseEnv($settings->domain);

        $siteDomainWarnings = [];
        foreach ($sites as $site) {
            $warning = $settings->domainWarning($overrides[$site->uid] ?? '', $available);
            if ($warning !== null) {
                $siteDomainWarnings[] = $site->name . ': ' . $warning;
            }
        }

        // With every site overridden the default is unreachable, which is worth saying rather
        // than leaving someone to wonder why editing it changes nothing.
        $allSitesOverridden = $sites !== [] && !array_filter(
            $sites,
            static fn($site): bool => ($overrides[$site->uid] ?? '') === '',
        );

        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('dub/_settings.twig', [
            'plugin' => $this,
            'settings' => $settings,
            'domains' => $domains,
            'sectionOptions' => $sectionOptions,
            'enabledSections' => $this->getEnabledSections(),
            'sectionsOverridden' => $sectionsEnv !== null && $sectionsEnv !== '',
            'sites' => $sites,
            'siteDomains' => $overrides,
            'allSitesOverridden' => $allSitesOverridden,
            'domainWarning' => $domainWarning,
            'defaultDomain' => is_string($defaultDomain) ? $defaultDomain : '',
            'siteDomainWarning' => $siteDomainWarnings !== [] ? implode(' ', $siteDomainWarnings) : null,
            'showSiteDomains' => count($sites) > 1,
        ]);
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('dub', 'Dub Links'),
                    'permissions' => [
                        self::PERMISSION_VIEW_LINKS => [
                            'label' => Craft::t('dub', 'View the Short Link panel'),
                            'nested' => [
                                self::PERMISSION_MANAGE_LINKS => [
                                    'label' => Craft::t('dub', 'Edit the short link slug'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Whether the current user may see short links.
     *
     * One gate per question, called from every surface that asks it, rather than the check
     * written out at each. Separate copies drift, and the one that drifts is the one nobody
     * looks at.
     *
     * Answers true when there is no user, which is a console command or a queue job. Those run
     * with shell or system access already, and the permission system has nobody to ask about.
     * The entry save path reads no request input in that case either, so there is nothing a
     * permission could protect there.
     */
    public static function canViewLinks(): bool
    {
        return self::hasPermission(self::PERMISSION_VIEW_LINKS);
    }

    /**
     * Whether the current user may set, change or clear a short link slug.
     */
    public static function canManageLinks(): bool
    {
        return self::hasPermission(self::PERMISSION_MANAGE_LINKS);
    }

    private static function hasPermission(string $permission): bool
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity === null || $identity->can($permission);
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
                self::canManageLinks(),
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

            if (!self::canViewLinks()) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry, false)) {
                return;
            }

            $sectionEnabled = $this->entrySectionHasUrls($entry, true);
            $settings = Plugin::getInstance()->getSettings();
            $hasApiKey = !empty(Craft::parseEnv($settings->apiKey));
            // The domain this entry's own site writes to, so the heading names the domain the
            // link will actually be created on rather than the install-wide default.
            $domain = $settings->domainForSite($entry->siteId);
            $hasDomain = $domain !== '';
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
                'canManage' => self::canManageLinks(),
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
     * They don't for a user without the manage permission. Rendering the field read-only is
     * not enough on its own: the params would still be posted by anyone willing to write the
     * request by hand, and ignoring them here is what actually stops a link being renamed or
     * deleted. It also means an editor without the permission can still save entries normally,
     * and their links keep following their slugs, because that path reads no request input.
     *
     * They don't on a console save, which has no request behind it. And they don't on a
     * propagated site element: propagation re-enters the before-save handler inside the same
     * request, so the params are still readable, but they describe the site the editor was
     * actually on. Reusing the custom key would PATCH this site's link with a key Dub has
     * already assigned to the originating site on the same domain, and the resulting 4xx
     * fails the entire entry save through Craft's cross-site validation.
     */
    private static function readsLinkFields(bool $isConsoleRequest, bool $isPropagating, bool $canManage): bool
    {
        return !$isConsoleRequest && !$isPropagating && $canManage;
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
