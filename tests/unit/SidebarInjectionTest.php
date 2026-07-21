<?php

namespace bensomething\craftdub\tests\unit;

use bensomething\craftdub\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SidebarInjectionTest extends TestCase
{
    private function inject(string $html): string
    {
        $reflection = new ReflectionClass(Plugin::class);
        $plugin = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('injectSidebarRow');
        $method->setAccessible(true);

        return $method->invoke($plugin, $html, '[ROW]');
    }

    public function testRowSitsBeforeTheNotesFieldWrapper(): void
    {
        // Craft renders `id` before `class` (Yii's attribute order), so the wrapper is
        // never a bare `<div class="field">`.
        $html = '<fieldset><div id="a" class="field">Slug</div></fieldset>'
            . '<div id="notes-field" class="field"><textarea name="notes"></textarea></div>';

        $this->assertStringContainsString('[ROW]<div id="notes-field"', $this->inject($html));
    }

    public function testRowIsNotSplicedIntoAnotherPluginsHandRolledFieldset(): void
    {
        // Regression: a literal `<div class="field` match landed inside the Bluesky
        // module's fieldset, which is the only markup emitting `class` first.
        $html = '<fieldset><legend>Bluesky</legend>'
            . '<div class="field">Posted</div><div class="field">Standard.site</div>'
            . '</fieldset>'
            . '<fieldset><legend>Status</legend><div id="s" class="field">Enabled</div></fieldset>'
            . '<div id="notes-field" class="field"><textarea name="notes"></textarea></div>';

        $result = $this->inject($html);

        $this->assertStringContainsString('[ROW]<div id="notes-field"', $result);
        $this->assertStringNotContainsString('[ROW]<div class="field">Standard.site', $result);
    }

    public function testFieldClassIsMatchedAsAWholeToken(): void
    {
        $html = '<div id="a" class="field-status">Status</div>'
            . '<div id="notes-field" class="field first"><textarea name="notes"></textarea></div>';

        $this->assertStringContainsString('[ROW]<div id="notes-field"', $this->inject($html));
    }

    public function testFallsBackToTheFirstFieldsetWhenThereIsNoNotesField(): void
    {
        $html = '<fieldset><div id="a" class="field">Slug</div></fieldset>';

        $this->assertStringContainsString('[ROW]<fieldset>', $this->inject($html));
    }

    public function testAppendsWhenTheSidebarHasNeitherNotesNorAFieldset(): void
    {
        $this->assertSame('<div>Nothing</div>[ROW]', $this->inject('<div>Nothing</div>'));
    }
}
