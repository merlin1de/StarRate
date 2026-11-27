<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCA\StarRate\AppInfo\Application;
use OCA\StarRate\Settings\UserSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Fasst Gast-Bewertungen zu einer Benachrichtigung pro Gast und Share zusammen
 * („Jane hat 50 Bilder bewertet") statt einer Meldung pro Klick.
 *
 * Läuft im Hintergrund-Job. Pro Lauf werden neue Log-Einträge seit dem letzten
 * Lauf (Watermark) eingesammelt und die bewerteten file_ids pro Gast gesammelt.
 * Erst wenn ein Gast QUIET_SECONDS lang nichts mehr bewertet hat, geht die
 * Benachrichtigung raus. Das Sammeln pro Lauf (statt erst am Ende aus dem Log zu
 * zählen) hält die Zählung korrekt, auch wenn das Log auf 500 Einträge gekürzt wird.
 */
class GuestActivityNotifier
{
    public const SUBJECT       = 'guest_rated';
    public const QUIET_SECONDS = 600;

    private const CONFIG_STATE = 'starrate_notify_state';
    private const CONFIG_SINCE = 'notify_since';

    public function __construct(
        private readonly IConfig              $config,
        private readonly ShareService         $shareService,
        private readonly UserSettings         $userSettings,
        private readonly INotificationManager $notificationManager,
        private readonly ITimeFactory         $timeFactory,
        private readonly LoggerInterface      $logger,
    ) {}

    /**
     * Verarbeitet alle Share-Owner. Gibt die Anzahl verschickter Benachrichtigungen zurück.
     */
    public function run(): int
    {
        $sent = 0;
        foreach ($this->shareService->getAllShareOwners() as $ownerId) {
            try {
                $sent += $this->processOwner($ownerId);
            } catch (\Throwable $e) {
                $this->logger->error("StarRate GuestActivityNotifier: owner {$ownerId} – {$e->getMessage()}");
            }
        }
        return $sent;
    }

    public function processOwner(string $ownerId): int
    {
        $now     = $this->timeFactory->getTime();
        $since   = $this->enabledSince($now);
        $enabled = (bool) ($this->userSettings->getSettings($ownerId)['notify_guest_activity'] ?? true);
        $state   = $this->loadState($ownerId);
        $shares  = [];
        foreach ($this->shareService->getSharesByOwner($ownerId) as $s) {
            $shares[$s['token']] = $s;
        }

        // Gelöschte Shares aus dem State entfernen
        $state = array_intersect_key($state, $shares);

        $sent = 0;
        foreach ($shares as $token => $share) {
            if (!isset($state[$token])) {
                // Erster Kontakt: Aktivität vor Einführung der Benachrichtigungen nicht melden,
                // neue Shares aber ab ihrer Erstellung mitzählen.
                $created = (int) ($share['created_at'] ?? 0);
                $state[$token] = ['wm' => max($since, $created - 1), 'seen' => 0, 'pending' => []];
            }

            $tokenState = $this->collect($ownerId, $share, $state[$token]);

            foreach ($tokenState['pending'] as $key => $p) {
                if ($p['last'] > $now - self::QUIET_SECONDS) {
                    continue;  // Gast ist noch dabei
                }
                if ($enabled && count($p['files']) > 0) {
                    $this->notify($ownerId, $share, $p);
                    $sent++;
                }
                unset($tokenState['pending'][$key]);
            }
            $state[$token] = $tokenState;
        }

        $this->saveState($ownerId, $state);
        return $sent;
    }

    /**
     * Sammelt neue Bewertungs-Einträge seit dem Watermark in die Pending-Liste.
     *
     * Timestamps haben Sekunden-Auflösung: 'seen' zählt die Einträge mit
     * timestamp == wm, die schon verarbeitet sind. So geht ein Eintrag, der in
     * derselben Sekunde nach dem letzten Lauf geloggt wurde, nicht verloren.
     *
     * @param array{wm: int, seen?: int, pending: array} $tokenState
     * @return array{wm: int, seen: int, pending: array}
     */
    private function collect(string $ownerId, array $share, array $tokenState): array
    {
        $wm      = (int) ($tokenState['wm'] ?? 0);
        $pending = is_array($tokenState['pending'] ?? null) ? $tokenState['pending'] : [];
        $seen    = (int) ($tokenState['seen'] ?? 0);
        $newWm   = $wm;
        $newSeen = $seen;
        $atWm    = 0;

        foreach ($this->shareService->getRawGuestLog($ownerId, $share['token']) as $e) {
            $ts = (int) ($e['timestamp'] ?? 0);
            if ($ts < $wm || ($ts === $wm && $atWm++ < $seen)) {
                continue;
            }
            if ($ts > $newWm) {
                $newWm   = $ts;
                $newSeen = 1;
            } elseif ($ts === $newWm) {
                $newSeen++;
            }

            $fileId = (int) ($e['file_id'] ?? 0);
            $isRating = $fileId > 0 && (
                ($e['rating'] ?? null) !== null
                || ($e['color'] ?? null) !== null
                || ($e['pick'] ?? null) !== null
            );
            if (!$isRating) {
                continue;  // Kommentare, Logins etc.
            }

            $name = $this->resolveGuestName((string) ($e['guest_name'] ?? ''), $share);
            $key  = mb_strtolower($name);
            if (!isset($pending[$key])) {
                $pending[$key] = ['name' => $name, 'files' => [], 'last' => 0];
            }
            $pending[$key]['files'][(string) $fileId] = 1;
            $pending[$key]['last'] = max($pending[$key]['last'], $ts);
        }

        return ['wm' => $newWm, 'seen' => $newSeen, 'pending' => $pending];
    }

    /**
     * Platzhalter-Namen aus dem Gast-Frontend ('Gast' / 'Guest') durch den am Share
     * hinterlegten Namen ersetzen. '' = unbekannt (Notifier zeigt „Ein Gast").
     */
    private function resolveGuestName(string $name, array $share): string
    {
        $name = trim($name);
        if ($name === '' || in_array(mb_strtolower($name), ['gast', 'guest'], true)) {
            return trim((string) ($share['guest_name'] ?? ''));
        }
        return $name;
    }

    private function notify(string $ownerId, array $share, array $pending): void
    {
        $path   = (string) ($share['nc_path'] ?? '');
        $folder = $path === '' ? '/' : basename($path);

        $n = $this->notificationManager->createNotification();
        $n->setApp(Application::APP_ID)
            ->setUser($ownerId)
            ->setDateTime(new \DateTime('@' . $pending['last']))
            ->setObject('share', $share['token'] . '/' . $pending['last'])
            ->setSubject(self::SUBJECT, [
                'guest'  => $pending['name'],
                'count'  => count($pending['files']),
                'folder' => $folder,
                'path'   => $path,
            ]);
        $this->notificationManager->notify($n);
    }

    /**
     * Zeitpunkt, ab dem Gast-Aktivität gemeldet wird (erster Job-Lauf nach dem Update).
     */
    private function enabledSince(int $now): int
    {
        $since = (int) $this->config->getAppValue(Application::APP_ID, self::CONFIG_SINCE, '0');
        if ($since <= 0) {
            $since = $now;
            $this->config->setAppValue(Application::APP_ID, self::CONFIG_SINCE, (string) $since);
        }
        return $since;
    }

    private function loadState(string $ownerId): array
    {
        $raw   = $this->config->getUserValue($ownerId, Application::APP_ID, self::CONFIG_STATE, '{}');
        $state = json_decode($raw, true);
        return is_array($state) ? $state : [];
    }

    private function saveState(string $ownerId, array $state): void
    {
        $this->config->setUserValue($ownerId, Application::APP_ID, self::CONFIG_STATE, json_encode($state));
    }
}
