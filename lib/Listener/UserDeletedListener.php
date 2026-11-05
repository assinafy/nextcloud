<?php

declare(strict_types=1);

namespace OCA\Assinafy\Listener;

use OCA\Assinafy\Service\OAuthService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/** @implements IEventListener<BeforeUserDeletedEvent> */
final class UserDeletedListener implements IEventListener
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function handle(Event $event): void
    {
        if ($event instanceof BeforeUserDeletedEvent) {
            $this->oauth->disconnectUser($event->getUser()->getUID());
        }
    }
}
