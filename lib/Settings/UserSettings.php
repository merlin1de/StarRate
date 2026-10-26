<?php

declare(strict_types=1);

namespace OCA\StarRate\Settings;

use OCA\StarRate\Service\XmpService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\IUserSession;

/**
 * Persönliche Einstellungen für StarRate.
 * Erscheint unter Nextcloud → Einstellungen → Persönlich → StarRate.
 */
class UserSettings implements ISettings
{
    private const APP_ID = 'starrate';

    /** Erlaubte Diashow-Intervalle in Sekunden. Spiegel zu src/utils/slideshow.js. */
    public const SLIDESHOW_INTERVALS = [1, 2, 3, 4, 5, 7, 10, 15, 30];

    public const SLIDESHOW_DEFAULT_SEC = 4;

    public function __construct(
        private readonly IConfig      $config,
        private readonly IUserSession $userSession,
    ) {}

    public function getForm(): TemplateResponse
    {
        $userId   = $this->userSession->getUser()?->getUID() ?? '';
        $settings = $this->getSettings($userId);

        return new TemplateResponse(self::APP_ID, 'settings/personal', [
            'settings' => $settings,
        ], '');
    }

    public function getSection(): string
    {
        return 'starrate';
    }

    public function getPriority(): int
    {
        return 50;
    }

    // ─── Einstellungen lesen / schreiben ──────────────────────────────────────

    /**
     * Gibt alle Benutzereinstellungen zurück.
     */
    public function getSettings(string $userId): array
    {
        return [
            'default_sort'             => $this->get($userId, 'default_sort', 'name'),
            'default_sort_order'       => $this->get($userId, 'default_sort_order', 'asc'),
            'show_filename'            => $this->getBool($userId, 'show_filename', true),
            'show_rating_overlay'      => $this->getBool($userId, 'show_rating_overlay', true),
            'show_color_overlay'       => $this->getBool($userId, 'show_color_overlay', true),
            'grid_columns'             => $this->get($userId, 'grid_columns', 'auto'),
            'enable_pick_ui'           => $this->getBool($userId, 'enable_pick_ui', false),
            'write_xmp'                => $this->getBool($userId, 'write_xmp', true),
            // Kamera-RAWs (NEF, CR3, …) im Grid und in Gast-Galerien zeigen. Opt-in:
            // wer RAW+JPEG fotografiert, sähe sonst nach dem Update jedes Bild doppelt.
            'show_raw'                 => $this->getBool($userId, 'show_raw', false),
            // RAW (NEF etc.): Bewertung als XMP-Sidecar neben die RAW-Datei schreiben
            // (DSC_0001.NEF → DSC_0001.xmp). Wirkt nur zusammen mit show_raw + write_xmp.
            'write_raw_sidecar'        => $this->getBool($userId, 'write_raw_sidecar', true),
            // XMP-Label-Sprache: 'de' = Lightroom DE-Lokalisierung (Rot/Gelb/…),
            // 'en' = Bridge/digiKam/englische Tools (Red/Yellow/…). Default leitet
            // sich aus der NC-UI-Sprache ab (de_* → de, sonst en) damit DE-User
            // sofort funktionierende LR-DE-Imports bekommen, ohne Setting anzufassen.
            'xmp_label_language'       => $this->get($userId, 'xmp_label_language', $this->defaultLabelLanguage($userId)),
            'comments_enabled'         => $this->getBool($userId, 'comments_enabled', false),
            // Recursive-View Defaults — werden beim Folder-Open als initiale
            // Werte verwendet; URL-Params können sie pro View überschreiben.
            // Master-Schalter: deaktiviert das gesamte Recursive-Feature inkl.
            // FilterBar-Toggle, Tiefe-Selector und URL-Param-Auswertung. Default
            // false — Nutzer muss Feature bewusst freischalten. Macht Rollout
            // und Testing kontrollierbarer (Tester wissen, dass sie es aktivieren
            // müssen) und entlastet Solo-Workflows ohne tiefe Ordnerstruktur.
            'recursion_enabled'        => $this->getBool($userId, 'recursion_enabled', false),
            'recursive_default'        => $this->getBool($userId, 'recursive_default', false),
            'recursive_default_depth'  => $this->getInt($userId, 'recursive_default_depth', 0),
            // Diashow-Intervall in Sekunden. Siehe Loupe „S“-Toggle.
            'slideshow_interval'       => $this->getInt($userId, 'slideshow_interval', self::SLIDESHOW_DEFAULT_SEC),
        ];
    }

