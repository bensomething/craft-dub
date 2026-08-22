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
                case 'stale':
                    $this->stdout('· stale      ', Console::FG_YELLOW);
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

        // Detections first, then what was done about them — a row that drifted and couldn't be
        // repaired appears once on each side of the arrow rather than twice in one list.
        $this->stdout("\n");
        $this->stdout(sprintf(
            "%d %s: %d ok, %d missing, %d drifted, %d stale, %d unreadable.\n",
            $summary['checked'],
            $summary['checked'] === 1 ? 'link' : 'links',
            $summary['ok'],
            $summary['missing'],
            $summary['drifted'],
            $summary['stale'],
            $summary['unreadable'],
        ), Console::FG_CYAN);

        if ($summary['stale']) {
            // Not repaired here on purpose — see staleAgainstCraft(). resave/entries sends one
            // request per link that has moved and nothing for the rest.
            $this->stdout("\nStale links are fixed by saving their entries: php craft resave/entries\n");
        }

        if ($this->fix) {
            $this->stdout(sprintf(
                "  → %d repaired, %d could not be repaired.\n",
                $summary['repaired'],
                $summary['unrepaired'],
            ), Console::FG_CYAN);
        } elseif ($summary['missing'] || $summary['drifted']) {
            $this->stdout("\nRun with --fix to recreate the missing links and reconcile the drifted ones.\n");
        }

        // Anything still unresolved is worth a non-zero exit, so this can run from cron or CI
        // without its output having to be read. A link that was repaired is resolved.
        $unresolved = $summary['unreadable'] + $summary['stale'] + ($this->fix
            ? $summary['unrepaired']
            : $summary['missing'] + $summary['drifted']);

        return $unresolved > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
