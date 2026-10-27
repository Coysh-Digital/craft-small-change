<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests;

use craft\mail\Mailer;
use craft\mail\Message;

/**
 * Keeps sent messages in memory instead of sending them.
 */
class RecordingMailer extends Mailer
{
    public $messageClass = Message::class;

    /**
     * @var Message[]
     */
    public array $sent = [];

    protected function sendMessage($message): bool
    {
        $this->sent[] = $message;

        return true;
    }

    protected function saveMessage($message): bool
    {
        return true;
    }
}
