# Changelog

## 1.3.6

### EN

**Bug fixes**
- **Guest ratings are now written into the JPEG XMP** (stars, color labels, pick/reject) — previously a rating made through a guest share link only updated the Nextcloud tag database, never the embedded XMP. Combined with the self-healing XMP read added in 1.3.3 (which treats the file's XMP as the source of truth when a JPEG is opened), a guest rating on a photo that already carried older XMP was silently reverted to the stale value the next time the owner opened it. The guest path now writes both — governed by the share owner's "write XMP" setting — so guest input survives and round-trips to Lightroom / digiKam like any owner rating.
- **Guests can now clear a color label** — clicking the active color again sends an explicit `null`, which an `isset()` check swallowed as "no change", so a guest could never remove a color. The guest endpoint now distinguishes "field absent" (unchanged) from "field present but empty" (clear), matching the logged-in path; the cleared state propagates to both the tag database and the XMP.

**Migration / recovery**
- **`occ starrate:heal-guest-xmp`** — one-time migration that recovers guest ratings made before this fix (on instances running 1.3.3–1.3.5 with XMP-write enabled) that the self-healing read may have reverted. It reconstructs each guest's intent from the per-share guest log — not the possibly-already-reverted database — and writes it back into both the tag database and the JPEG XMP. Conflict-safe: a file edited externally (Lightroom / digiKam) *after* the guest rated, detected by comparing the file's modification time against the guest-log timestamp, is left untouched. Defaults to a dry-run; `--write` applies, `--user` scopes to a single owner.
- **Upgrade check** — a repair step on app upgrade scans the guest logs read-only (no file I/O) and reports how many guest-rated files may need healing, pointing to the command above. It never modifies files itself.

### DE

**Bugfixes**
- **Gast-Bewertungen werden jetzt ins JPEG-XMP geschrieben** (Sterne, Farb-Labels, Pick/Reject) — bisher hat eine Bewertung über einen Gast-Link nur die Nextcloud-Tag-Datenbank aktualisiert, nie das eingebettete XMP. Zusammen mit dem in 1.3.3 eingeführten selbstheilenden XMP-Read (der beim Öffnen eines JPEGs das Datei-XMP als maßgeblich behandelt) wurde eine Gast-Bewertung auf einem Foto mit bereits vorhandenem älterem XMP beim nächsten Öffnen durch den Besitzer still auf den veralteten Wert zurückgesetzt. Der Gast-Pfad schreibt jetzt beides — gesteuert durch die „XMP schreiben"-Einstellung des Share-Besitzers — sodass die Gast-Eingabe erhalten bleibt und wie jede Besitzer-Bewertung nach Lightroom / digiKam zurückläuft.
- **Gäste können ein Farb-Label jetzt entfernen** — ein erneuter Klick auf die aktive Farbe sendet ein explizites `null`, das eine `isset()`-Prüfung als „keine Änderung" verschluckt hat — ein Gast konnte eine Farbe also nie löschen. Der Gast-Endpunkt unterscheidet jetzt „Feld fehlt" (unverändert) von „Feld vorhanden, aber leer" (löschen), analog zum eingeloggten Pfad; der gelöschte Zustand propagiert in Tag-Datenbank und XMP.

**Migration / Wiederherstellung**
- **`occ starrate:heal-guest-xmp`** — einmalige Migration, die vor diesem Fix abgegebene Gast-Bewertungen wiederherstellt (auf Instanzen mit 1.3.3–1.3.5 und aktiviertem XMP-Write), die der selbstheilende Read zurückgesetzt haben könnte. Sie rekonstruiert die Gast-Absicht aus dem Gast-Log pro Share — nicht aus der möglicherweise bereits zurückgesetzten Datenbank — und schreibt sie zurück in Tag-Datenbank und JPEG-XMP. Konfliktsicher: eine Datei, die *nach* der Gast-Bewertung extern (Lightroom / digiKam) bearbeitet wurde — erkannt am Vergleich von Datei-Änderungszeit und Gast-Log-Zeitstempel —, bleibt unangetastet. Standardmäßig Dry-Run; `--write` wendet an, `--user` begrenzt auf einen Besitzer.
- **Upgrade-Prüfung** — ein Repair-Step beim App-Upgrade scannt die Gast-Logs read-only (kein Datei-I/O) und meldet, wie viele gast-bewertete Dateien geheilt werden sollten, mit Verweis auf den obigen Befehl. Er verändert selbst keine Dateien.

## 1.3.5

### EN

**Bug fixes**
- **Nextcloud 34 compatibility: `Call to undefined method OC\Server::getURLGenerator()`** (#75) — `OC\Server::getURLGenerator()` was removed in NC 34, so loading the app on the latest Nextcloud release ended in a fatal error from `templates/index.php` (and the same call was duplicated in `templates/guest.php` for the guest gallery deep-link URL). Both call sites now resolve the URL generator through the supported DI entry point `\OCP\Server::get(\OCP\IURLGenerator::class)`, which has been available since NC 25 — so the fix covers the full declared compatibility range (29–34) without changing behavior on older releases. Thanks @miaulalala for the report and the spot-on suggested fix.

### DE

**Bugfixes**
- **Nextcloud 34 Kompatibilität: `Call to undefined method OC\Server::getURLGenerator()`** (#75) — `OC\Server::getURLGenerator()` wurde in NC 34 entfernt, sodass die App beim Laden auf der aktuellen Nextcloud-Version mit einem Fatal Error aus `templates/index.php` abstürzte (der gleiche Aufruf war auch in `templates/guest.php` für die Gast-Galerie-Deep-Link-URL doppelt). Beide Stellen holen den URL-Generator jetzt über den unterstützten DI-Entry-Point `\OCP\Server::get(\OCP\IURLGenerator::class)` — der ist seit NC 25 verfügbar, der Fix deckt also die volle deklarierte Kompatibilitätsspanne (29–34) ab, ohne das Verhalten auf älteren Versionen zu ändern. Danke an @miaulalala für die Meldung und den punktgenauen Fix-Vorschlag.

## 1.3.4

### EN

**Bug fixes**
- **Mobile: scroll seized after page 1 in huge grids** — at 7 000+ images, the virtualized grid container reached ~570 k px tall on a 2-column mobile layout, far past Chrome on Android's GPU tile cache. Past page 1 the user dragged into a region that wasn't pre-rasterized; Chrome had to rasterize tiles on demand, the compositor scroll fell back to the main thread, and the bitmap moved in row-sized jumps instead of pixel-by-pixel. Re-enabled the compression map at a 350 000 px cap so the scrollable container fits the cache; rewrote the top-spacer math with a continuous sub-row offset (`scrollTop − (logicalScrollTop mod rowStride) − BUFFER × rowStride`) so the visible content advances smoothly as `compressionRatio × scrollTop` instead of sticking at row boundaries and jumping at row ticks. At 7 000 images on mobile this gives a ratio of ~1.6 — content travels 1.6 px per finger pixel, which feels like normal flick-scroll momentum rather than the previous seize-and-jump pattern. Below the 350 k threshold (≤ ~5 k images on mobile, ≤ ~15 k on desktop) compression doesn't engage at all and scroll stays exactly 1:1.

**Documentation**
- App Store description now highlights recursive folder view, slideshow mode, and Lightroom round-trip as headline features alongside the existing rating/sharing toolset.
- Backfilled changelog entries for the 1.3.0–1.3.2 nightly releases that were not user-visible before (recursive view, slideshow, virtualized grid).

### DE

**Bugfixes**
- **Mobile: Scroll im großen Grid hakte ab Page 1** — bei 7 000+ Bildern wurde der virtualisierte Container im 2-Spalten-Mobile-Layout ~570 k px hoch, weit über Chromes GPU-Tile-Cache auf Android. Ab Page 2 ziehst du in einen Bereich, der nicht vorrasterisiert ist; Chrome muss Tiles on-demand rendern, der Compositor-Scroll fällt zurück auf den Main-Thread, und die Bitmap rückt in Zeilen-Sprüngen statt pixelweise. Compression-Map mit 350 000 px Cap reaktiviert, damit der Container in den Cache passt; topSpacer-Mathe mit kontinuierlichem Sub-Row-Offset neu geschrieben (`scrollTop − (logicalScrollTop mod rowStride) − BUFFER × rowStride`), sodass der sichtbare Inhalt linear mit `compressionRatio × scrollTop` läuft — nicht mehr „bleibt bei einer Zeile kleben und springt am Tick". Bei 7 000 Bildern auf Mobile ergibt das eine Ratio von ~1,6 — der Inhalt wandert 1,6 px pro Finger-Pixel, fühlt sich an wie normales Flick-Scroll-Momentum statt des vorherigen Hak-und-Spring-Musters. Unter der 350-k-Schwelle (≤ ~5 k Bilder Mobile, ≤ ~15 k Desktop) greift die Compression gar nicht erst, Scroll bleibt 1:1.

**Dokumentation**
- App-Store-Beschreibung hebt jetzt rekursive Ordneransicht, Diashow-Modus und Lightroom-Round-Trip als Hauptmerkmale neben den bestehenden Bewertungs- und Sharing-Funktionen hervor.
- Changelog-Einträge für die 1.3.0–1.3.2 Nightly-Releases nachgetragen, die zuvor nicht user-sichtbar waren (rekursive Ansicht, Diashow, virtualisiertes Grid).

## 1.3.3

### EN

**New features**
- **Color rating compatible with: Lightroom (DE) / Bridge / digiKam** (#69, #70) — new personal setting that controls the language of `xmp:Label`. With "Lightroom (German localization)" StarRate writes `xmp:Label="Rot"` etc. so a German LRC matches its native label set strings instead of falling through to "Custom" with a white flag. With "Bridge / digiKam / English tools" it writes `xmp:Label="Red"` etc. Default derives from the NC user UI language (`de_*` → DE, otherwise EN), so German users get a working LRC import out of the box. The companion `photoshop:LabelColor` field stays lowercase English regardless — that's universal and drives the LR color stripe.
- **Pick / Reject is now written into XMP** (#70) — previously stored only in NC tags. Bridge/LRC standard format: pick → `xmpDM:pick="1"` + `xmpDM:good="true"`, reject → `xmpDM:pick="-1"` + `xmpDM:good="false"`, none → both attributes removed. Read path is backward-compatible with the older FlashView schema (`flashView:IsPicked`/`IsRejected`); the write path actively cleans up those legacy attributes including the orphan `xmlns:flashView` namespace declaration.
- **Self-healing XMP read on loupe open** (#25, #72) — when a JPEG is opened in the loupe and the user has XMP-write enabled, StarRate now compares the file's XMP against its tag database and adopts the file's values if they differ. A photo edited externally in Lightroom or digiKam heals itself the next time you click into it — no manual `occ starrate:import-xmp` needed for the common case. The check is debounced 400 ms so rapid arrow-key navigation doesn't queue up server requests; only the image you actually linger on triggers a sync. Failures (permission denied, storage offline, corrupt header) silently fall back to the database value.

**Bug fixes**
- **LR re-imports the wrong color label after editing in StarRate** (#70) — Lightroom Classic 7+ reads `photoshop:LabelColor` (lowercase EN) with priority over `xmp:Label` to derive the color stripe. StarRate previously only wrote `xmp:Label`, and the patch path did not strip a stale `photoshop:LabelColor` left behind by LR — so a re-import in LR showed the old color, ignoring the change. StarRate now writes both fields in lockstep and removes any stale `photoshop:LabelColor` before re-injecting.
- **Grid only fills 2/3 of the viewport after navigating into a subfolder** (#71) — when SPA-navigating between folders, the header above the grid changed (subfolder pills appearing/disappearing), which moved the grid vertically without changing its own size. The existing `ResizeObserver` on the grid stayed silent and the cached `max-height` was wrong for the new layout, requiring a manual reload. Now the observer also watches the grid's wrap parent, so any header-size change re-runs the height calculation.
- **XMP read returned empty on Nextcloud's wrapped storage** (#72) — `fopen('rb') + fread($h, 256_000)` only delivered the first 8 KB block in NC's stacked storage (e.g. the `Files_Trashbin` wrapper), not the requested header size. Since LR-written XMP typically sits at offset 15–25 KB after the EXIF block, our parser never saw it. Switched to `stream_get_contents` which loops internally until limit or EOF — Self-healing now finds LR/Bridge/digiKam XMP reliably.

### DE

**Neue Features**
- **Farb-Bewertungen kompatibel mit: Lightroom (DE) / Bridge / digiKam** (#69, #70) — neue persönliche Einstellung, die die Sprache von `xmp:Label` steuert. Mit „Lightroom (deutsche Lokalisierung)" schreibt StarRate `xmp:Label="Rot"` etc., damit ein deutsches LRC den nativen Label-Set-String matcht und nicht auf „Custom" mit weißer Fahne durchfällt. Mit „Bridge / digiKam / englische Tools" wird `xmp:Label="Red"` etc. geschrieben. Default leitet sich aus der NC-UI-Sprache ab (`de_*` → DE, sonst EN) — deutsche User bekommen einen funktionierenden LRC-Import direkt out of the box. Das parallele Feld `photoshop:LabelColor` bleibt unabhängig davon immer in lowercase EN — das ist universell und treibt den Farbstreifen in LR.
- **Pick / Reject werden jetzt ins XMP geschrieben** (#70) — bislang nur als NC-Tag gespeichert. Bridge/LRC-Standardformat: Pick → `xmpDM:pick="1"` + `xmpDM:good="true"`, Reject → `xmpDM:pick="-1"` + `xmpDM:good="false"`, none → beide Attribute komplett entfernt. Der Lesepfad bleibt rückwärtskompatibel zum älteren FlashView-Schema (`flashView:IsPicked`/`IsRejected`); der Schreibpfad räumt diese Legacy-Attribute inklusive verwaister `xmlns:flashView`-Namespace-Deklaration aktiv weg.
- **Selbst-heilende XMP-Lese-Synchronisation beim Loupe-Open** (#25, #72) — wird ein JPEG in der Loupe geöffnet und der User hat XMP-Write aktiviert, vergleicht StarRate jetzt das XMP der Datei gegen die Tag-Datenbank und übernimmt die Datei-Werte falls sie abweichen. Ein in Lightroom oder digiKam extern bearbeitetes Foto heilt sich beim nächsten Klick darauf selbst — kein manueller `occ starrate:import-xmp`-Lauf für den Normalfall mehr nötig. Der Check ist 400 ms debounced, damit beim schnellen Pfeiltasten-Durchblättern keine Request-Queue aufläuft; nur das Bild, auf dem der User stehen bleibt, triggert einen Sync. Fehler (Permission, Storage offline, korrupter Header) fallen still auf den Datenbank-Wert zurück.

**Bugfixes**
- **LR re-importiert die falsche Farbe nach Änderung in StarRate** (#70) — Lightroom Classic 7+ liest `photoshop:LabelColor` (lowercase EN) mit Priorität über `xmp:Label`, um den Farbstreifen abzuleiten. StarRate hat bisher nur `xmp:Label` geschrieben, und der Patch-Pfad hat ein von LR hinterlassenes `photoshop:LabelColor` nicht entfernt — beim Re-Import in LR wurde die alte Farbe angezeigt, unsere Änderung ignoriert. StarRate schreibt jetzt beide Felder parallel und räumt ein stale `photoshop:LabelColor` vor dem Re-Inject weg.
- **Grid füllt nur 2/3 der Bildschirmhöhe nach Wechsel in einen Unterordner** (#71) — bei SPA-Navigation zwischen Ordnern änderte sich der Header oberhalb des Grids (Subfolder-Pills tauchten auf oder verschwanden), das Grid wurde vertikal verschoben, behielt aber seine eigene Größe. Der bestehende `ResizeObserver` auf dem Grid feuerte daher nicht und die gecachte `max-height` war für das neue Layout falsch — ein manueller Reload war nötig. Der Observer beobachtet jetzt zusätzlich das Wrap-Parent-Element, sodass jede Header-Größenänderung die Höhenberechnung neu auslöst.
- **XMP-Read lieferte leer in Nextclouds gestapelter Storage** (#72) — `fopen('rb') + fread($h, 256_000)` lieferte in NCs Storage-Stack (z.B. `Files_Trashbin` Wrapper) nur den ersten 8 KB-Block zurück, nicht die angeforderte Header-Größe. Da LR-geschriebenes XMP typischerweise bei Offset 15–25 KB nach dem EXIF-Block sitzt, hat unser Parser es nie gesehen. Auf `stream_get_contents` umgestellt — schleift intern bis Limit oder EOF; Self-healing findet jetzt LR/Bridge/digiKam-XMP zuverlässig.

## 1.3.2

### EN

**New features**
- **Slideshow mode in the loupe** (#67) — toggle with `S` to auto-advance through filtered images. Loops at the end. Per-user interval setting (1/2/3/4/5/7/10/15/30 s, default 4 s). Useful for client presentations: pre-filter to ★★★+, hand over the keyboard, walk away.
- **Logical scroll mapping for huge grids** (#68) — virtualized grid now stays smooth at 25 000+ images per folder; previously the browser scrollbar got jittery and Loupe-open could lag noticeably. Scroll position is now mapped logically, independent of physical row count.

**Bug fixes**
- **Reverse iteration in re-enqueue paths** — error'd thumbnails are now retried from top to bottom of the visible range, so the items the user actually sees come back first.

### DE

**Neue Features**
- **Diashow-Modus in der Loupe** (#67) — mit `S` umschalten, läuft automatisch durch die gefilterten Bilder. Loop am Ende. Pro-User-Intervall (1/2/3/4/5/7/10/15/30 s, Default 4 s). Nützlich für Kundenpräsentationen: auf ★★★+ vorfiltern, Tastatur übergeben, Raum verlassen.
- **Logical scroll mapping für riesige Grids** (#68) — das virtualisierte Grid bleibt jetzt auch bei 25 000+ Bildern pro Ordner flüssig; vorher zitterte der Browser-Scrollbar und das Loupe-Öffnen konnte spürbar laggen. Die Scroll-Position wird jetzt logisch gemappt, unabhängig von der physischen Reihenanzahl.

**Bugfixes**
- **Reverse-Iteration in Re-Enqueue-Pfaden** — fehlgeschlagene Thumbnails werden jetzt von oben nach unten im sichtbaren Bereich neu probiert, sodass die Items, die der User tatsächlich sieht, zuerst zurückkommen.

## 1.3.1

### EN

**New features**
- **Edit existing shares** — share dialog opens in edit mode when invoked on an existing share, lets you change password, expiry, or per-share permissions (rate, pick, export, comments) without recreating the link.
- **Per-share recursive view** — guest galleries can be configured to show all images from subfolders too, not just the share's root folder.
- **Per-folder recursive memory** — the recursive toggle and depth selector are remembered per folder in localStorage; revisiting a folder restores your last setting.

**Bug fixes**
- **Filter bar option text now legible in dark theme** — depth dropdown options were hard to read against the dark background; now use proper foreground color.
- **Mobile depth dropdown shows actual value** — overlay-span now displays the selected depth on mobile where the native select renders empty.
- **Filter bar recursive cluster vertically centered** — the recursive toggle group used to drift relative to its row.

### DE

**Neue Features**
- **Bestehende Shares bearbeiten** — der Share-Dialog öffnet sich im Edit-Modus, wenn er auf einem bestehenden Share aufgerufen wird; Passwort, Ablauf oder pro-Share-Berechtigungen (Bewerten, Pick, Export, Kommentare) ändern ohne den Link neu zu erstellen.
- **Pro-Share rekursive Ansicht** — Gast-Galerien können so konfiguriert werden, dass auch Bilder aus Unterordnern gezeigt werden, nicht nur aus dem Share-Root.
- **Pro-Ordner rekursiver Memory** — Recursive-Toggle und Tiefen-Selector werden pro Ordner in localStorage gemerkt; beim Wiederbesuch ist die letzte Einstellung wieder da.

**Bugfixes**
- **Filterleisten-Optionstext im Dark-Theme jetzt lesbar** — Tiefe-Dropdown-Optionen waren auf dem dunklen Hintergrund schlecht lesbar; jetzt korrekte Vordergrundfarbe.
- **Mobile-Tiefe-Dropdown zeigt echten Wert** — Overlay-Span zeigt jetzt die ausgewählte Tiefe auf Mobile, wo das native Select leer rendert.
- **Filterleiste-Recursive-Cluster vertikal zentriert** — die Recursive-Toggle-Gruppe lief früher relativ zu ihrer Zeile aus dem Ruder.

## 1.3.0

### EN

**Big new feature**
- **Recursive folder view** (#35) — toggle in the filter bar to flatten an entire folder tree into a single view. Pick a depth (0 = flat stream, 1–4 = grouped by N path segments). Personal opt-in setting (Default off). When enabled, you also get an in-context recursive toggle and depth selector right in the filter bar, plus URL-state-wiring for shareable bookmarks. Hover a tile to see the relative folder path as a dynamic breadcrumb tail. Defaults to off because recursive on huge libraries can be slow — see the Performance section for guardrails.

**Performance**
- **Virtualized grid** — folders with 1 000+ thumbnails no longer render every tile to the DOM. Only the ~100 tiles in or near the viewport are mounted, the rest is reserved as scroll space. Scroll-jank gone, Loupe-Open is instant again, batch-rate stays smooth.
- **Cancel-resistant thumbnail loading** — if you scroll fast, in-flight thumb requests for tiles you've passed are cancelled cleanly instead of fighting for bandwidth with the new visible range.
- **Recursive view OOM-/SQL-Limit-/Duplicate-Call protection** — the backend chunks tag queries and de-duplicates result paths; deep recursive scans don't exhaust PHP memory or trigger MySQL `LIMIT` errors.

**UX**
- **Dynamic breadcrumb tail** — in recursive mode, hovering a tile shows where it lives in the folder tree, without per-tile clutter.
- **Faster ESC out of the loupe** — perceived close time tightened.

### DE

**Großes neues Feature**
- **Rekursive Ordneransicht** (#35) — Toggle in der Filterleiste, um einen ganzen Ordnerbaum zu einer Ansicht zusammenzufassen. Tiefe wählen (0 = flacher Stream, 1–4 = nach N Pfad-Segmenten gruppiert). Opt-in pro User (Default aus). Wenn aktiviert, gibt's außerdem einen in-context Recursive-Toggle und Tiefen-Selector direkt in der Filterleiste plus URL-State-Wiring für teilbare Bookmarks. Hover über eine Kachel zeigt den relativen Ordnerpfad als dynamischen Breadcrumb-Tail. Default aus, weil Recursive auf großen Bibliotheken langsam sein kann — siehe Performance-Section für Schutzplanken.

**Performance**
- **Virtualisiertes Grid** — Ordner mit 1 000+ Thumbnails rendern nicht mehr jede Kachel ins DOM. Nur die ~100 Kacheln im oder nahe dem Viewport werden gemountet, der Rest wird als Scroll-Space reserviert. Scroll-Jank weg, Loupe-Open ist wieder instant, Batch-Rate bleibt flüssig.
- **Cancel-resistentes Thumbnail-Laden** — beim schnellen Scrollen werden in-flight Thumb-Requests für vorbeigegangene Kacheln sauber abgebrochen statt mit dem neuen sichtbaren Bereich um Bandbreite zu kämpfen.
- **Recursive-View OOM-/SQL-Limit-/Duplicate-Call-Schutz** — das Backend chunkt Tag-Queries und de-duplikiert Result-Pfade; tiefe rekursive Scans erschöpfen PHP-Memory nicht und triggern keine MySQL-`LIMIT`-Fehler.

**UX**
- **Dynamischer Breadcrumb-Tail** — im Recursive-Modus zeigt der Hover über eine Kachel, wo sie im Ordnerbaum lebt, ohne pro-Kachel-Geklütter.
- **Schnelleres ESC aus der Loupe** — gefühlte Schließzeit knapper.

## 1.2.11

### EN

**Performance**
- **Faster thumbnail loading** — the grid now fetches previews via NC's core `/core/preview` endpoint for logged-in users instead of going through the StarRate controller. Less PHP overhead per request, more cache hits.

**Bug fixes**
- **Thumbnails sometimes invisible until window re-shown** — fixed a paint-suppression bug where loaded grid thumbnails wouldn't appear until the browser window was occluded and re-exposed. Two interacting causes: the native `loading="lazy"` attribute fought against StarRate's own intersection-based preload queue, and `decoding="async"` let the browser defer image decoding so `<img>` elements landed in the DOM but didn't paint until a window-visibility change forced a full repaint. Removed the redundant `loading` attribute and now pre-decode preloaded images via `HTMLImageElement.decode()` before flipping `thumbLoaded` — the `<img>` is added to the DOM only once the bitmap is decode-ready and paints in the same frame.

### DE

**Performance**
- **Schnelleres Thumbnail-Laden** — das Grid lädt Previews jetzt für eingeloggte User über NCs `/core/preview`-Endpunkt statt über den StarRate-Controller. Weniger PHP-Overhead pro Request, mehr Cache-Treffer.

**Bugfixes**
- **Thumbnails manchmal unsichtbar bis Fenster neu aufgebaut** — Paint-Suppression-Bug behoben, bei dem geladene Grid-Thumbnails erst sichtbar wurden, nachdem das Browserfenster verdeckt und wieder aufgedeckt wurde. Zwei zusammenwirkende Ursachen: das native `loading="lazy"`-Attribut hat gegen StarRates eigenen Preload-Queue gearbeitet, und `decoding="async"` ließ den Browser den Decode aufschieben — `<img>`-Elemente landeten zwar im DOM, paintet wurden sie aber erst nach einem Force-Repaint via Fenster-Visibility-Wechsel. Das redundante `loading`-Attribut ist raus, und vorgeladene Bilder werden jetzt via `HTMLImageElement.decode()` pre-decoded, bevor `thumbLoaded` auf true geht — das `<img>` wandert erst dann ins DOM, wenn die Bitmap decode-ready ist, und paintet im selben Frame.

## 1.2.10

### EN

**Bug fixes**
- **Guest share: Nextcloud footer no longer overlaps images** (#62) — on public share pages NC renders an entity-name/legal/signup footer that on mobile could eat up to 1/3 of the viewport and on desktop pushed the grid up. The StarRate guest view now renders as a full-viewport overlay and hides NC's header/footer chrome (imprint/privacy remain reachable via the NC instance root). Big thanks to @matt-ek for the detailed repro and @lukegraphix for the independent reproduction on a fresh install.

### DE

**Bugfixes**
- **Gast-Freigabe: Nextcloud-Footer überlagert keine Bilder mehr** (#62) — auf Public-Share-Seiten rendert NC einen Footer mit Entity-Name, Legal-Links und Signup-Promo, der auf Mobile bis zu 1/3 des Viewports fressen konnte und auf Desktop das Grid hochgedrückt hat. Die StarRate-Gast-Ansicht legt sich jetzt als Vollbild-Overlay über die Seite und blendet NC-Header und -Footer aus (Impressum/Datenschutz bleiben über den Root der NC-Instanz erreichbar). Großer Dank an @matt-ek für die detaillierte Repro und @lukegraphix für die unabhängige Reproduktion auf einer frischen Installation.

## 1.2.9

### EN

A big leap since v1.1.0 — over 100 commits of new features, stability and polish. Highlights:

**New features**
- **Comments** — photographers and guests can leave comments on individual images. Great for client feedback rounds or quick self-notes. Toggleable per user, works in guest galleries too.
- **Export rating list** — CSV export of all rated images with selectable columns (filename, stars, color, pick/reject). Download or copy to clipboard. Guest export can be enabled per share.
- **Batch pick/reject** — select multiple images and flag them all as pick or reject in one click.
- **XMP import via occ** — new `occ starrate:import-xmp` command reads existing XMP ratings from Lightroom, digiKam & co. into StarRate. Use `--recursive` for whole folder trees.
- **Write-XMP as a user setting** — users who don't want XMP written into JPEGs can now disable it per account.
- **Guest logins in the share log** — sign-ins and failed password attempts now appear alongside ratings.

**UI & ergonomics**
- **Tidier nav row** — Share and Export moved into the filter bar; subfolders got their own row (desktop) or a popover menu (mobile). Breadcrumb is readable again.
- **Mobile polish** — filter bar scrolls horizontally cleanly, loupe rating footer no longer overlaps the image, deep paths truncate correctly.
- **ESC reliably closes modals** — no more accidental folder navigation when closing share/export dialogs.
- **Image count always visible** — you see how many images are in the folder even without an active filter.
- **Loupe performance** — preview preload, touch-zoom safety, less flicker when entering from the grid.

**Stability & compatibility**
- **Broader XMP compatibility** — `xap:` alias (Nokia Lumia, older Olympus), digiKam `ColorLabel`, self-closing `rdf:Description`, multi-block files.
- **Lightroom XMP fields preserved** on rating updates (was a regression in 1.1, issue #16).
- **Debounced batch ratings** — prevents concurrent JPEG writes when clicking fast. Info toasts surface skipped files.
- **Security** — guest comment endpoints hardened, rating requests now verify the file actually belongs to the share.
- **Rate limiting** — guest previews allow 600/min for smooth scrolling through large galleries.

**Under the hood**
- Test coverage gates: Vitest with coverage threshold, PHPUnit on PHP 8.1 + 8.3, Cypress E2E suite grown to 72 passing tests.
- Hardened CI: releases only from the correct branch, nightlies no longer auto-promote to the app store.
- Nextcloud 33 supported.

### DE

Ein großer Sprung seit v1.1.0 — über 100 Commits voll neuer Features, Stabilität und Politur. Die Highlights:

**Neue Features**
- **Kommentare** — Fotografen und Gäste können jetzt Kommentare zu einzelnen Bildern hinterlassen. Ideal für Feedback-Runden mit Kunden oder als Notiz an sich selbst. Ein- und ausschaltbar pro Benutzer, funktioniert auch in Gast-Galerien.
- **Bewertungsliste exportieren** — CSV-Export aller bewerteten Bilder mit wählbaren Spalten (Dateiname, Sterne, Farbe, Pick/Reject). Herunterladen oder in die Zwischenablage kopieren. Gast-Export kann pro Share erlaubt werden.
- **Batch Pick/Reject** — Mehrere Bilder markieren, mit einem Klick alle als Pick oder Reject flaggen.
- **XMP-Import via occ** — Neuer Befehl `occ starrate:import-xmp` liest bestehende XMP-Bewertungen aus Lightroom, digiKam & Co. in StarRate ein. Mit `--recursive` für ganze Ordnerbäume.
- **XMP-Write als Nutzer-Einstellung** — wer XMP nicht in JPEGs schreiben möchte, kann das jetzt pro Benutzer abschalten.
- **Gast-Login im Share-Log** — Anmeldungen und Fehlversuche tauchen jetzt im Protokoll auf, nicht nur Bewertungen.

**UI & Bedienung**
- **Aufgeräumte Nav-Zeile** — Teilen und Export sind in die FilterBar gewandert, Unterordner erscheinen als eigene Zeile (Desktop) bzw. als Popover-Menü (Mobile). Breadcrumb ist dadurch wieder lesbar.
- **Mobile-Polish** — FilterBar scrollt sauber horizontal, der Bewertungs-Footer in der Loupe überlappt das Bild nicht mehr, tiefe Pfade werden korrekt abgeschnitten.
- **ESC schließt Modals zuverlässig** — keine versehentliche Ordner-Navigation mehr, wenn man Share- oder Export-Dialog schließt.
- **Bildzähler immer sichtbar** — auch ohne aktiven Filter sieht man, wie viele Bilder im Ordner liegen.
- **Loupe-Performance** — Preview-Preload, Touch-Zoom-Schutz, weniger Flackern beim Einstieg aus dem Grid.

**Stabilität & Kompatibilität**
- **XMP-Kompatibilität erweitert** — `xap:`-Alias (Nokia Lumia, ältere Olympus), digiKam `ColorLabel`, selbst-schließendes `rdf:Description`, Multi-Block-Dateien.
- **Lightroom-XMP-Felder** bleiben bei Rating-Updates erhalten (war Bug in 1.1, Issue #16).
- **Batch-Ratings debounced** — verhindert konkurrierende JPEG-Writes beim schnellen Klicken. Sichtbare Info-Toasts für Skips.
- **Sicherheit** — Gast-Kommentar-Endpoints gehärtet, Rating-Requests prüfen jetzt, dass die Datei tatsächlich zum Share gehört.
- **Rate Limiting** — Gast-Previews erlauben 600/min für flüssiges Durch-Scrollen großer Galerien.

**Unter der Haube**
- Umfassende Test-Coverage-Gates: Vitest mit Coverage-Threshold, PHPUnit auf PHP 8.1 + 8.3, Cypress-E2E-Suite auf 72 grüne Tests ausgebaut.
- CI-Workflow gehärtet: Release nur aus korrekten Branches, Nightly geht per Default nicht mehr automatisch in den Store.
- Nextcloud 33 unterstützt.
