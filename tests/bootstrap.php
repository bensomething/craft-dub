<?php

/**
 * Minimal bootstrap for the unit suite.
 *
 * These tests deliberately avoid booting Craft — there's no DB, no config and no plugin
 * instance. Only Yii's autoloader and the `Craft` class are loaded, which is enough for
 * the framework-free logic (settings normalisation, URL matching, the Guzzle layer).
 * Anything that genuinely needs a live Craft app belongs in an integration suite instead.
 */

define('CRAFT_TESTS', true);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';

Yii::setAlias('@craft', dirname(__DIR__) . '/vendor/craftcms/cms/src');
Yii::setAlias('@bensomething/craftdub', dirname(__DIR__) . '/src');

// A bare console app so validators can resolve translation messages. It has no DB,
// cache or request — touching those from a unit test is a signal the test belongs elsewhere.
new yii\console\Application([
    'id' => 'craft-dub-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        'i18n' => [
            'translations' => [
                '*' => ['class' => yii\i18n\PhpMessageSource::class],
            ],
        ],
    ],
]);
