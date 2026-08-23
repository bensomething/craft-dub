<?php

namespace bensomething\craftdub\console\controllers;

use bensomething\craftdub\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Checks that this site can actually talk to Dub.
 *
 * Creates a throwaway link, reads it back, fetches its QR code, and deletes it again. The write
 * is the point: an API key with no write scope, or a workspace at its link limit, passes every
 * read and then fails the first time an editor saves an entry.
 *
 * Nothing else answers this. dub/check only inspects links that already exist, so on a fresh
 * install it has nothing to report either way.
 */
class TestController extends Controller
{
    /**
     * Runs a live round trip against Dub and reports each step.
     */
    public function actionIndex(): int
    {
        $steps = Plugin::getInstance()->dub->runTest();
        $failed = 0;

        foreach ($steps as $step) {
            if ($step['ok']) {
                $this->stdout('✓ ', Console::FG_GREEN);
            } else {
                $this->stdout('✗ ', Console::FG_RED);
                $failed++;
            }

            $this->stdout(str_pad($step['step'], 18));
            $this->stdout($step['detail'] . "\n");
        }

        $this->stdout("\n");

        if ($failed > 0) {
            // stdout, not stderr. The two are buffered differently, so a piped run printed the
            // summary before the steps it was summarising. The non-zero exit is what a script
            // should be reading anyway.
            $this->stdout("Whoops, Dub isn't connected properly yet.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Nice, everything works.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
