<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\services;

use coyshdigital\smallchange\models\InstallResult;
use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use InvalidArgumentException;

/**
 * Copies the example templates into the site's templates folder, so a site doesn't have to do it by hand.
 * The examples live in the package's `example-templates/` folder; the sets below group them by what
 * a site wants to offer. It only ever creates files, and never replaces one unless told to.
 */
class Templates extends Component
{
    /**
     * The layout the example pages extend, and the block they put their content in. Installing rewrites
     * these two tokens when the site uses its own layout.
     */
    public const LAYOUT_NAME = '_small-change/layout';
    public const DEFAULT_BLOCK = 'content';

    private const COMMON_LAYOUTS = ['_layouts/site', '_layouts/base', '_layouts/main', '_layouts/default', '_layout', '_base', '_site', '_layouts/layout'];

    private const SHARED_GIFT_AID = '_small-change/gift-aid.twig';

    /**
     * The first path segments the example templates use, in template names and URLs. A folder goes in front of them.
     */
    private const FOLDER_SEGMENTS = ['_small-change', 'shop', 'donate', 'cart', 'checkout', 'account'];

    /**
     * Small partials that keep the pages working behind a static cache such as Blitz (see docs/caching.md).
     */
    private const SHARED_CSRF = '_small-change/csrf.twig';
    private const SHARED_DYNAMIC = [
        '_small-change/dynamic.twig',
        '_small-change/flash.twig',
        '_small-change/basket-link.twig',
    ];

    /**
     * @var array<string, array{files: string[], urls: string[]}>
     */
    private const SETS = [
        'donations' => [
            'files' => [
                'donate/index.twig',
                'donate/_form.twig',
                'donate/payment.twig',
                'donate/complete.twig',
                'donate/_progress.twig',
                '_small-change/checkout-fields.twig',
                self::SHARED_GIFT_AID,
                self::SHARED_CSRF,
            ],
            'urls' => ['/donate', '/donate/payment', '/donate/complete'],
        ],
        'recurring' => [
            'files' => [
                'donate/manage.twig',
                'account/donations.twig',
                self::SHARED_CSRF,
            ],
            'urls' => ['/donate/manage', '/account/donations'],
        ],
        'shop' => [
            'files' => [
                'shop/index.twig',
                'shop/product.twig',
                'shop/_add-form.twig',
                'cart.twig',
                'checkout/index.twig',
                'checkout/payment.twig',
                'checkout/complete.twig',
                '_small-change/address-fields.twig',
                '_small-change/checkout-fields.twig',
                self::SHARED_GIFT_AID,
                self::SHARED_CSRF,
                ...self::SHARED_DYNAMIC,
            ],
            'urls' => ['/shop', '/cart', '/checkout', '/checkout/payment', '/checkout/complete'],
        ],
    ];

    /**
     * The sets on offer, in the order to show them.
     *
     * @return array<string, array{label: string, description: string, urls: string[], files: string[]}>
     */
    public function sets(): array
    {
        $text = [
            'donations' => [
                Craft::t('small-change', 'Donations'),
                Craft::t('small-change', 'A donation form (one-off or recurring), the payment page and the thank-you page.'),
            ],
            'recurring' => [
                Craft::t('small-change', 'Recurring donor pages'),
                Craft::t('small-change', 'A page where a donor asks for a link to manage their recurring donation, and a signed-in account page of their gifts.'),
            ],
            'shop' => [
                Craft::t('small-change', 'Shop'),
                Craft::t('small-change', 'A product list and product page, the basket, checkout, the payment page and the thank-you page.'),
            ],
        ];

        $sets = [];

        foreach (self::SETS as $handle => $set) {
            $sets[$handle] = [
                'label' => $text[$handle][0],
                'description' => $text[$handle][1],
                'urls' => $set['urls'],
                'files' => $set['files'],
            ];
        }

        return $sets;
    }

    /**
     * @return string[]
     */
    public function setHandles(): array
    {
        return array_keys(self::SETS);
    }

    /**
     * Every file the chosen sets install, as a path inside the templates folder. Files shared between
     * sets (the Gift Aid partial) appear once.
     *
     * @param string[] $sets
     * @return string[]
     */
    public function files(array $sets, bool $withLayout = false): array
    {
        $files = [];

        foreach ($this->validSets($sets) as $handle) {
            foreach (self::SETS[$handle]['files'] as $file) {
                $files[$file] = $file;
            }
        }

        if ($withLayout) {
            $files[self::LAYOUT_NAME . '.twig'] = self::LAYOUT_NAME . '.twig';
        }

        return array_values($files);
    }

