# StarRate — Lightroom-style Photo Rating for Nextcloud

**Rate, label and curate your photos directly in Nextcloud — with 1–5 stars, color labels, and XMP metadata that Lightroom, digiKam and F-Stop can read.**

[![CI](https://github.com/merlin1de/starrate/actions/workflows/ci.yml/badge.svg)](https://github.com/merlin1de/starrate/actions/workflows/ci.yml)
[![Nextcloud App Store](https://img.shields.io/badge/Nextcloud%20App%20Store-StarRate-blue?logo=nextcloud)](https://apps.nextcloud.com/apps/starrate)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-green.svg)](LICENSE)
[![Nextcloud 29–35](https://img.shields.io/badge/Nextcloud-29–35-0082c9?logo=nextcloud)](https://apps.nextcloud.com/apps/starrate)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)](https://php.net)

> 🌳 **New in v1.3** — Recursive folder view: browse a whole folder tree as one stream, optionally grouped by folder depth. → [How it works](docs/recursive-folders.en.md)

> **The missing photo rating tool for Nextcloud photographers.**  
> Stop switching apps. Rate your shots, share a guest link with your client, and open them in Lightroom — the stars are already there.

---

## Why StarRate?

Photographers using Nextcloud for file storage hit the same two walls:

1. **No way to rate and curate photos inside Nextcloud** — you have to export to Lightroom, rate, re-import, repeat.
2. **No way to let a client pick favorites** without giving them your full Nextcloud access or sending a Google Drive link.

StarRate solves both — and it keeps your ratings inside the JPEG itself, so every app that reads XMP metadata sees them.

---

## XMP Metadata Compatibility

StarRate writes ratings directly into the **JPEG APP1 segment** (XMP standard). No sidecar files. No re-export needed.

Camera RAW files (NEF, CR2, ARW, DNG, RAF, …) are never modified: their ratings go into a Lightroom-style sidecar (`DSC_0001.NEF` → `DSC_0001.xmp`). An existing sidecar is patched in place, so develop settings stay intact. In Lightroom Classic: *Metadata → Read Metadata from Files*.

| Application | Reads StarRate ratings |
|---|---|
| Adobe Lightroom Classic | ✅ |
| digiKam | ✅ |
| F-Stop (Android) | ✅ |
| Darktable | ✅ |

Rate in Nextcloud → open in Lightroom → stars are already there.

---

## Features

| Feature | Description |
|---|---|
| ⭐ Star rating | 0–5 stars, hover preview, keyboard shortcuts `0`–`5` |
| 🎨 Color labels | Red / Yellow / Green / Blue / Purple (keys `6`–`9`) |
| 🚩 Pick / Reject | Flag images with `P` / `X`, batch support via selection bar |
| 📝 XMP metadata | Ratings written directly into JPEG (APP1 segment) |
| 📷 Camera RAW | NEF, CR2, ARW, DNG, PEF, ORF, RW2, RAF in grid, loupe and guest galleries. The preview is the JPEG the camera embeds (no demosaicing, no extra server packages); ratings go into an XMP sidecar |
| 📥 XMP import | `occ starrate:import-xmp` — import existing ratings from Lightroom, digiKam, etc. |
| 💬 Comments | Per-photo notes in loupe view — owner and guests can comment |
| 📤 Export | CSV download or clipboard copy of ratings and metadata |
| ⬇️ Download | Original images — a single shot from the loupe or the whole selection as a ZIP; for logged-in users and, per share, for guests |
| 🔍 Filter bar | Combinable filters: stars, color, Pick/Reject, unrated |
| 🌳 Recursive view | Browse a whole folder tree as one stream — flat or grouped by folder depth ([details](docs/recursive-folders.en.md)) |
| 🎞️ Slideshow | Loupe auto-advances through filtered images at a configurable interval — ideal for client presentations |
| 🔎 Loupe view | Zoom 25–400 %, pan, pinch-to-zoom, keyboard navigation |
| 🖱️ Batch rating | `Shift+click` / `Ctrl+click`, `Ctrl+A`, floating selection bar |
| 🔗 Guest sharing | Public gallery links — clients rate without a Nextcloud account |
| 🌙 Dark theme | Anthracite UI (`#1a1a2e`) with red accent (`#e94560`) |
| 🌍 i18n | English and German |

---

## Screenshots

### Grid view with star ratings and color labels
![Grid view](screenshots/starrate-grid.jpg)

### Loupe view with zoom and keyboard navigation
![Loupe view](screenshots/starrate-loupe.jpg)

### Guest gallery on mobile — rate without a Nextcloud account
![Guest gallery on mobile](screenshots/starrate-mobile-guest.jpg)

---

## Guest Sharing — The Client Workflow

1. Open a folder in StarRate → click **Share**
2. Set permission to **View + Rate** or **View only**
3. Optionally: add a password, expiry date, and minimum star filter
4. Copy the link → send to your client or model

Guests can rate images in their browser — **no Nextcloud account required**. The photographer sees all guest ratings in real time. Optionally, enable comments so guests can leave per-photo notes.

---

## Requirements

- Nextcloud 29–35
- PHP 8.1–8.5
- Node.js 18+ / npm 9+
- Composer 2

---

## Installation

### From the Nextcloud App Store *(recommended)*

Search for **StarRate** in the Apps section of your Nextcloud admin panel and click Install.

Or go directly: [apps.nextcloud.com/apps/starrate](https://apps.nextcloud.com/apps/starrate)

### Manual installation

```bash
git clone https://github.com/merlin1de/starrate.git /var/www/nextcloud/custom_apps/starrate
cd /var/www/nextcloud/custom_apps/starrate
composer install --no-dev
npm ci && npm run build
sudo -u www-data /var/www/nextcloud/occ app:enable starrate
```

---

## The StarRate Ecosystem

One shared XMP rating contract — multiple apps that read and write it.

| Component                  | Status         | What it does                                                                                       |
|----------------------------|----------------|----------------------------------------------------------------------------------------------------|
| **StarRate for Nextcloud** | This repo      | Rate in your browser, share guest galleries, write XMP straight into the JPEG                      |
| **StarRate for Android**   | Coming soon    | Native app with Nextcloud SSO and `starrate://` deep links for guest shares                        |
| **[FlashView](https://flashview.net)** | In development | A lightning-fast Windows image viewer — opens folders instantly and tags the very same XMP that StarRate reads |

The shared workflow: cull thousands of shots in FlashView on your desktop, sync the folder to Nextcloud, and send a guest link to your client. Photographer and client edit the same XMP metadata — every star, color label and pick stays in sync, no re-export needed.

⭐ **Star this repo** to get notified when the Android beta launches.

---

## Development

```bash
make install-deps   # Install all dependencies
make test           # PHPUnit + Vitest
make build          # Production bundle → js/
make lint           # PHP_CodeSniffer + ESLint
make package        # → dist/starrate.tar.gz
```

### Run tests

```bash
make test          # PHPUnit + Vitest
make test-php      # PHPUnit only
make test-js       # Vitest only (468 component tests)
make test-e2e      # Cypress headless (requires a running Nextcloud instance)
npm run e2e:open   # Cypress GUI with browser selection
make test-coverage # Reports written to tests/results/
```

CI runs on every PR: Vitest (468 component tests) + PHPUnit (304 tests, PHP 8.1 + 8.4 + 8.5)

---

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE).

For commercial licensing (mobile apps, white-label, OEM): **starrate@merlin1.de**

---

## Contributing

Pull requests and bug reports are welcome! Before submitting: `make test && make build`
