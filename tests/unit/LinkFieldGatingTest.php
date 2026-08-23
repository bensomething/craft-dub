<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers which saves are allowed to read the Dub fields out of the request body.
 */
class LinkFieldGatingTest extends TestCase
{
    private function readsLinkFields(bool $isConsoleRequest, bool $isPropagating, bool $canManage): bool
    {
        $method = new ReflectionMethod(Plugin::class, 'readsLinkFields');
        $method->setAccessible(true);

        return $method->invoke(null, $isConsoleRequest, $isPropagating, $canManage);
    }

    #[DataProvider('gatingProvider')]
    public function testTheRequestIsOnlyReadOnAnEditorsOwnSave(
        bool $isConsoleRequest,
        bool $isPropagating,
        bool $canManage,
        bool $expected,
    ): void {
        $this->assertSame($expected, $this->readsLinkFields($isConsoleRequest, $isPropagating, $canManage));
    }

    private function actsOnLifecycleOf(bool $isDraft, bool $isRevision): bool
    {
        $method = new ReflectionMethod(Plugin::class, 'actsOnLifecycleOf');
        $method->setAccessible(true);

        return $method->invoke(null, $isDraft, $isRevision);
    }

    #[DataProvider('lifecycleProvider')]
    public function testOnlyTheCanonicalEntrysLifecycleTouchesItsLinks(
        bool $isDraft,
        bool $isRevision,
        bool $expected,
    ): void {
        $this->assertSame($expected, $this->actsOnLifecycleOf($isDraft, $isRevision));
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function lifecycleProvider(): array
    {
        return [
            'the canonical entry' => [false, false, true],
            // Craft hard-deletes the provisional draft after every CP save. Because the delete
            // path resolves getCanonicalId(), acting on that cleanup deleted the entry's live
            // Dub link and its local row moments after the save that created them.
            'a provisional draft being cleaned up after a save' => [true, false, false],
            'a revision being pruned' => [false, true, false],
        ];
    }

    /** @return array<string, array{bool, bool, bool, bool}> */
    public static function gatingProvider(): array
    {
        return [
            'the editor saving the entry they are on' => [false, false, true, true],
            // The server-side half of the permission. Rendering the field read-only stops the
            // honest path only: without this the params still arrive from a hand-written
            // request, and a link could be renamed or deleted by someone with no right to.
            'an editor without the manage permission' => [false, false, false, false],
            // Propagation re-enters the before-save handler in the same request, so the body
            // params are still readable — but the custom key belongs to the originating site.
            // Replaying it PATCHes this site's link with a key Dub already assigned to the
            // other one on the same domain, and the 4xx fails the whole entry save.
            'a propagated site element' => [false, true, true, false],
            'a console save, which has no request' => [true, false, true, false],
            'a console save that also propagates' => [true, true, true, false],
            'every reason at once' => [true, true, false, false],
        ];
    }
}