    /**
     * Whether a set is `installed`, `partial` or `none`, going by which of its files exist.
     */
    public function status(string $set, ?string $basePath = null, ?string $folder = null): string
    {
        $base = $this->basePath($basePath);
        $prefix = $this->folderPrefix($folder);
        $present = 0;
        $files = $this->files([$set]);

        foreach ($files as $file) {
            if (is_file($base . '/' . $prefix . $file)) {
                $present++;
            }
        }

        return match (true) {
            $present === 0 => 'none',
            $present === count($files) => 'installed',
            default => 'partial',
        };
    }

    /**
     * A layout the site probably already has, to offer as the default. Null when none of the usual names exist.
     */
    public function suggestLayout(?string $basePath = null): ?string
    {
        $base = $this->basePath($basePath);

        foreach (self::COMMON_LAYOUTS as $name) {
            foreach (['.twig', '.html'] as $suffix) {
                if (is_file($base . '/' . $name . $suffix)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Copies the chosen sets into the templates folder.
     *
     * @param string[] $sets handles from sets()
     * @param string|null $layout the site's own layout template (like `_layouts/site`), or null to install a plain one
     * @param string $block the block in that layout the page content goes in
     * @param bool $overwrite replace files that already exist (they are skipped otherwise)
     * @param string|null $basePath install somewhere other than the site's templates folder (for tests)
     * @param string|null $folder a folder inside the templates folder to put everything in, for a site whose own pages
     *                            already use paths like /checkout. The pages' links and includes are rewritten to match.
     * @throws InvalidArgumentException when the request can't be carried out; the message is fit to show
     */
    public function install(array $sets, ?string $layout = null, string $block = self::DEFAULT_BLOCK, bool $overwrite = false, ?string $basePath = null, ?string $folder = null): InstallResult
    {
        $sets = $this->validSets($sets);

        if ($sets === []) {
            throw new InvalidArgumentException(Craft::t('small-change', 'Choose at least one set of templates to install.'));
        }

        $layout = $layout !== null && trim($layout) !== '' ? trim($layout) : null;
        $folder = $this->cleanFolder($folder);
        $prefix = $this->folderPrefix($folder);

        if (!preg_match('/^[A-Za-z_]\w*$/', $block)) {
            throw new InvalidArgumentException(Craft::t('small-change', 'The block name can only contain letters, numbers and underscores.'));
        }

        $base = $this->basePath($basePath);
        $result = new InstallResult();

        if ($layout !== null) {
            $this->checkLayout($layout, $block, $base, $result);
        }

        $source = dirname(__DIR__, 2) . '/example-templates';

        foreach ($this->files($sets, $layout === null) as $file) {
            $target = $base . '/' . $prefix . $file;
            $exists = is_file($target);

            if ($exists && !$overwrite) {
                $result->skipped[] = $prefix . $file;

                continue;
            }

            $contents = (string) file_get_contents($source . '/' . $file);

            // The layout first: the folder rewrite then carries the plain layout's name along with the rest.
            if ($layout !== null) {
                $contents = $this->useLayout($contents, $layout, $block);
            }

            if ($folder !== null) {
                $contents = $this->useFolder($contents, $folder);
            }

            FileHelper::writeToFile($target, $contents);

            if ($exists) {
                $result->overwritten[] = $prefix . $file;
            } else {
                $result->created[] = $prefix . $file;
            }
        }

        $result->nextSteps = $this->nextSteps($sets, $layout === null, $folder);

        return $result;
    }

    /**
     * Points a page at the site's layout and block instead of the plain one.
     */
    private function useLayout(string $contents, string $layout, string $block): string
    {
        $contents = str_replace("{% extends '" . self::LAYOUT_NAME . "' %}", "{% extends '" . $layout . "' %}", $contents);

        return str_replace('{% block ' . self::DEFAULT_BLOCK . ' %}', '{% block ' . $block . ' %}', $contents);
    }

    /**
     * Puts the folder in front of every template name and URL the pages use for themselves: quoted strings that
     * start with one of the example paths, such as 'donate/payment', 'cart' or "/shop". Strings that merely
     * contain those words, like small-change/cart/add or a type of 'donation', are not touched.
     */
    private function useFolder(string $contents, string $folder): string
    {
        $segments = implode('|', array_map(static fn(string $segment): string => preg_quote($segment, '~'), self::FOLDER_SEGMENTS));

        return (string) preg_replace('~([\'"])(/?)(' . $segments . ')(?=[/?\'"])~', '${1}${2}' . $folder . '/${3}', $contents);
    }

    /**
     * @throws InvalidArgumentException when the folder could reach outside the templates folder or break a template name
     */
    private function cleanFolder(?string $folder): ?string
    {
        $folder = trim((string) $folder, " \t/");

        if ($folder === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_\-.\/]+$/', $folder) || str_contains($folder, '..') || str_contains($folder, '//')) {
            throw new InvalidArgumentException(Craft::t('small-change', 'The folder should be a path like small-change, using letters, numbers, dashes and underscores.'));
        }

        return $folder;
    }

    private function folderPrefix(?string $folder): string
    {
        $folder = $this->cleanFolder($folder);

        return $folder === null ? '' : $folder . '/';
    }

    /**
     * A layout that doesn't exist is an error (the pages would all fail to render). One that exists but
     * looks wrong only gets a warning, because the checks are a guess at what Twig will do.
     */
    private function checkLayout(string $layout, string $block, string $base, InstallResult $result): void
    {
        if (!preg_match('/^[A-Za-z0-9_\-.\/]+$/', $layout) || str_contains($layout, '..') || str_starts_with($layout, '/')) {
            throw new InvalidArgumentException(Craft::t('small-change', 'The layout should be a template path like _layouts/site.'));
        }

        $file = null;

        foreach (['.twig', '.html', '/index.twig', '/index.html'] as $suffix) {
            if (is_file($base . '/' . $layout . $suffix)) {
                $file = $base . '/' . $layout . $suffix;

                break;
            }
        }

        if ($file === null) {
            throw new InvalidArgumentException(Craft::t('small-change', 'There is no layout template called "{layout}" in your templates folder.', ['layout' => $layout]));
        }

        $source = (string) file_get_contents($file);

        if (!preg_match('/\{%-?\s*block\s+' . preg_quote($block, '/') . '\b/', $source)) {
            $result->warnings[] = Craft::t('small-change', 'Your layout does not seem to have a "{block}" block, so the pages will show nothing until it does.', ['block' => $block]);
        }

        if (!str_contains($source, 'endBody()')) {
            $result->warnings[] = Craft::t('small-change', 'Your layout does not output endBody(), so the scripts for the payment form will not load. Add {{ endBody() }} just before </body>.');
        }
    }

    /**
     * @param string[] $sets
     * @return string[]
     */
    private function nextSteps(array $sets, bool $installedLayout, ?string $folder): array
    {
        $steps = [];
        $url = static fn(string $path): string => '/' . ($folder !== null ? $folder . '/' : '') . $path;
        $template = static fn(string $path): string => ($folder !== null ? $folder . '/' : '') . $path;

        if (in_array('donations', $sets, true)) {
            $steps[] = Craft::t('small-change', 'Create a donation under Small Change → Products, then visit {url}.', ['url' => $url('donate')]);
            $steps[] = Craft::t('small-change', 'To put the donation form on another page, include {template} there with the product.', ['template' => $template('donate/_form')]);
        }

        if (in_array('recurring', $sets, true)) {
            $steps[] = Craft::t('small-change', 'Turn on recurring gifts for your donation product, so donors can use {url}.', ['url' => $url('donate/manage')]);
        }

        if (in_array('shop', $sets, true)) {
            $steps[] = Craft::t('small-change', 'Under Settings → Product types, give your shop product type the URI format {uri} and the template {template}, then visit {url}. Or leave the URI format blank and show products on your own pages.', [
                'uri' => $template('shop/{slug}'),
                'template' => $template('shop/product'),
                'url' => $url('shop'),
            ]);
        }

        if ($installedLayout) {
            $steps[] = Craft::t('small-change', 'Edit templates/{path} to match your site, or point the pages at your own layout by installing again with a layout.', ['path' => $template('_small-change/layout.twig')]);
        }

        return $steps;
    }

    /**
     * @param string[] $sets
     * @return string[]
     */
    private function validSets(array $sets): array
    {
        $unknown = array_diff($sets, array_keys(self::SETS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(Craft::t('small-change', 'Unknown template set: {sets}.', ['sets' => implode(', ', $unknown)]));
        }

        // Keep the order sets() lists them in, whatever order they were asked for.
        return array_values(array_intersect(array_keys(self::SETS), $sets));
    }

    private function basePath(?string $basePath): string
    {
        return rtrim($basePath ?? Craft::$app->getPath()->getSiteTemplatesPath(), '/');
    }
}
