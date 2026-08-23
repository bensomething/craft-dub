<?php

namespace bensomething\craftdub\console\controllers;

use bensomething\craftdub\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Adopts pre-existing Dub links into the plugin.
 *
 * Matches links already in your Dub workspace to Craft entries by the path of their
 * destination URL, stamps each entry's externalId onto the link, and records it
 * locally so the plugin manages it going forward. Useful when installing the plugin
 * on a site that already has Dub links for its content.
 */
class AdoptController extends Controller
{
    /**
     * @var int How many unmatched links to list before summarising the rest.
     */
    private const UNMATCHED_SHOWN = 10;

    /**
     * @var bool Report what would be adopted without changing anything.
     */
    public bool $dryRun = false;

    /**
     * @var bool List every unmatched link rather than the first few.
     */
    public bool $showUnmatched = false;

    /**
     * @var string|null Comma-separated prefix rewrites (from=to) applied to a link's
     * destination path as a fallback when the raw path matches no entry.
     * Example: --rewrite="/areas-stages/=/venues/"
     */
    public ?string $rewrite = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'rewrite', 'showUnmatched']);
    }

    /**
     * @return array<string, string>
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun', 'r' => 'rewrite']);
    }

    /**
     * Scans the Dub workspace and adopts links matching Craft entries.
     */
    public function actionIndex(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        if (empty(Craft::parseEnv($settings->apiKey))) {
            $this->stderr("No Dub API key configured.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if ($this->dryRun) {
            $this->stdout("Dry run — no changes will be made.\n\n", Console::FG_YELLOW);
        } elseif ($this->interactive && !$this->confirm('Match Dub workspace links to entries and stamp externalId on them?')) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        $rewrites = [];
        foreach (array_filter(explode(',', (string)$this->rewrite)) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                $rewrites[] = [strtolower(trim($parts[0])), strtolower(trim($parts[1]))];
            }
        }

        $summary = Plugin::getInstance()->dub->adoptLinks($this->dryRun, $rewrites, function(string $status, string $message): void {
            switch ($status) {
                case 'adopted':
                    $this->stdout($this->dryRun ? '✓ would adopt ' : '✓ adopted    ', Console::FG_GREEN);
                    $this->stdout("$message\n");
                    break;
                case 'ambiguous':
                    $this->stdout('? ambiguous  ', Console::FG_YELLOW);
                    $this->stdout("$message\n");
                    break;
                case 'error':
                    $this->stderr('✗ error      ', Console::FG_RED);
                    $this->stderr("$message\n");
                    break;
                // 'skipped' (already linked) and 'unmatched' are quiet during the run;
                // they're summarised below.
            }
        });

        if ($summary['error'] !== null) {
            $this->stderr("\nDub API error: {$summary['error']}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\n");
        $this->stdout(sprintf(
            "%d adopted, %d already linked, %d ambiguous, %d unmatched, %d failed.\n",
            $summary['adopted'],
            $summary['skipped'],
            $summary['ambiguous'],
            count($summary['unmatched']),
            $summary['failed'],
        ), Console::FG_CYAN);

        if (!empty($summary['unmatched'])) {
            // These lines are here to help spot a link that should have matched, which they
            // can't do buried in hundreds of unrelated ones. A workspace holding several
            // domains, which is what per-site domains make normal, reaches that easily.
            $unmatched = $summary['unmatched'];
            $shown = $this->showUnmatched ? $unmatched : array_slice($unmatched, 0, self::UNMATCHED_SHOWN);
            $hidden = count($unmatched) - count($shown);

            $this->stdout("\nUnmatched Dub links (no entry found for the destination path):\n", Console::FG_YELLOW);
            foreach ($shown as $u) {
                $this->stdout("  - $u\n");
            }

            if ($hidden > 0) {
                $this->stdout(sprintf(
                    "  … and %d more. Pass --show-unmatched to list them all.\n",
                    $hidden,
                ), Console::FG_GREY);
            }
        }

        return $summary['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
