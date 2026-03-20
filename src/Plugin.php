<?php

namespace bensomething\craftdub;

use bensomething\craftdub\models\Settings;
use bensomething\craftdub\services\DubService;
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

        return Craft::$app->view->renderTemplate('_dub/_settings.twig', [
            'plugin' => $this,
            'settings' => $settings,
            'domains' => $domains,
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

            if ($entry->siteId !== Craft::$app->sites->getPrimarySite()->id) {
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
            $hasExistingLink = Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId()) !== null;

            if ($customKey !== null || $hasExistingLink) {
                $error = Plugin::getInstance()->dub->prepareLink($entry, $customKey);
                if ($error) {
                    $entry->addError('dub', Craft::t('_dub', 'Dub · {error}', ['error' => $error]));
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

            if ($entry->siteId !== Craft::$app->sites->getPrimarySite()->id) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry)) {
                return;
            }

            if ($entry->getStatus() === Entry::STATUS_LIVE) {
                Plugin::getInstance()->dub->commitLink($entry->id);
            } else {
                Plugin::getInstance()->dub->deactivateLink($entry);
            }
        });

        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            Plugin::getInstance()->dub->deleteLink($entry);
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;

            if ($entry->getIsRevision()) {
                return;
            }

            if (!$this->entrySectionHasUrls($entry)) {
                return;
            }

            $settings = Plugin::getInstance()->getSettings();
            $hasApiKey = !empty(Craft::parseEnv($settings->apiKey));
            $shortLink = Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId());

            $settingsUrl = !$hasApiKey ? UrlHelper::cpUrl('settings/plugins/_dub') : null;

            $currentKey = $shortLink ? ltrim(parse_url($shortLink, PHP_URL_PATH), '/') : null;

            $row = Craft::$app->getView()->renderTemplate('_dub/_entry-sidebar.twig', [
                'shortLink' => $shortLink,
                'currentKey' => $currentKey,
                'hasApiKey' => $hasApiKey,
                'settingsUrl' => $settingsUrl,
            ]);
            $event->html = preg_replace('/<fieldset>/', $row . '<fieldset>', $event->html, 1);
        });
    }

    private function entrySectionHasUrls(Entry $entry): bool
    {
        $section = $entry->getSection();
        if (!$section) {
            return false;
        }
        $primarySiteId = Craft::$app->sites->getPrimarySite()->id;
        $siteSettings = $section->getSiteSettings();
        return !empty($siteSettings[$primarySiteId]) && $siteSettings[$primarySiteId]->hasUrls;
    }
}
