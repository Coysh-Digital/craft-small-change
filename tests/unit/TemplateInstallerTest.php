<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\elements\Order;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Templates;
use coyshdigital\smallchange\tests\Fixtures;
use Craft;
use craft\elements\Address;
use craft\helpers\FileHelper;
use craft\web\View;
use InvalidArgumentException;
use Twig\Source;

final class TemplateInstallerTest extends Unit
{
    use Fixtures;

    private string $base;

    protected function _before(): void
    {
        $this->base = sys_get_temp_dir() . '/small-change-templates-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($this->base);
    }

    protected function _after(): void
    {
        FileHelper::removeDirectory($this->base);
    }

    private function templates(): Templates
    {
        return Plugin::getInstance()->templates;
    }

    private function writeLayout(string $contents = "<html>{{ head() }}<body>{% block main %}{% endblock %}{{ endBody() }}</body></html>"): void
    {
        FileHelper::writeToFile($this->base . '/_layouts/site.twig', $contents);
    }

    public function testEveryExampleTemplateBelongsToASet(): void
    {
        $root = dirname(__DIR__, 2) . '/example-templates';
        $listed = $this->templates()->files($this->templates()->setHandles(), true);
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $found[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }

        sort($found);
        sort($listed);

        // Nothing orphaned (not installable) and nothing listed that isn't shipped.
        $this->assertSame($found, $listed);
    }

    public function testEachSetInstallsItsFiles(): void
    {
        foreach ($this->templates()->setHandles() as $handle) {
            $base = $this->base . '/' . $handle;
            $result = $this->templates()->install([$handle], null, 'content', false, $base);

            $expected = $this->templates()->files([$handle], true);
            sort($expected);
            $created = $result->created;
            sort($created);

            $this->assertSame($expected, $created, $handle);
            $this->assertSame([], $result->skipped);

            foreach ($expected as $file) {
                $this->assertFileExists($base . '/' . $file);
            }
        }
    }

    public function testAFolderHoldsEveryFileAndEveryLinkFollowsIt(): void
    {
        $result = $this->templates()->install($this->templates()->setHandles(), null, 'content', false, $this->base, 'pages/giving');

        $expected = array_map(static fn(string $file): string => 'pages/giving/' . $file, $this->templates()->files($this->templates()->setHandles(), true));
        sort($expected);
        $created = $result->created;
        sort($created);

        $this->assertSame($expected, $created);
        $this->assertFileDoesNotExist($this->base . '/donate/index.twig');
        $this->assertFileDoesNotExist($this->base . '/cart.twig');
        $this->assertFileDoesNotExist($this->base . '/checkout/index.twig');

        $segments = '_small-change|shop|donate|cart|checkout|account';

        foreach ($expected as $file) {
            $contents = (string) file_get_contents($this->base . '/' . $file);

            // Nothing still points at the site's own /cart or /checkout.
            $this->assertDoesNotMatchRegularExpression('~([\'"])/?(' . $segments . ')(?=[/?\'"])~', $contents, $file);
        }

        $index = (string) file_get_contents($this->base . '/pages/giving/donate/index.twig');
        $this->assertStringContainsString("{% extends 'pages/giving/_small-change/layout' %}", $index);
        $this->assertStringContainsString("{% include 'pages/giving/donate/_form'", $index);
        $this->assertStringContainsString("kind: 'donation'", $index, 'a value that only looks like a path is left alone');

        $form = (string) file_get_contents($this->base . '/pages/giving/donate/_form.twig');
        $this->assertStringContainsString("redirectInput('pages/giving/donate/payment?number={number}')", $form);
        $this->assertStringContainsString("'pages/giving/donate/complete'|hash", $form);
        $this->assertStringContainsString("actionInput('small-change/donations/start')", $form, 'the plugin actions are not site paths');

        $cart = (string) file_get_contents($this->base . '/pages/giving/cart.twig');
        $this->assertStringContainsString("redirectInput('pages/giving/cart')", $cart);
        $this->assertStringContainsString('<a href="/pages/giving/checkout">', $cart);
        $this->assertStringContainsString("actionInput('small-change/cart/update')", $cart);
    }

