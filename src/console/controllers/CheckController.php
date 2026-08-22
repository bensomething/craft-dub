<?php

namespace bensomething\craftdub\console\controllers;

use bensomething\craftdub\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Checks that the links this plugin has recorded still exist at Dub, and still match.
 *
 * Saving an entry no longer re-sends a link that hasn't moved, which is what makes a resave
 * cheap — but it also means the plugin stops noticing when a link is deleted or edited in the
 * Dub dashboard. The local row keeps rendering a short link that no longer resolves. This is
 * the command that finds those, and with --fix puts them back.
 *
 * `dub/adopt` can't do it: adoption walks the links Dub still has and skips entries that
 * already have a row, so a row pointing at a deleted link is invisible to it.
 */
class CheckController extends Controller
{
    /**
     * @var bool Repair what's found, rather than only reporting it.
     */
    public bool $fix = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['fix']);
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['f' => 'fix']);
    }

    /**
     * Reports every recorded link that's missing from Dub or has drifted from what's recorded.
     */
    public function actionIndex(): int
    {
        if ($this->fix) {
            $this->stdout("Repairing links that are missing or have drifted.\n\n", Console::FG_YELLOW);
            if ($this->interactive && !$this->confirm('Recreate deleted links and re-point edited ones to match Craft?')) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        $summary = Plugin::getInstance()->dub->checkLinks($this->fix, function(string $status, string $message): void {
            switch ($status) {
                case 'missing':
                    $this->stdout('✗ missing    ', Console::FG_RED);
                    $this->stdout("$message\n");
                    break;
                case 'drifted':
                    $this->stdout('~ drifted    ', Console::FG_YELLOW);
                    $this->stdout("$message\n");
                    break;
                case 'repaired':
                    $this->stdout('✓ repaired   ', Console::FG_GREEN);
                    $this->stdout("$message\n");
                    break;
                case 'failed':
                    $this->stderr('! failed     ', Console::FG_RED);
                    $this->stderr("$message\n");
                    break;
            }
        });

        if ($summary['error'] !== null) {
            $this->stderr("\n{$summary['error']}\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("\n");
        $this->stdout(sprintf(
            "%d ok, %d missing, %d drifted, %d repaired, %d failed.\n",
            $summary['ok'],
            $summary['missing'],
            $summary['drifted'],
            $summary['repaired'],
            $summary['failed'],
        ), Console::FG_CYAN);

        if (!$this->fix && ($summary['missing'] || $summary['drifted'])) {
            $this->stdout("\nRun with --fix to recreate the missing links and re-point the drifted ones.\n");
        }

        // A clean report is the only success. Anything left unresolved is worth a non-zero
        // exit so this can be run from cron or CI without the output having to be read.
        $unresolved = $summary['failed'] + ($this->fix
            ? $summary['missing'] + $summary['drifted'] - $summary['repaired']
            : $summary['missing'] + $summary['drifted']);

        return $unresolved > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
