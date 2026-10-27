<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\console\controllers;

use coyshdigital\smallchange\models\InstallResult;
use coyshdigital\smallchange\Plugin;
use coyshdigital\smallchange\services\Templates;
use craft\console\Controller;
use InvalidArgumentException;
use yii\console\ExitCode;

/**
 * Installs the example templates: `php craft small-change/templates/install`.
 */
final class TemplatesController extends Controller
{
    /**
     * @var string|null which sets to install: donations, recurring, shop (comma separated), or all
     */
    public ?string $sets = null;

    /**
     * Your layout template, like _layouts/site; pass an empty value (--layout=) for a plain one. Redeclares the
     * base controller's (unused on the command line) view layout, so it can't have a native type.
     *
     * @var string|null
     */
    public $layout = null;

    /**
     * @var string|null the block in that layout that the page content goes in (default: content)
     */
    public ?string $block = null;

    /**
     * @var string|null a folder inside your templates folder to put everything in, like small-change, when your site
     * already has pages at /cart or /checkout. The pages' links are rewritten to match.
     */
    public ?string $folder = null;

    /**
     * @var bool replace files that already exist (they are left alone otherwise)
     */
    public bool $overwrite = false;

    /**
     * @var bool don't ask for confirmation
     */
    public bool $yes = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'install' => [...$options, 'sets', 'layout', 'block', 'folder', 'overwrite', 'yes'],
            default => $options,
        };
    }

    /**
     * Copies the example pages into your templates folder, wrapped in your layout. Asks for anything you don't pass.
     */
    public function actionInstall(): int
    {
        $templates = Plugin::getInstance()->templates;

        $sets = $this->chosenSets($templates);

        if ($sets === null) {
            $this->stderr("Choose what to install with --sets=donations,recurring,shop (or --sets=all)\n");

            return ExitCode::USAGE;
        }

        $layout = $this->chosenLayout($templates);
        $block = $this->block ?? $this->chosenBlock($layout);

        if ($this->overwrite && !$this->yes && $this->interactive && !$this->confirm('Replace any of these files that already exist? Changes you have made to them will be lost.')) {
            return ExitCode::OK;
        }

        try {
            $result = $templates->install($sets, $layout, $block, $this->overwrite, null, $this->folder);
        } catch (InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n");

            return ExitCode::USAGE;
        }

        $this->report($result);

        return ExitCode::OK;
    }

    /**
     * @return string[]|null null when nothing was chosen and nobody can be asked
     */
    private function chosenSets(Templates $templates): ?array
    {
        $answer = $this->sets;

        if ($answer === null && $this->interactive) {
            $answer = $this->prompt('Which templates? donations, recurring, shop, or all (comma separated)', ['default' => 'all']);
        }

        if ($answer === null || trim($answer) === '') {
            return null;
        }

        if (trim($answer) === 'all') {
            return $templates->setHandles();
        }

        return array_values(array_filter(array_map('trim', explode(',', $answer))));
    }

    /**
     * An empty --layout means the plain layout; no --layout at all means ask (or the plain one if nobody can be asked).
     */
    private function chosenLayout(Templates $templates): ?string
    {
        if ($this->layout !== null) {
            return trim($this->layout) === '' ? null : trim($this->layout);
        }

        if (!$this->interactive) {
            return null;
        }

        $suggested = $templates->suggestLayout();

        if ($suggested !== null) {
            return $this->confirm("Put the pages in your layout, {$suggested}?", true) ? $suggested : null;
        }

        $answer = trim($this->prompt('Your layout template, like _layouts/site (leave empty to add a plain one)', ['default' => '']));

        return $answer === '' ? null : $answer;
    }

    private function chosenBlock(?string $layout): string
    {
        if ($layout === null || !$this->interactive) {
            return Templates::DEFAULT_BLOCK;
        }

        return trim($this->prompt('The block in that layout for the page content', ['default' => Templates::DEFAULT_BLOCK])) ?: Templates::DEFAULT_BLOCK;
    }

    private function report(InstallResult $result): void
    {
        foreach ($result->created as $file) {
            $this->stdout("  created  {$file}\n");
        }

        foreach ($result->overwritten as $file) {
            $this->stdout("  replaced {$file}\n");
        }

        foreach ($result->skipped as $file) {
            $this->stdout("  skipped  {$file} (already exists)\n");
        }

        foreach ($result->warnings as $warning) {
            $this->stderr("Warning: {$warning}\n");
        }

        $this->stdout("\n{$result->written()} file(s) written, " . count($result->skipped) . " left alone.\n");

        foreach ($result->nextSteps as $i => $step) {
            $this->stdout(($i === 0 ? "\nNext:\n" : '') . '  ' . ($i + 1) . ". {$step}\n");
        }
    }
}
