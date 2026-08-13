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
    private function readsLinkFields(bool $isConsoleRequest, bool $isPropagating): bool
    {
        $method = new ReflectionMethod(Plugin::class, 'readsLinkFields');
        $method->setAccessible(true);

        return $method->invoke(null, $isConsoleRequest, $isPropagating);
    }

    #[DataProvider('gatingProvider')]
    public function testTheRequestIsOnlyReadOnAnEditorsOwnSave(
        bool $isConsoleRequest,
        bool $isPropagating,
        bool $expected,
    ): void {
        $this->assertSame($expected, $this->readsLinkFields($isConsoleRequest, $isPropagating));
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function gatingProvider(): array
    {
        return [
            'the editor saving the entry they are on' => [false, false, true],
            // Propagation re-enters the before-save handler in the same request, so the body
            // params are still readable — but the custom key belongs to the originating site.
            // Replaying it PATCHes this site's link with a key Dub already assigned to the
            // other one on the same domain, and the 4xx fails the whole entry save.
            'a propagated site element' => [false, true, false],
            'a console save, which has no request' => [true, false, false],
            'a console save that also propagates' => [true, true, false],
        ];
    }
}
