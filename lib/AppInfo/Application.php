<?php

declare(strict_types=1);

namespace OCA\StarRate\AppInfo;

use OCA\StarRate\Listener\NodeDeletedListener;
use OCA\StarRate\Preview\RawPreviewProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Util;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'starrate';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);
        // RAW-Vorschauen (NEF & Co.) aus dem eingebetteten Kamera-JPEG
        $context->registerPreviewProvider(RawPreviewProvider::class, RawPreviewProvider::MIME_REGEX);
    }

    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (IEventDispatcher $dispatcher): void {
            $dispatcher->addListener(
                BeforeTemplateRenderedEvent::class,
                function (): void {
                    // files-context.js auf jeder NC-Seite laden:
                    // Speichert den NC-Files-Ordnerpfad in localStorage,
                    // damit StarRate ihn beim Start auslesen kann.
                    Util::addScript('starrate', 'starrate-files-context');
                }
            );
        });
    }
}
