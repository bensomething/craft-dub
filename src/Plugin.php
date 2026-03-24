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
use craft\helpers\UrlHelper;
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
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

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

    protected function settingsHtml(): ?string
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
                    $sectionOptions[] = ['label' => $section->name, 'value' => $section->id];
                    break;
                }
            }
        }

        return Craft::$app->view->renderTemplate('dub/_settings.twig', [
            'plugin' => $this,
            'settings' => $settings,
            'domains' => $domains,
            'sectionOptions' => $sectionOptions,
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

            $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();
            $customKey = !$isConsole ? Craft::$app->getRequest()->getBodyParam('dubCustomKey') ?: null : null;
            $shortLinkPresent = !$isConsole ? Craft::$app->getRequest()->getBodyParam('dubShortLinkPresent') : null;
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

            if (Plugin::getInstance()->dub->isPendingDeletion()) {
                Plugin::getInstance()->dub->commitDeletion();
            } elseif ($entry->getStatus() === Entry::STATUS_LIVE) {
                Plugin::getInstance()->dub->commitLink($entry->id);
            } else {
                Plugin::getInstance()->dub->deactivateLink($entry);
            }
        });

        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->dateDeleted === null) {
                Plugin::getInstance()->dub->deactivateLink($entry);
                return;
            }
            Plugin::getInstance()->dub->deleteLink($entry);
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
            $shortLink = Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId(), $entry->siteId);

            // Only show sidebar if section is enabled or entry already has a short link
            if (!$sectionEnabled && !$shortLink) {
                return;
            }

            $settingsUrl = !$hasApiKey ? UrlHelper::cpUrl('settings/plugins/dub') : null;

            $currentKey = $shortLink ? ltrim(parse_url($shortLink, PHP_URL_PATH), '/') : null;
            $shortLinkDomain = $shortLink ? parse_url($shortLink, PHP_URL_HOST) : null;
            $workspaceId = Plugin::getInstance()->dub->getWorkspaceId();

            $dubDashboardUrl = null;
            if ($workspaceId && $shortLinkDomain && $currentKey && Craft::$app->getUser()->getIsAdmin()) {
                $dubDashboardUrl = 'https://app.dub.co/' . $workspaceId . '/links/' . $shortLinkDomain . '/' . $currentKey;
            }

            $row = Craft::$app->getView()->renderTemplate('dub/_entry-sidebar.twig', [
                'shortLink' => $shortLink,
                'currentKey' => $currentKey,
                'hasApiKey' => $hasApiKey,
                'settingsUrl' => $settingsUrl,
                'sectionEnabled' => $sectionEnabled,
                'isLive' => $entry->getStatus() === Entry::STATUS_LIVE,
                'dubDashboardUrl' => $dubDashboardUrl,
            ]);
            $event->html = preg_replace('/<fieldset>/', $row . '<fieldset>', $event->html, 1);
        });
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
            $allowedSections = $this->getSettings()->sections;
            if (!in_array('*', $allowedSections, true) && !in_array($section->id, $allowedSections, false)) {
                return false;
            }
        }
        return true;
    }
}
