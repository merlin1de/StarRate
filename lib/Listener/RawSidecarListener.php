<?php

declare(strict_types=1);

namespace OCA\StarRate\Listener;

use OCA\StarRate\Service\SidecarService;
use OCA\StarRate\Settings\UserSettings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Hält XMP-Sidecars an ihrer RAW: Löschen der RAW löscht das Sidecar mit,
 * Umbenennen/Verschieben nimmt es mit.
 *
 * Nur für User, bei denen StarRate Sidecars schreibt (write_xmp + write_raw_sidecar),
 * und nur im Dateibaum des Users (nicht Papierkorb, nicht Versionen). Fehler hier
 * dürfen die eigentliche Dateioperation nie stören.
 *
 * @template-implements IEventListener<NodeDeletedEvent|NodeRenamedEvent>
 */
class RawSidecarListener implements IEventListener
{
    public function __construct(
        private readonly SidecarService $sidecarService,
        private readonly UserSettings $userSettings,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void
    {
        try {
            if ($event instanceof NodeDeletedEvent) {
                $raw = $event->getNode();
                if ($this->manages($raw)) {
                    $this->sidecarService->deleteSidecarOf($raw);
                }
            } elseif ($event instanceof NodeRenamedEvent) {
                // Die Quelle existiert nach dem Rename nicht mehr: ohne Filecache-Eintrag
                // wirft getMimeType() — nur Name/Pfad der Quelle sind verlässlich.
                $source = $event->getSource();
                $target = $event->getTarget();
                if ($source instanceof File && $this->manages($target)) {
                    $this->sidecarService->moveSidecarWith($source, $target);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('StarRate: sidecar follow-up skipped: ' . $e->getMessage());
        }
    }

    /**
     * @phpstan-assert-if-true File $node
     */
    private function manages(Node $node): bool
    {
        if (!$node instanceof File || !SidecarService::hasSidecar($node)) {
            return false;
        }
        // Pfad: /<uid>/files/… — Papierkorb (/files_trashbin/) und Versionen fallen raus
        if (!preg_match('#^/([^/]+)/files/#', $node->getPath(), $m)) {
            return false;
        }
        return SidecarService::appliesTo($node, $this->userSettings->getSettings($m[1]));
    }
}