    public function testAFolderInstallStillParsesAndUsesTheSitesLayout(): void
    {
        $this->writeLayout();
        $result = $this->templates()->install($this->templates()->setHandles(), '_layouts/site', 'main', false, $this->base, 'small-change/');

        $this->assertSame([], $result->warnings);
        $this->assertFileExists($this->base . '/small-change/shop/index.twig');
        $this->assertFileDoesNotExist($this->base . '/small-change/_small-change/layout.twig');
        $this->assertStringContainsString("{% extends '_layouts/site' %}", (string) file_get_contents($this->base . '/small-change/cart.twig'));

        $twig = Craft::$app->getView()->getTwig();

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base . '/small-change', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $twig->parse($twig->tokenize(new Source((string) file_get_contents($file->getPathname()), $file->getFilename())));
        }

        $this->assertSame('installed', $this->templates()->status('shop', $this->base, 'small-change'));
        $this->assertSame('none', $this->templates()->status('shop', $this->base));
        $this->assertStringContainsString('/small-change/shop', implode(' ', $result->nextSteps));
    }

    public function testABadFolderIsRejectedAndWritesNothing(): void
    {
        foreach (['../secret', 'a b', 'a//b', "x' %}{% set y", 'a/../b'] as $folder) {
            try {
                $this->templates()->install(['donations'], null, 'content', false, $this->base, $folder);
                $this->fail($folder . ' was accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame([], FileHelper::findFiles($this->base));
    }

    public function testTheDonationFormPartialRendersForAnyProduct(): void
    {
        $this->useFakes();
        $view = Craft::$app->getView();
        $site = Craft::$app->getPath()->getSiteTemplatesPath();
        $folders = ['_small-change', 'donate'];
        $this->assertSame([], array_filter($folders, static fn(string $f): bool => is_dir($site . '/' . $f)));

        try {
            $this->templates()->install(['donations'], null, 'content');
            $product = $this->makeProduct([500, 1000], 200, true, 'Roof fund');

            $html = $view->renderTemplate('donate/_form', ['product' => $product], View::TEMPLATE_MODE_SITE);

            $this->assertStringContainsString('id="donate-form-' . $product->id . '"', $html);
            $this->assertStringContainsString('name="productId" value="' . $product->id . '"', $html);
            $this->assertStringContainsString('name="suggestedAmount"', $html);
            $this->assertStringContainsString('name="email"', $html);
        } finally {
            foreach ($folders as $folder) {
                FileHelper::removeDirectory($site . '/' . $folder);
            }
        }
    }

    public function testTheAddFormCanGoStraightToCheckout(): void
    {
        $this->useFakes();
        $view = Craft::$app->getView();
        $site = Craft::$app->getPath()->getSiteTemplatesPath();
        $folders = ['_small-change', 'shop'];
        $this->assertSame([], array_filter($folders, static fn(string $f): bool => is_dir($site . '/' . $f)));

        try {
            foreach (['_small-change/csrf.twig', 'shop/_add-form.twig'] as $file) {
                FileHelper::writeToFile($site . '/' . $file, (string) file_get_contents(dirname(__DIR__, 2) . '/example-templates/' . $file));
            }

            $product = $this->makeShopProduct(900);
            $basket = $view->renderTemplate('shop/_add-form', ['product' => $product], View::TEMPLATE_MODE_SITE);
            $now = $view->renderTemplate('shop/_add-form', ['product' => $product, 'buyNow' => true, 'label' => 'Buy it'], View::TEMPLATE_MODE_SITE);

            $this->assertStringContainsString('Add to basket</button>', $basket);
            $this->assertStringNotContainsString('Buy it', $basket);
            $this->assertStringContainsString('Buy it</button>', $now);
            $this->assertNotSame($this->redirectOf($basket), $this->redirectOf($now), 'buyNow sends the buyer somewhere else');
        } finally {
            foreach ($folders as $folder) {
                FileHelper::removeDirectory($site . '/' . $folder);
            }
        }
    }

    private function redirectOf(string $html): string
    {
        preg_match('/name="redirect" value="([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
    }

    public function testEachSetBringsEveryTemplateItIncludes(): void
    {
        foreach ($this->templates()->setHandles() as $handle) {
            $base = $this->base . '/' . $handle;
            $this->templates()->install([$handle], null, 'content', false, $base);

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $contents = (string) file_get_contents($file->getPathname());

                // Includes that name their template and are not marked as optional. The comments in the files only
                // describe includes, so they are dropped first.
                $contents = (string) preg_replace('/\{#.*?#\}/s', '', $contents);
                preg_match_all("/\{%-?\s*(?:include|extends)\s+'([^']+)'(?![^%]*ignore missing)/", $contents, $matches);

                foreach ($matches[1] as $name) {
                    $this->assertFileExists($base . '/' . $name . '.twig', $handle . ' set: ' . $file->getFilename() . ' includes ' . $name);
                }
            }
        }
    }

    public function testSharedPartialsAreWrittenOnce(): void
    {
        $result = $this->templates()->install(['donations', 'shop'], null, 'content', false, $this->base);

        $this->assertSame(array_values(array_unique($result->created)), $result->created);
        $this->assertCount(1, array_keys($result->created, '_small-change/gift-aid.twig', true));
    }

    public function testThePlainLayoutIsInstalledAndLeftAsTheExtendsTarget(): void
    {
        $this->templates()->install(['donations'], null, 'content', false, $this->base);

        $this->assertFileExists($this->base . '/_small-change/layout.twig');
        $this->assertStringContainsString("{% extends '_small-change/layout' %}", (string) file_get_contents($this->base . '/donate/index.twig'));
    }

    public function testPagesAreRewrittenToTheSitesLayoutAndBlock(): void
    {
        $this->writeLayout();

        $result = $this->templates()->install(['donations', 'shop'], '_layouts/site', 'main', false, $this->base);

        $this->assertSame([], $result->warnings);
        $this->assertFileDoesNotExist($this->base . '/_small-change/layout.twig');

        foreach (['donate/index.twig', 'cart.twig', 'checkout/index.twig'] as $file) {
            $contents = (string) file_get_contents($this->base . '/' . $file);
            $this->assertStringContainsString("{% extends '_layouts/site' %}", $contents, $file);
            $this->assertStringContainsString('{% block main %}', $contents, $file);
            $this->assertStringNotContainsString('_small-change/layout', $contents, $file);
            $this->assertStringNotContainsString('{% block content %}', $contents, $file);
        }
    }

    public function testEveryInstalledTemplateParses(): void
    {
        $this->writeLayout();
        $this->templates()->install($this->templates()->setHandles(), '_layouts/site', 'main', false, $this->base);

        $twig = Craft::$app->getView()->getTwig();
        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $twig->parse($twig->tokenize(new Source((string) file_get_contents($file->getPathname()), $file->getFilename())));
            $count++;
        }

        $this->assertGreaterThan(0, $count);
    }

    public function testExistingFilesAreSkippedUnlessOverwriting(): void
    {
        FileHelper::writeToFile($this->base . '/donate/index.twig', 'mine');

        $result = $this->templates()->install(['donations'], null, 'content', false, $this->base);

        $this->assertContains('donate/index.twig', $result->skipped);
        $this->assertNotContains('donate/index.twig', $result->created);
        $this->assertSame('mine', file_get_contents($this->base . '/donate/index.twig'));

        $result = $this->templates()->install(['donations'], null, 'content', true, $this->base);

        $this->assertContains('donate/index.twig', $result->overwritten);
        $this->assertNotSame('mine', file_get_contents($this->base . '/donate/index.twig'));
    }

    public function testRunningTwiceChangesNothingTheSecondTime(): void
    {
        $this->templates()->install(['donations'], null, 'content', false, $this->base);
        $second = $this->templates()->install(['donations'], null, 'content', false, $this->base);

        $this->assertSame([], $second->created);
        $this->assertSame([], $second->overwritten);
        $this->assertSame(0, $second->written());
        $this->assertNotSame([], $second->skipped);
    }

    public function testStatusReflectsWhatIsOnDisk(): void
    {
        $this->assertSame('none', $this->templates()->status('donations', $this->base));

        FileHelper::writeToFile($this->base . '/donate/index.twig', 'x');
        $this->assertSame('partial', $this->templates()->status('donations', $this->base));

        $this->templates()->install(['donations'], null, 'content', false, $this->base);
        $this->assertSame('installed', $this->templates()->status('donations', $this->base));
    }

    public function testALayoutThatLooksWrongGetsWarnings(): void
    {
        $this->writeLayout('<html><body>{% block content %}{% endblock %}</body></html>');

        $result = $this->templates()->install(['donations'], '_layouts/site', 'main', false, $this->base);

        // No "main" block, and no endBody().
        $this->assertCount(2, $result->warnings);
        $this->assertGreaterThan(0, $result->written());
    }

    public function testBadRequestsAreRejected(): void
    {
        $this->writeLayout();

        $bad = [
            'no sets' => [[], null, 'content'],
            'unknown set' => [['donations', 'nope'], null, 'content'],
            'bad block' => [['donations'], '_layouts/site', 'main block'],
            'missing layout' => [['donations'], '_layouts/missing', 'main'],
            'layout outside the templates folder' => [['donations'], '../secret', 'main'],
            'layout with a quote' => [['donations'], "_layouts/site' %}{% set x", 'main'],
        ];

        foreach ($bad as $name => [$sets, $layout, $block]) {
            try {
                $this->templates()->install($sets, $layout, $block, false, $this->base);
                $this->fail($name . ' was accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        // A rejected request writes nothing.
        $this->assertFileDoesNotExist($this->base . '/donate/index.twig');
    }

    public function testSuggestsACommonLayout(): void
    {
        $this->assertNull($this->templates()->suggestLayout($this->base));

        $this->writeLayout();

        $this->assertSame('_layouts/site', $this->templates()->suggestLayout($this->base));
    }

    public function testPagesNoLongerNeedRoutesOrACopiedScript(): void
    {
        $root = dirname(__DIR__, 2) . '/example-templates';

        foreach ($this->templates()->files($this->templates()->setHandles()) as $file) {
            $contents = (string) file_get_contents($root . '/' . $file);

            $this->assertStringNotContainsString('/small-change/js/', $contents, $file);
            $this->assertStringNotContainsString('_includes/', $contents, $file);
            $this->assertStringNotContainsString('payment/{number}', $contents, $file);
        }
    }

    /**
     * Copies the cache-friendly partials where Twig can find them, runs the callback, and removes them again.
     *
     * @param callable(): void $test
     */
    private function withCachePartials(callable $test): void
    {
        $site = Craft::$app->getPath()->getSiteTemplatesPath();
        $this->assertDirectoryDoesNotExist($site . '/_small-change');

        try {
            foreach (['csrf', 'dynamic', 'flash', 'basket-link'] as $name) {
                FileHelper::writeToFile($site . "/_small-change/$name.twig", (string) file_get_contents(dirname(__DIR__, 2) . "/example-templates/_small-change/$name.twig"));
            }

            $test();
        } finally {
            FileHelper::removeDirectory($site . '/_small-change');
        }
    }

    public function testTheCachePartialsWorkWithoutBlitz(): void
    {
        $this->withCachePartials(function(): void {
            $view = Craft::$app->getView();
            Craft::$app->getSession()->setFlash('error', 'That did not work.');

            $flash = $view->renderTemplate('_small-change/dynamic', ['template' => '_small-change/flash'], View::TEMPLATE_MODE_SITE);
            $this->assertStringContainsString('<p role="alert">That did not work.</p>', $flash, 'without Blitz it is a plain include');

            $link = $view->renderTemplate('_small-change/dynamic', ['template' => '_small-change/basket-link'], View::TEMPLATE_MODE_SITE);
            $this->assertStringContainsString('<a href="/cart">Basket</a>', $link);

            $csrf = $view->renderTemplate('_small-change/csrf', [], View::TEMPLATE_MODE_SITE);
            $this->assertStringNotContainsStringIgnoringCase('blitz', $csrf);
        });
    }

    public function testTheCachePartialsUseBlitzsDocumentedFunctions(): void
    {
        $root = dirname(__DIR__, 2) . '/example-templates/_small-change';

        // Names taken from Blitz's own documentation; a typo would only show up on a site that has Blitz.
        $this->assertStringContainsString("craft.app.plugins.isPluginEnabled('blitz')", (string) file_get_contents($root . '/csrf.twig'));
        $this->assertStringContainsString('craft.blitz.csrfInput()', (string) file_get_contents($root . '/csrf.twig'));
        $this->assertStringContainsString('craft.blitz.includeDynamic(template, params)', (string) file_get_contents($root . '/dynamic.twig'));
        $this->assertStringContainsString("craft.app.plugins.isPluginEnabled('blitz')", (string) file_get_contents($root . '/dynamic.twig'));
    }

    public function testInstalledPagesRenderInsideTheSitesLayoutWithStripeLoadedFirst(): void
    {
        $view = Craft::$app->getView();
        $site = Craft::$app->getPath()->getSiteTemplatesPath();
        $folders = ['_layouts', '_small-change', 'donate'];

        // Only the folders this test makes are removed afterwards; the real templates folder starts empty.
        $this->assertSame([], array_filter($folders, static fn(string $f): bool => is_dir($site . '/' . $f)));

        try {
            FileHelper::writeToFile($site . '/_layouts/site.twig', '<html><head>{{ head() }}</head><body>{{ beginBody() }}<div id="site-layout">{% block main %}{% endblock %}</div>{{ endBody() }}</body></html>');
            $this->templates()->install(['donations'], '_layouts/site', 'main');

            // Where a donor lands after backing out of Stripe's page; the reason has to be on the page.
            Craft::$app->getSession()->setFlash('error', 'Your payment did not complete.');
            $html = $view->renderPageTemplate('donate/payment', [], View::TEMPLATE_MODE_SITE);

            $this->assertStringContainsString('<p role="alert">Your payment did not complete.</p>', $html);
            $this->assertStringContainsString('<div id="site-layout">', $html);
            $this->assertStringContainsString("We couldn't find that donation", $html);

            $stripe = strpos($html, 'js.stripe.com/endive/stripe.js');
            $ours = strpos($html, 'small-change.js');

            $this->assertNotFalse($stripe, 'Stripe.js is on the payment page');
            $this->assertNotFalse($ours, 'the Small Change script is on the payment page');
            $this->assertLessThan($ours, $stripe, 'Stripe.js loads before our script');
        } finally {
            Craft::$app->getSession()->removeFlash('error');

            foreach ($folders as $folder) {
                FileHelper::removeDirectory($site . '/' . $folder);
            }
        }
    }

    public function testGiftAidKeepsTheAddressTheOrderAlreadyHas(): void
    {
        $view = Craft::$app->getView();
        $site = Craft::$app->getPath()->getSiteTemplatesPath();
        $settings = Plugin::getInstance()->getSettings();
        $before = $settings->giftAidEnabled;

        $this->assertDirectoryDoesNotExist($site . '/_small-change');

        try {
            $settings->giftAidEnabled = true;
            FileHelper::writeToFile($site . '/_small-change/gift-aid.twig', (string) file_get_contents(dirname(__DIR__, 2) . '/example-templates/_small-change/gift-aid.twig'));

            $order = new Order();
            $order->giftAidDeclared = true;
            $order->setBillingAddress(new Address([
                'firstName' => 'Ada',
                'lastName' => 'O\'Neil',
                'addressLine1' => '1 High Street',
                'locality' => 'Leeds',
                'postalCode' => 'LS1 1AA',
                'countryCode' => 'GB',
            ]));

            // Going back to the details page must not post an empty address over the saved one.
            $html = $view->renderTemplate('_small-change/gift-aid', ['order' => $order], View::TEMPLATE_MODE_SITE);
            $this->assertStringContainsString('name="billingAddress[firstName]" value="Ada"', $html);
            $this->assertStringContainsString('name="billingAddress[lastName]" value="O&#039;Neil"', $html);
            $this->assertStringContainsString('name="billingAddress[addressLine1]" value="1 High Street"', $html);
            $this->assertStringContainsString('name="billingAddress[locality]" value="Leeds"', $html);
            $this->assertStringContainsString('name="billingAddress[postalCode]" value="LS1 1AA"', $html);

            // And a first visit, with no order or no address yet, is blank rather than an error.
            $blank = $view->renderTemplate('_small-change/gift-aid', [], View::TEMPLATE_MODE_SITE);
            $this->assertStringContainsString('name="billingAddress[firstName]" value=""', $blank);
        } finally {
            $settings->giftAidEnabled = $before;
            FileHelper::removeDirectory($site . '/_small-change');
        }
    }
}
