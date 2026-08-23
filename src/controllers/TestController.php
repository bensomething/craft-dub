<?php

namespace bensomething\craftdub\controllers;

use bensomething\craftdub\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Backs the Test button on the plugin's settings screen.
 */
class TestController extends Controller
{
    /**
     * Runs a live round trip against Dub and returns each step as JSON.
     *
     * requireAdmin(false) rather than requireAdmin(): the second also refuses when
     * allowAdminChanges is off, and this changes no configuration. Somewhere with admin changes
     * disabled, usually production, is exactly where you most want to ask whether the API key
     * in that environment works.
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin(false);

        return $this->asJson(['steps' => Plugin::getInstance()->dub->runTest()]);
    }
}
