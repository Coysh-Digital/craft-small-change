<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use Craft;
use Twig\Source;

/**
 * Twig errors only show when a page is opened, so parse every template the plugin ships up front.
 */
final class TemplatesTest extends Unit
{
    /**
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];

        foreach (['src/templates', 'example-templates'] as $folder) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $folder, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'twig') {
                    $cases[ltrim(str_replace($root, '', $file->getPathname()), '/')] = [$file->getPathname()];
                }
            }
        }

        ksort($cases);

        return $cases;
    }

    /**
     * @dataProvider templates
     */
    public function testTheTemplateParses(string $path): void
    {
        $twig = Craft::$app->getView()->getTwig();
        $source = new Source((string) file_get_contents($path), basename($path), $path);

        // Throws a SyntaxError, naming the line, if there is a mistake.
        $twig->parse($twig->tokenize($source));

        $this->assertTrue(true);
    }

    public function testThereAreTemplatesToCheck(): void
    {
        $this->assertGreaterThan(30, count(self::templates()));
    }

    public function testTheProductTypeFormHasAnInputForEverythingTheSaveActionReads(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $form = (string) file_get_contents($root . '/templates/settings/product-types/_edit.twig');
        $action = (string) file_get_contents($root . '/controllers/ProductTypesController.php');

        preg_match_all("/getBodyParam\\('(\\w+)'/", $action, $read);

        // A setting the action reads but the form never posts is reset to its default on every save.
        foreach (array_diff(array_unique($read[1]), ['productTypeId']) as $param) {
            $this->assertStringContainsString("name: '$param'", $form, "The product type form has no input called $param");
        }
    }

    public function testDestructiveButtonsAskForConfirmation(): void
    {
        // Craft only honours data-confirm on its own formsubmit elements, so on a plain submit button it does nothing
        // and the action would run without asking. A form needs a real confirm() instead.
        foreach (self::templates() as $name => [$path]) {
            preg_match_all('/<[^>]*data-confirm=[^>]*>/', (string) file_get_contents($path), $tags);

            foreach ($tags[0] as $tag) {
                $this->assertStringContainsString('formsubmit', $tag, $name . ' uses data-confirm, which Craft ignores on a plain button');
            }
        }
    }
}
