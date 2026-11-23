<?php

declare(strict_types=1);

namespace OCA\StarRate\Notification;

use OCA\StarRate\Service\GuestActivityNotifier;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Rendert die gesammelten Gast-Bewertungs-Benachrichtigungen,
 * z. B. „Jane hat 50 Bilder in Shooting-2024 bewertet".
 */
class Notifier implements INotifier
{
    public function __construct(
        private readonly IFactory      $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
    ) {}

    public function getID(): string
    {
        return GuestActivityNotifier::APP_ID;
    }

    public function getName(): string
    {
        return $this->l10nFactory->get(GuestActivityNotifier::APP_ID)->t('StarRate');
    }

    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== GuestActivityNotifier::APP_ID
            || $notification->getSubject() !== GuestActivityNotifier::SUBJECT) {
            throw new UnknownNotificationException();
        }

        $l      = $this->l10nFactory->get(GuestActivityNotifier::APP_ID, $languageCode);
        $p      = $notification->getSubjectParameters();
        $guest  = trim((string) ($p['guest'] ?? ''));
        $count  = (int) ($p['count'] ?? 0);
        $folder = (string) ($p['folder'] ?? '');
        $path   = (string) ($p['path'] ?? '');

        if ($guest === '') {
            $guest = $l->t('Ein Gast');
        }

        $notification->setParsedSubject(
            $l->n('%1$s hat %n Bild in %2$s bewertet', '%1$s hat %n Bilder in %2$s bewertet', $count, [$guest, $folder])
        );
        $notification->setParsedMessage(
            $l->t('Zeit, die Metadaten zu synchronisieren.')
        );

        $link = $path === '' || $path === '/'
            ? $this->urlGenerator->linkToRouteAbsolute('starrate.gallery.index')
            : $this->urlGenerator->linkToRouteAbsolute('starrate.gallery.folder', ['path' => ltrim($path, '/')]);
        $notification->setLink($link);
        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(GuestActivityNotifier::APP_ID, 'app.svg'))
        );

        return $notification;
    }
}
