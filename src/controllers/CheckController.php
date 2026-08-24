<?php

namespace bensomething\craftdub\controllers;

use bensomething\craftdub\Plugin;
use bensomething\craftdub\services\DubService;
use bensomething\craftdub\utilities\CheckLinks;
use Craft;
use craft\elements\Entry;
use craft\enums\Color;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * Backs the Dub Links utility, which is the `dub/check` command with a screen in front of it.
 *
 * @phpstan-import-type CheckResult from DubService
 * @phpstan-import-type CheckSummary from DubService
 * @phpstan-type Finding array{key: string, status: string, outcome: string|null, detail: string, outcomeDetail: string, shortLink: string|null, entryId: int, siteId: int}
 */
class CheckController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * How many links one request works through.
     *
     * A check is one Dub request per link, in series, so the only thing that decides how long
     * a request runs is how many links it is given. Twenty five of them is a few seconds even
     * on a slow day, which is comfortably inside every proxy timeout worth naming, and the
     * screen loops until the workspace is done. The alternative, a single request sized by the
     * workspace, is the one that gets cut off, and a repair cut off halfway is silent about
     * which half it finished.
     */
    private const BATCH_SIZE = 2;

    // Public Methods
    // =========================================================================

    /**
     * Checks one slice of the recorded links and returns its rows as rendered HTML.
     *
     * The work is the service's, exactly as the console command calls it, so the two surfaces
     * cannot report different things. What's added here is the naming: a row gets its entry's
     * title and a link to it, rather than the `entry 41, site 2` the terminal has to settle for.
     *
     * One request is one slice. The screen calls this until `done`, carrying the running totals
     * with it, and renders each slice's rows as they land. That is what keeps the findings so
     * far on screen if a later slice fails, rather than losing the lot to one timeout.
     *
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('utility:' . CheckLinks::id());

        $fix = (bool)$this->request->getBodyParam('fix');

        // Reporting is read-only, repairing writes to Dub, so they're gated apart. The second
        // check is the one that matters: the utility hides the repair switch from anyone
        // without the permission, but a hidden control is not a gate.
        if ($fix) {
            $this->requirePermission(Plugin::PERMISSION_MANAGE_LINKS);
        }

        $offset = max(0, (int)$this->request->getBodyParam('offset', 0));

        // A slice is sized to finish well inside any timeout, but PHP's own limit is per
        // request and knows nothing about that, so it still gets lifted.
        App::maxPowerCaptain();

        /** @var list<Finding> $rows */
        $rows = [];

        $batch = Plugin::getInstance()->dub->checkLinks($fix, function(string $status, array $result) use (&$rows): void {
            /** @var CheckResult $result */
            $record = $result['record'];

            $rows = self::addFinding($rows, [
                'key' => $record->entryId . '-' . $record->siteId,
                'status' => $status,
                'outcome' => null,
                'detail' => $result['detail'],
                'outcomeDetail' => '',
                // Read at callback time rather than kept: a reconciled link may have taken on
                // Dub's slug, and the slug it arrived with would hide the thing that changed.
                'shortLink' => $record->shortLink,
                'entryId' => (int)$record->entryId,
                'siteId' => (int)$record->siteId,
            ]);
        }, $offset, self::BATCH_SIZE);

        // The slice's own counts, folded into what the screen has carried so far. Totals come
        // back off the request rather than being held server-side: they are nine integers that
        // are only ever shown back to the person who sent them, so a session or a cache entry
        // would be state to expire for no gain.
        $totals = self::mergeTotals(self::postedTotals($this->request->getBodyParam('totals')), $batch);

        // A short slice means the table ran out, which is the only reliable end signal. Reading
        // it off a count taken now would race with anything saved during the run.
        $done = $batch['error'] !== null || $batch['checked'] < self::BATCH_SIZE;

        return $this->asJson([
            'rowsHtml' => $this->getView()->renderTemplate('dub/_check-rows.twig', [
                'rows' => $this->nameRows($rows),
                'showSite' => Craft::$app->getIsMultiSite(),
            ]),
            // Rendered apart because they land in different places: the outcome goes in the
            // button row, in place of the progress bar, and what to do about it goes above the
            // table it refers to. Both only exist once the last slice is in.
            'resultHtml' => $done
                ? $this->getView()->renderTemplate('dub/_check-result.twig', [
                    'summary' => $totals,
                    'fix' => $fix,
                ])
                : null,
            'fixHtml' => $done
                ? $this->getView()->renderTemplate('dub/_check-fix.twig', [
                    'summary' => $totals,
                    'fix' => $fix,
                    'canRepair' => Plugin::canManageLinks(),
                    // A link Dub wouldn't answer for is usually the key or the workspace, and
                    // the connection test names which. Pointing at it beats describing it.
                    'settingsUrl' => UrlHelper::cpUrl('settings/plugins/dub'),
                ])
                : null,
            'totals' => $totals,
            'checked' => $totals['checked'],
            'total' => Plugin::getInstance()->dub->countLinks(),
            'nextOffset' => $offset + self::BATCH_SIZE,
            'done' => $done,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * The running totals the screen carried into this request, reduced to what they are allowed
     * to be.
     *
     * Only the known counters survive, each cast to a non-negative integer, so a hand-written
     * request cannot put anything into the summary but wrong arithmetic about its own run. The
     * error field is deliberately dropped: it belongs to whichever slice actually failed.
     *
     * @return CheckSummary
     */
    private static function postedTotals(mixed $posted): array
    {
        $totals = self::emptyTotals();

        if (!is_array($posted)) {
            return $totals;
        }

        foreach ($totals as $key => $value) {
            if ($key !== 'error' && isset($posted[$key])) {
                $totals[$key] = max(0, (int)$posted[$key]);
            }
        }

        return $totals;
    }

    /**
     * Adds a slice's counts to the running totals.
     *
     * @param CheckSummary $totals
     * @param CheckSummary $batch
     * @return CheckSummary
     */
    private static function mergeTotals(array $totals, array $batch): array
    {
        foreach ($totals as $key => $value) {
            if ($key !== 'error') {
                $totals[$key] = $value + $batch[$key];
            }
        }

        // The first failure wins. A later slice failing for a second reason doesn't make the
        // first one untrue, and only one line is shown.
        $totals['error'] = $totals['error'] ?? $batch['error'];

        return $totals;
    }

    /**
     * @return CheckSummary
     */
    private static function emptyTotals(): array
    {
        return [
            'checked' => 0,
            'ok' => 0,
            'missing' => 0,
            'drifted' => 0,
            'stale' => 0,
            'unreadable' => 0,
            'repaired' => 0,
            'unrepaired' => 0,
            'error' => null,
        ];
    }

    /**
     * Adds one result to the findings, folding a repair outcome into the row that found it.
     *
     * A repaired link is reported twice: what was found, then what was done about it. The
     * console prints those as two lines, which is right for a stream. A table is not a stream,
     * and two rows naming the same link read as two problems rather than one problem answered.
     *
     * Only the pairing the service can actually produce is folded: a detection that reached the
     * repair branch, immediately followed by that branch's outcome for the same link. An
     * unreadable link never reaches it, and a stale one is deliberately left alone, so neither
     * can swallow the row after it.
     *
     * @param list<Finding> $rows
     * @param Finding $finding
     * @return list<Finding>
     */
    private static function addFinding(array $rows, array $finding): array
    {
        $last = array_key_last($rows);

        if (
            $last !== null &&
            $rows[$last]['key'] === $finding['key'] &&
            $rows[$last]['outcome'] === null &&
            in_array($rows[$last]['status'], ['missing', 'drifted'], true) &&
            in_array($finding['status'], ['repaired', 'failed'], true)
        ) {
            $rows[$last]['outcome'] = $finding['status'];
            // Only a failed repair has anything to add. A successful one says nothing the
            // detection above it hasn't already said.
            $rows[$last]['outcomeDetail'] = $finding['detail'];
            $rows[$last]['shortLink'] = $finding['shortLink'];

            return $rows;
        }

        $rows[] = $finding;

        return $rows;
    }

    /**
     * Attaches each row's entry title, edit URL, site name and status pill.
     *
     * The entries are fetched in one query rather than per row: a check that turns up fifty
     * findings would otherwise be fifty element queries after the fact, on a screen that has
     * already spent fifty HTTP requests getting here.
     *
     * Disabled and trashed entries are included deliberately. A trashed entry keeps its link,
     * archived, and an entry that has just been disabled is exactly the sort of thing a check
     * turns up, so leaving either out would name the finding after an entry it couldn't find.
     *
     * @param list<Finding> $rows
     * @return list<array{statusHtml: string|null, outcomeHtml: string|null, detailHtml: string, outcomeDetailHtml: string|null, shortLink: string|null, title: string, url: string|null, site: string|null}>
     */
    private function nameRows(array $rows): array
    {
        $entries = [];
        $entryIds = array_values(array_unique(array_column($rows, 'entryId')));

        if ($entryIds !== []) {
            $found = Entry::find()
                ->id($entryIds)
                ->siteId('*')
                ->status(null)
                ->trashed(null)
                ->all();

            foreach ($found as $entry) {
                $entries[$entry->id . '-' . $entry->siteId] = $entry;
            }
        }

        $sites = Craft::$app->getSites();

        return array_map(static function(array $row) use ($entries, $sites): array {
            $entry = $entries[$row['entryId'] . '-' . $row['siteId']] ?? null;

            return [
                'statusHtml' => self::statusHtml($row['status']),
                'outcomeHtml' => $row['outcome'] !== null ? self::outcomeHtml($row['outcome']) : null,
                'detailHtml' => self::detailHtml($row['detail']),
                'outcomeDetailHtml' => $row['outcomeDetail'] !== '' ? self::detailHtml($row['outcomeDetail']) : null,
                'shortLink' => $row['shortLink'],
                // An entry the row names but Craft no longer has is a real state: the local row
                // outlives a hard delete that failed to cascade. Say which id it was.
                'title' => $entry?->title ?? Craft::t('dub', 'Entry {id}', ['id' => $row['entryId']]),
                'url' => $entry?->getCpEditUrl(),
                'site' => $sites->getSiteById($row['siteId'])?->getName(),
            ];
        }, $rows);
    }

    /**
     * A finding's detail, with any URL in it set in code.
     *
     * A detail is prose with values dropped into it, and the values are the part worth reading
     * closely: a drifted link reports the two URLs it now sits between. Encoded before it is
     * marked up, so the only tags in the result are the ones added on the line below.
     *
     * Only URLs carrying a scheme are caught. A stale row naming two bare domains is left as
     * prose, which is the safe side of a regex that would otherwise go looking for hostnames
     * inside error messages from Dub.
     */
    private static function detailHtml(string $detail): string
    {
        return (string)preg_replace(
            '#https?://[^\s<]+#',
            '<code>$0</code>',
            Html::encode($detail),
        );
    }

    /**
     * The status pill for one finding.
     *
     * Cp::statusLabelHtml() rather than hand-rolled markup: a bare `.status` span renders as a
     * 10 by 10 dot with the label wrapping one character per line inside it.
     */
    private static function statusHtml(string $status): ?string
    {
        [$color, $label] = match ($status) {
            'missing' => [Color::Red, Craft::t('dub', 'Missing')],
            'drifted' => [Color::Orange, Craft::t('dub', 'Drifted')],
            'stale' => [Color::Amber, Craft::t('dub', 'Stale')],
            'repaired' => [Color::Green, Craft::t('dub', 'Repaired')],
            default => [Color::Red, Craft::t('dub', 'Failed')],
        };

        return Cp::statusLabelHtml(['color' => $color, 'label' => $label]);
    }

    /**
     * The pill for what a repair did, shown beside the pill for what was found.
     *
     * Labelled apart from the detection statuses on purpose. A repair that didn't take reports
     * as 'failed', the same word an unreadable link uses, and two pills reading Failed in one
     * table would be describing two different things.
     */
    private static function outcomeHtml(string $status): ?string
    {
        return $status === 'repaired'
            ? Cp::statusLabelHtml(['color' => Color::Green, 'label' => Craft::t('dub', 'Repaired')])
            : Cp::statusLabelHtml(['color' => Color::Red, 'label' => Craft::t('dub', 'Not repaired')]);
    }
}