    /**
     * Speichert eine oder mehrere Einstellungen.
     *
     * @param  array<string, mixed> $data
     * @throws \InvalidArgumentException bei ungültigen Werten
     */
    public function saveSettings(string $userId, array $data): void
    {
        $allowed = [
            'default_sort', 'default_sort_order',
            'show_filename', 'show_rating_overlay',
            'show_color_overlay', 'grid_columns', 'enable_pick_ui', 'write_xmp', 'show_raw', 'write_raw_sidecar', 'comments_enabled',
            'recursion_enabled', 'recursive_default', 'recursive_default_depth',
            'slideshow_interval',
            'xmp_label_language',
        ];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException("Unbekannte Einstellung: {$key}");
            }

            $this->validate($key, $value);
            $this->config->setUserValue(
                $userId,
                self::APP_ID,
                $key,
                is_bool($value) ? ($value ? '1' : '0') : (string) $value
            );
        }
    }

    // ─── Hilfsmethoden ────────────────────────────────────────────────────────

    private function get(string $userId, string $key, string $default): string
    {
        return $this->config->getUserValue($userId, self::APP_ID, $key, $default);
    }

    private function getBool(string $userId, string $key, bool $default): bool
    {
        $val = $this->config->getUserValue($userId, self::APP_ID, $key, null);
        if ($val === null) return $default;
        return in_array($val, ['1', 'true', 'yes'], true);
    }

    private function getInt(string $userId, string $key, int $default): int
    {
        $val = $this->config->getUserValue($userId, self::APP_ID, $key, null);
        return $val === null ? $default : (int) $val;
    }

    private function validate(string $key, mixed $value): void
    {
        match ($key) {
            'default_sort' => $this->assertIn($key, $value, ['name', 'mtime', 'size']),
            'default_sort_order' => $this->assertIn($key, $value, ['asc', 'desc']),
            'grid_columns' => $this->assertIn($key, $value, ['auto', '2', '3', '4', '5', '6', '8']),
            'recursive_default_depth' => $this->assertIntRange($key, $value, 0, 4),
            'slideshow_interval' => $this->assertIn($key, (int) $value, self::SLIDESHOW_INTERVALS),
            'xmp_label_language' => $this->assertIn($key, $value, XmpService::VALID_LABEL_LANGS),
            'show_filename', 'show_rating_overlay', 'show_color_overlay',
            'enable_pick_ui', 'write_xmp', 'show_raw', 'write_raw_sidecar', 'comments_enabled',
            'recursion_enabled', 'recursive_default' => null,
        };
    }

    /**
     * Leitet die Default-Label-Sprache aus der NC-UI-Sprache des Users ab.
     * de_* → 'de' (LR DE-Lokalisierung), sonst → 'en' (Bridge/digiKam-Standard).
     */
    private function defaultLabelLanguage(string $userId): string
    {
        if ($userId === '') {
            return XmpService::LABEL_LANG_EN;
        }
        $lang = $this->config->getUserValue($userId, 'core', 'lang', '');
        return str_starts_with($lang, 'de') ? XmpService::LABEL_LANG_DE : XmpService::LABEL_LANG_EN;
    }

    private function assertIntRange(string $key, mixed $value, int $min, int $max): void
    {
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false || $intVal < $min || $intVal > $max) {
            throw new \InvalidArgumentException(
                "{$key} muss eine ganze Zahl zwischen {$min} und {$max} sein."
            );
        }
    }

    private function assertIn(string $key, mixed $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$key} muss einer von " . implode(', ', $allowed) . " sein."
            );
        }
    }

}
