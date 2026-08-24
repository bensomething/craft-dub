<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\console\controllers\CheckController;
use bensomething\craftdub\controllers\CheckController as WebCheckController;
use bensomething\craftdub\utilities\CheckLinks;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The two surfaces that read a check result: the console command, which puts the label and the
 * detail back on one line, and the utility screen, which keeps them apart in their own columns.
 *
 * Both read the same payload from DubService::checkLinks(). These pin the shape of that payload
 * from either end, so a change to one surface can't quietly leave the other reading a key that
 * has moved.
 */
class CheckReportingTest extends TestCase
{
    /**
     * Loads the console controller with deprecations muted.
     *
     * craft\console\Controller declares an implicitly nullable parameter, which PHP 8.4
     * deprecates and this suite's Yii error handler turns into an exception. It's Craft's
     * signature, not the plugin's, and it only fires on the first autoload of the class.
     */
    public static function setUpBeforeClass(): void
    {
        set_error_handler(static fn(): bool => true, E_DEPRECATED);

        try {
            class_exists(CheckController::class);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array{label: string, detail: string} $result
     */
    private function line(array $result): string
    {
        $method = new ReflectionMethod(CheckController::class, 'line');
        $method->setAccessible(true);

        return $method->invoke(null, $result);
    }

    private function statusHtml(string $status): ?string
    {
        $method = new ReflectionMethod(WebCheckController::class, 'statusHtml');
        $method->setAccessible(true);

        return $method->invoke(null, $status);
    }

    public function testALabelAndDetailAreJoined(): void
    {
        $this->assertSame(
            'dub.sh/abc (entry 4, site 1) — gone from Dub',
            $this->line(['label' => 'dub.sh/abc (entry 4, site 1)', 'detail' => 'gone from Dub']),
        );
    }

    public function testARepairWithNothingToAddIsJustItsLabel(): void
    {
        // A repaired link reports no detail. Composing unconditionally would leave every
        // successful repair printing a trailing separator with nothing after it.
        $this->assertSame(
            'dub.sh/abc (entry 4, site 1)',
            $this->line(['label' => 'dub.sh/abc (entry 4, site 1)', 'detail' => '']),
        );
    }

    public function testEachStatusGetsItsOwnPill(): void
    {
        foreach (['missing' => 'Missing', 'drifted' => 'Drifted', 'stale' => 'Stale', 'repaired' => 'Repaired'] as $status => $label) {
            $html = (string)$this->statusHtml($status);
            $this->assertStringContainsString($label, $html, "$status should be labelled $label");
            $this->assertStringContainsString('status-label', $html, "$status should render as a pill");
        }
    }

    public function testAnUnrecognisedStatusReadsAsAFailure(): void
    {
        // 'failed' is what checkLinks() reports both for an unreadable link and for a repair
        // that didn't take, and the match arm covering it is the default. Anything the service
        // grows later lands there too, which is the safe side to land on.
        $this->assertStringContainsString('Failed', (string)$this->statusHtml('failed'));
        $this->assertStringContainsString('Failed', (string)$this->statusHtml('something-new'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function addFinding(array $rows, string $key, string $status, string $detail = '', ?string $shortLink = null): array
    {
        $method = new ReflectionMethod(WebCheckController::class, 'addFinding');
        $method->setAccessible(true);

        return $method->invoke(null, $rows, [
            'key' => $key,
            'status' => $status,
            'outcome' => null,
            'detail' => $detail,
            'outcomeDetail' => '',
            'shortLink' => $shortLink,
            'entryId' => 4,
            'siteId' => 1,
        ]);
    }

    public function testARepairFoldsIntoTheRowThatFoundIt(): void
    {
        // checkLinks() reports a repaired link twice, the detection then the outcome. Two table
        // rows naming the same link read as two problems rather than one problem answered.
        $rows = $this->addFinding([], '4-1', 'drifted', 'Dub has A → B', 'https://dub.sh/old');
        $rows = $this->addFinding($rows, '4-1', 'repaired', '', 'https://dub.sh/new');

        $this->assertCount(1, $rows);
        $this->assertSame('drifted', $rows[0]['status']);
        $this->assertSame('repaired', $rows[0]['outcome']);
        $this->assertSame('Dub has A → B', $rows[0]['detail'], 'the detection detail is what was wrong, and survives the repair');
        $this->assertSame('https://dub.sh/new', $rows[0]['shortLink'], 'a reconciled link may have taken Dub\'s slug');
    }

    public function testAFailedRepairKeepsItsReason(): void
    {
        $rows = $this->addFinding([], '4-1', 'missing', 'gone from Dub');
        $rows = $this->addFinding($rows, '4-1', 'failed', 'duplicate key');

        $this->assertCount(1, $rows);
        $this->assertSame('failed', $rows[0]['outcome']);
        $this->assertSame('gone from Dub', $rows[0]['detail']);
        $this->assertSame('duplicate key', $rows[0]['outcomeDetail']);
    }

    public function testAStaleRowDoesNotSwallowTheFindingAfterIt(): void
    {
        // A stale link never reaches the repair branch, so nothing that follows it is its
        // outcome. Folding on the key alone would have eaten the next link's row.
        $rows = $this->addFinding([], '4-1', 'stale', 'entry now at https://x.test');
        $rows = $this->addFinding($rows, '4-1', 'failed', 'unreadable');

        $this->assertCount(2, $rows);
    }

    public function testFindingsForDifferentLinksStayApart(): void
    {
        $rows = $this->addFinding([], '4-1', 'drifted', 'Dub has A → B');
        $rows = $this->addFinding($rows, '9-1', 'repaired');

        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['outcome']);
    }

    public function testAnOutcomeIsFoldedOnlyOnce(): void
    {
        $rows = $this->addFinding([], '4-1', 'drifted', 'Dub has A → B');
        $rows = $this->addFinding($rows, '4-1', 'repaired');
        $rows = $this->addFinding($rows, '4-1', 'repaired');

        $this->assertCount(2, $rows, 'a second outcome opens its own row rather than overwriting the first');
    }

    public function testARepairOutcomeIsLabelledApartFromAnUnreadableLink(): void
    {
        // Both arrive as 'failed'. Two pills reading Failed in one table would be describing
        // two different things.
        $method = new ReflectionMethod(WebCheckController::class, 'outcomeHtml');
        $method->setAccessible(true);

        $this->assertStringContainsString('Repaired', (string)$method->invoke(null, 'repaired'));
        $this->assertStringContainsString('Not repaired', (string)$method->invoke(null, 'failed'));
    }

    public function testDetailUrlsAreSetInCodeAndEverythingElseIsEscaped(): void
    {
        $method = new ReflectionMethod(WebCheckController::class, 'detailHtml');
        $method->setAccessible(true);

        $html = (string)$method->invoke(null, '<b>x</b> https://fg.wtf/a?b=1&c=2');

        $this->assertStringContainsString('<code>https://fg.wtf/a?b=1&amp;c=2</code>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testTheUtilityIconResolvesToAFileThatExists(): void
    {
        // Cp::iconSvg() swallows a bad path, logs a warning and returns an empty string, and
        // the utilities controller then falls back to Craft's default icon. A wrong path here
        // ships as a wrong icon and nothing else, so this is the only place it gets caught.
        $this->assertFileExists((string)CheckLinks::icon());
    }

    public function testTheUtilityIdIsTheHandleTheControllerGatesOn(): void
    {
        // The run action requires 'utility:' . CheckLinks::id(), which is the permission Craft
        // registers for it. Renaming the id renames the permission, and every group that held
        // the old one silently loses the screen.
        $this->assertSame('dub-links', CheckLinks::id());
    }
}
