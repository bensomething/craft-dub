<?php

namespace bensomething\craftdub\utilities;

use bensomething\craftdub\Plugin;
use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;

/**
 * The control panel counterpart to the `dub/check` console command.
 *
 * Same service call, same three findings. This exists for installs where the people who look
 * after the links have no terminal, which is most of them: the command is the only place the
 * plugin ever admits a link has gone missing, and until now that admission was only reachable
 * over SSH.
 *
 * Craft gates this behind its own `utility:dub-links` permission, so it stays invisible to
 * everyone until an admin hands it out.
 */
class CheckLinks extends Utility
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('dub', 'Dub Links');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'dub-links';
    }

    /**
     * @inheritdoc
     *
     * The Dub mark, not the plugin's icon.svg. Control panel icons are drawn in the current
     * text colour, and icon.svg is the plugin store's version, which carries its own fills:
     * a dark disc and a white glyph that stay dark and white whichever theme is on.
     *
     * Derived from this file's own location rather than a plugin alias, which fails silently:
     * Cp::iconSvg() catches the lookup, logs a warning and returns an empty string, and the
     * utilities controller then falls back to the default icon. A wrong path is an invisible
     * bug, so there's no path to get wrong.
     */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/dub-logo.svg';
    }

    /**
     * @inheritdoc
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws Exception
     */
    public static function contentHtml(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return Craft::$app->getView()->renderTemplate('dub/_utility.twig', [
            'hasApiKey' => !empty(Craft::parseEnv($settings->apiKey)),
            'settingsUrl' => UrlHelper::cpUrl('settings/plugins/dub'),
            // The repair path writes to Dub, so it's offered on the same permission that lets
            // someone rename a link from an entry. Without it the screen still reports.
            'canRepair' => Plugin::canManageLinks(),
        ]);
    }
}
