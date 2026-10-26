<?php

declare(strict_types=1);

namespace OCA\StarRate\AppInfo;

use OCA\StarRate\Listener\NodeDeletedListener;
use OCA\StarRate\Listener\RawSidecarListener;
use OCA\StarRate\Preview\RawPreviewProvider;
use OCA\StarRate\Service\RawPreviewExtractor;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\IMimeTypeDetector;
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
        // XMP-Sidecars folgen ihrer RAW (Löschen / Umbenennen / Verschieben)
        $context->registerEventListener(NodeDeletedEvent::class, RawSidecarListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, RawSidecarListener::class);
        // RAW-Vorschauen (NEF & Co.) aus dem eingebetteten Kamera-JPEG
        $context->registerPreviewProvider(RawPreviewProvider::class, RawPreviewProvider::MIME_REGEX);
        self::registerRawMimeTypes();
    }

    /**
     * Nextcloud kennt `.cr3` nicht als RAW (bleibt application/octet-stream).
     * Wie die App „Camera RAW Previews“ zur Laufzeit nachtragen; registerTypeArray()
     * ist kein OCP-API, daher defensiv. Bestandsdateien stellt der Repair-Step
     * RegisterRawMimeTypes um.
     */
    private static function registerRawMimeTypes(): void
    {
        try {
            $detector = \OCP\Server::get(IMimeTypeDetector::class);
            if (!method_exists($detector, 'registerTypeArray')) {
                return;
            }
            // Erst die Standard-Mappings laden lassen — sonst ersetzt unser Eintrag sie
            $mappings = $detector->getAllMappings();
            $missing  = array_diff_key(RawPreviewExtractor::EXTRA_MAPPINGS, $mappings);
            if ($missing !== []) {
                $detector->registerTypeArray($missing);
            }
        } catch (\Throwable) {
            // Ohne Mapping bleibt CR3 nur unsichtbar — nie den Bootstrap stören
        }
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
