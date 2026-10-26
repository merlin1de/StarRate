<template>
  <div
    class="sr-grid"
    ref="gridEl"
    tabindex="0"
    :style="gridStyle"
    @keydown="onGlobalKeydown"
  >
    <!-- Skeleton-Loader -->
    <template v-if="loading">
      <div v-for="i in skeletonCount" :key="`skel-${i}`" class="sr-grid__item sr-grid__item--skeleton">
        <div class="sr-grid__skeleton-img" />
        <div class="sr-grid__skeleton-bar" />
      </div>
    </template>

    <!-- Bilder -->
    <template v-else>
      <!-- Virtualisierungs-Spacer oben: konsumiert eine volle Grid-Reihe via grid-column 1/-1.
           Damit fließen die folgenden Items dank Grid-Auto-Flow korrekt in die nächste Reihe. -->
      <div
        v-if="topSpacerHeight > 0"
        class="sr-grid__spacer"
        :style="{ height: topSpacerHeight + 'px' }"
        aria-hidden="true"
      />

      <div
        v-for="(image, index) in renderedImages"
        :key="image.id"
        class="sr-grid__item"
        :class="{
          'sr-grid__item--selected': isSelected(image.id),
          'sr-grid__item--focused':  focusedIndex === (renderStartIdx + index),
          'sr-grid__item--pick':     enablePickUi && image.pick === 'pick',
          'sr-grid__item--reject':   enablePickUi && image.pick === 'reject',
        }"
        :data-index="renderStartIdx + index"
        :title="image.relPath && image.relPath !== image.name ? image.relPath : image.name"
        @click="onItemClick($event, image, renderStartIdx + index)"
        @dblclick="$emit('open-loupe', image, renderStartIdx + index)"
        @mouseover="$emit('focus-preview', image)"
      >
        <!-- Thumbnail -->
        <div class="sr-grid__thumb-wrap">
          <!-- Placeholder + <img> bleiben BEIDE permanent im DOM. Kein v-if.
               Grund: DOM-Inserts/Removes während Image-Loads haben Paint-Suppression
               ausgelöst (Browser verwirft den Paint des frisch eingefügten/geänderten
               Nachbarn, Thumbs bleiben schwarz bis Window-Redraw).
               Mechanik: <img> hat permanent opacity:1. Solange src=BLANK_PIXEL
               (transparentes 1×1-GIF) ist, schimmert der dahinter liegende
               Placeholder durch. Sobald das opake JPEG lädt, überdeckt es den
               Placeholder. Kein Opacity-Fade, kein DOM-Umbau — deshalb auch keine
               Paint-Suppression. -->
          <div
            class="sr-grid__thumb-placeholder"
            :class="{
              'sr-grid__thumb-placeholder--hidden': image.thumbLoaded,
              'sr-grid__thumb-placeholder--error':  image.thumbError,
            }"
          />
          <img
            class="sr-grid__thumb"
            :src="image.thumbUrl || BLANK_PIXEL"
            :alt="image.name"
            decoding="sync"
            draggable="false"
            @load="onImgLoad(image)"
            @error="onImgError(image)"
          />

          <!-- Pick-Badge -->
          <div v-if="enablePickUi && image.pick === 'pick'" class="sr-grid__pick-badge" aria-label="Picked">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none">
              <circle cx="12" cy="12" r="10" fill="rgba(0,0,0,0.45)"/>
              <polyline points="7 12.5 10.5 16 17 9" stroke="#4caf50" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>

          <!-- Reject-Overlay -->
          <div v-if="enablePickUi && image.pick === 'reject'" class="sr-grid__reject-overlay">
            <span>✕</span>
          </div>

          <!-- Auswahl-Indikator -->
          <div v-if="isSelected(image.id)" class="sr-grid__select-badge">
            <svg viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>

        </div>

        <!-- Info-Leiste unten -->
        <div class="sr-grid__info">
          <!-- Sterne (linke Seite) -->
          <RatingStars
            v-if="showRatingInfo && enableRatingUi"
            :model-value="image.rating"
            :interactive="false"
            :compact="true"
            class="sr-grid__info-stars"
          />
          <!-- Dateiname (Mitte) -->
          <span v-if="showFilename" class="sr-grid__info-name" :title="image.name">{{ image.name }}</span>
          <!-- Farbpunkt (rechts) -->
          <span
            v-if="showColorInfo && enableColorUi && image.color"
            class="sr-grid__info-color"
            :class="`sr-grid__info-color--${image.color.toLowerCase()}`"
            :title="image.color"
          />
        </div>
      </div>

      <!-- Virtualisierungs-Spacer unten -->
      <div
        v-if="bottomSpacerHeight > 0"
        class="sr-grid__spacer"
        :style="{ height: bottomSpacerHeight + 'px' }"
        aria-hidden="true"
      />

      <!-- Leer-Zustand -->
      <div v-if="!loading && images.length === 0" class="sr-grid__empty">
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="8" y="16" width="48" height="36" rx="4" stroke="#555" stroke-width="2"/>
          <circle cx="22" cy="28" r="4" stroke="#555" stroke-width="2"/>
          <path d="M8 40l12-10 8 8 8-6 12 8" stroke="#555" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p>{{ t('starrate', 'Keine Bilder in diesem Ordner') }}</p>
        <button
          v-if="hasActiveFilter"
          class="sr-grid__empty-cta"
          type="button"
          @click="$emit('clear-filter')"
        >{{ t('starrate', 'Alle Filter löschen') }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import RatingStars from './RatingStars.vue'
import {
  spacerHeightForRows as _spacerHeightForRows,
  computeCompressionRatio,
  computeTopSpacerHeight,
  iterateViewportFirst,
} from '../utils/gridLayout.js'

const props = defineProps({
  /** Array von Bild-Objekten (von der API) */
  images: {
    type: Array,
    default: () => [],
  },
  /** Lädt gerade */
  loading: {
    type: Boolean,
    default: false,
  },
  /** Aktiver Filter hat Ergebnisse gefiltert */
  hasActiveFilter: {
    type: Boolean,
    default: false,
  },
  /** Aktuell fokussiertes Bild (Lupe-Sync) */
  currentIndex: {
    type: Number,
    default: -1,
  },
  thumbnailUrlFn: {
    type: Function,
    default: null,
  },
  /** Grid-Spalten: 'auto' | '2' | '3' | '4' | '5' | '6' | '8' */
  gridColumns:   { type: String,  default: 'auto' },
  /** Dateiname in der Info-Leiste anzeigen */
  showFilename:      { type: Boolean, default: true },
  /** Sterne in der Info-Leiste anzeigen */
  showRatingInfo:    { type: Boolean, default: true },
  /** Farbpunkt in der Info-Leiste anzeigen */
  showColorInfo:     { type: Boolean, default: true },
  /** Sterne-Bewertung aktiv (Anzeige + Tasten 0–5) */
  enableRatingUi:    { type: Boolean, default: true },
  /** Farbmarkierung aktiv (Anzeige + Tasten 6–9/V) */
  enableColorUi:     { type: Boolean, default: true },
  /** Pick/Reject-UI anzeigen */
  enablePickUi:      { type: Boolean, default: false },
  /** Sichtbar (v-show vom Parent). Wird false wenn Loupe aktiv ist —
   *  dann re-syncen wir beim Re-Aktivieren die Scroll-Position. */
  active:            { type: Boolean, default: true },
})

const emit = defineEmits([
  'rate',             // (image, rating|undefined, color|undefined, pick|undefined)
  'batch-rate',       // (rating|undefined, color|undefined, pick|undefined) — bei aktiver Mehrfachauswahl
  'open-loupe',       // (image, index)
  'selection-change', // (selectedIds: Set)
  'clear-filter',     // ()
  'focus-preview',    // (image) — Hover oder Keyboard-Focus, für dynamischen Breadcrumb-Tail im Recursive-Modus
])

// 1×1 transparenter PNG als initialer src für das immer-vorhandene <img>.
// Verhindert Broken-Image-Icon, ohne HTTP-Request auszulösen (data: URI).
const BLANK_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='

const THUMB_SIZE = 280

const gridStyle = computed(() => {
  // gridTemplateColumns direkt als Inline-Style – CSS-custom-properties mit repeat()
  // werden in einigen Browsern nicht korrekt in grid-template-columns geparsed.
  if (props.gridColumns !== 'auto') {
    return { gridTemplateColumns: `repeat(${props.gridColumns}, 1fr)` }
  }
  // min() stellt sicher dass auf Mobile mindestens 2 Spalten passen:
  // min(280px, calc(50vw - 16px)) → Desktop: 280px, Mobile 390px: ~179px → 2 Spalten
  return { gridTemplateColumns: `repeat(auto-fill, minmax(min(${THUMB_SIZE}px, calc(50vw - 16px)), 1fr))` }
})

// ─── Zustand ──────────────────────────────────────────────────────────────────

const gridEl       = ref(null)
const focusedIndex = ref(-1)
const selectedIds  = ref(new Set())
const lastClickIdx = ref(-1)
const skeletonCount = 16

// Thumbnail-Cache: überlebt Filter-Wechsel
const thumbCache = ref({})

// ─── Virtualisierung ─────────────────────────────────────────────────────────
//
// Strategie: Das CSS-Grid bleibt unverändert. Vor und hinter dem sichtbaren
// Bereich liegen zwei Spacer-Divs mit grid-column: 1/-1, die jeweils die Höhe
// der nicht-gerenderten Reihen einnehmen. Items dazwischen fließen via
// Grid-Auto-Flow in die korrekten Spalten. Vorteile: Selektion, Keyboard-Nav,
// Hover, Info-Bar, Thumbnail-Loading bleiben unangetastet — wir reduzieren
// nur die Anzahl der gleichzeitig im DOM lebenden Items.
//
// data-index bleibt der absolute Index — Thumbnail-Observer und Focus-Logik
// arbeiten weiter mit Original-Indizes.
//
// Zwei Modi koexistieren, getriggert durch compressionRatio:
//   ratio = 1  (kleine/mittlere Listen, fullLogicalHeight ≤ MAX_PHYSICAL_HEIGHT):
//              klassische Spacer-Höhen via spacerHeightForRows(); scrollTop läuft
//              1:1 zur Bild-Position, der Container ist exakt totalHeight hoch.
//   ratio > 1 (große Listen, Container würde sonst Chromes Tile-Cache überschreiten):
//              physicalScrollHeight = fullLogicalHeight / ratio; topSpacer folgt
//              kontinuierlich dem scrollTop minus rowOffset (siehe topSpacer-Formel),
//              Inhalt bewegt sich pro 1 px Scroll um `ratio` px relativ zum Viewport,
//              glatt und ohne Row-Tick-Sprung.

const VIRTUAL_BUFFER_ROWS = 2     // extra Reihen oberhalb/unterhalb des Viewports
const INFO_BAR_HEIGHT     = 26    // ~ min-height der .sr-grid__info Bar
const TILE_ASPECT         = 0.75  // padding-top: 75% (4:3)
const GRID_GAP            = 6     // gap: 6px aus CSS

// Cap der physischen Container-Höhe in px. Wenn die rechnerische Container-Höhe
// diesen Wert überschreitet, wird der Scroll logisch komprimiert: scrollTop
// 0..MAX mappt linear auf alle Items.
//
// Warum: Chrome auf Android (und ähnlich andere mobile Browser) hält bei
// Scroll-Containern den GPU-Tile-Cache nur für eine begrenzte Container-Höhe
// vor. Bei 7000 Items × Mobile-Layout (≈ 570k px) verlässt der User schon ab
// Page 2 den vorgerechneten Tile-Bereich; Chrome muss nachrasterisieren, der
// Compositor-Scroll fällt zurück auf Main-Thread, sichtbar als „Bitmap rückt
// in Zeilenschritten statt pixelweise". Bei 1200 Items (≈ 100k) passt das in
// den Cache, dort scrollt es smooth.
//
// 350k px ist der empirisch saubere Sweet-Spot: deckt den Cache-Bereich auf
// gängigen Mobile-Geräten ab und beschneidet erst große Ordner (>5k Bilder
// auf Mobile, >15k auf Desktop). Compression-Mode greift dort und nutzt
// kontinuierliches Sub-Row-Mapping für smoothes Scrollen (siehe topSpacer).
const MAX_PHYSICAL_HEIGHT = 350000

const scrollTop      = ref(0)
const containerWidth = ref(0)
const viewportHeight = ref(0)

let scrollRafId = 0
let scrollEndTimer = null
let resizeObserver = null

function onScroll() {
  if (!scrollRafId) {
    scrollRafId = requestAnimationFrame(() => {
      scrollRafId = 0
      if (gridEl.value) scrollTop.value = gridEl.value.scrollTop
    })
  }
  // Scroll-End-Hook: 200ms nach letztem Scroll-Event ein finales Re-Sync
  // anstoßen. Hintergrund: bei extrem schnellem Touch-Flick auf Mobile
  // können Watch-Firings durch das rAF-Throttling Items überspringen, die
  // beim Stop noch im sichtbaren Bereich liegen aber deren Load durch ein
  // zwischenzeitliches Unmount gecancelt wurde. Der Watch feuert dann nicht
  // erneut (Range stabil), die Items bleiben leer. Diese Funktion ersetzt
  // das manuelle "Wachküssen" durch hoch/runter scrollen.
  if (scrollEndTimer) clearTimeout(scrollEndTimer)
  scrollEndTimer = setTimeout(resyncRenderedThumbs, 200)
}

// Items im Render-Range mit Viewport-Priorität enqueuen. Iterations-
// Reihenfolge (Viewport → Buffer-unten → Buffer-oben) liegt als Generator
// in gridLayout.js und ist dort separat unit-getestet. Wir übersetzen
// jedes [index, priority]-Pair in unshift (priority=true) bzw. push
// (priority=false) via enqueueThumb. Bereits geladene oder gerade ladende
// Items werden übersprungen; cached Items kriegen ihre URL direkt
// zugewiesen ohne Queue-Roundtrip.
function enqueueRenderedRange() {
  if (!virtualEnabled.value || rowStride.value === 0) return
  const cols = columnsCount.value || 1
  const vpTopRow = Math.max(0, Math.floor(logicalScrollTop.value / rowStride.value))
  const vpRowCount = Math.ceil(viewportHeight.value / rowStride.value)
  const vpStart = Math.max(renderStartIdx.value, vpTopRow * cols)
  const vpEnd   = Math.min(renderEndIdx.value, (vpTopRow + vpRowCount) * cols)

  for (const [i, priority] of iterateViewportFirst(renderStartIdx.value, renderEndIdx.value, vpStart, vpEnd)) {
    const img = props.images[i]
    if (!img || img.thumbLoaded || img.thumbLoading) continue
    if (thumbCache.value[img.id]) {
      img.thumbUrl    = thumbCache.value[img.id]
      img.thumbLoaded = true
      continue
    }
    enqueueThumb(img, priority)
  }
}

function resyncRenderedThumbs() {
  scrollEndTimer = null
  if (!virtualEnabled.value) return
  pruneCancelledLoads()
  enqueueRenderedRange()
  drainQueue()
}

// Spaltenanzahl: bei expliziter gridColumns-Prop direkt; sonst aus computed
// gridTemplateColumns ableiten (zählt die Anzahl der Track-Definitionen).
const columnsCount = computed(() => {
  if (props.gridColumns !== 'auto') {
    return parseInt(props.gridColumns, 10) || 1
  }
  if (containerWidth.value === 0) return 0  // noch nicht gemessen
  // Replikation der CSS-Logik: minmax(min(THUMB_SIZE, 50vw-16px), 1fr)
  const minTile = Math.min(THUMB_SIZE, (window.innerWidth || 1024) / 2 - 16)
  const usable  = containerWidth.value - 16  // 8px padding × 2
  // CSS Grid auto-fill: floor((usable + gap) / (minTile + gap))
  return Math.max(1, Math.floor((usable + GRID_GAP) / (minTile + GRID_GAP)))
})

const tileWidth = computed(() => {
  if (columnsCount.value === 0 || containerWidth.value === 0) return 0
  const usable = containerWidth.value - 16
  return (usable - (columnsCount.value - 1) * GRID_GAP) / columnsCount.value
})

const rowHeight = computed(() => {
  if (tileWidth.value === 0) return 0
  return tileWidth.value * TILE_ASPECT + INFO_BAR_HEIGHT
})

const rowStride = computed(() => rowHeight.value + GRID_GAP)

const totalRows = computed(() => {
  if (columnsCount.value === 0) return 0
  return Math.ceil(props.images.length / columnsCount.value)
})

// Virtualisierung greift nur, wenn Layout gemessen werden konnte.
// Sonst (jsdom-Tests, initial vor Mount): alles rendern, Fallback-Verhalten.
const virtualEnabled = computed(() => rowStride.value > 0 && viewportHeight.value > 0)

// Logical-Scroll-Mapping: bei Listen, die rechnerisch über MAX_PHYSICAL_HEIGHT
// kämen, wird der physische Scrollbereich gekappt und auf den logischen
// Bereich gemappt. compressionRatio=1 bedeutet kein Mapping (kleine Listen).
// Pure Math liegt in src/utils/gridLayout.js für Unit-Tests.
const fullLogicalHeight = computed(() => totalRows.value * rowStride.value)
const compressionRatio  = computed(() =>
  computeCompressionRatio(fullLogicalHeight.value, MAX_PHYSICAL_HEIGHT)
)
const physicalScrollHeight = computed(() => fullLogicalHeight.value / compressionRatio.value)
const logicalScrollTop = computed(() => scrollTop.value * compressionRatio.value)

const visibleStartRow = computed(() => {
  if (!virtualEnabled.value) return 0
  return Math.max(0, Math.floor(logicalScrollTop.value / rowStride.value) - VIRTUAL_BUFFER_ROWS)
})

const visibleEndRow = computed(() => {
  if (!virtualEnabled.value) return totalRows.value
  // Items haben physische volle Größe — der sichtbare Bereich umfasst
  // viewportHeight/rowStride physische Reihen, unabhängig vom Mapping.
  return Math.min(
    totalRows.value,
    Math.ceil((logicalScrollTop.value + viewportHeight.value) / rowStride.value) + VIRTUAL_BUFFER_ROWS,
  )
})

const renderStartIdx = computed(() => visibleStartRow.value * columnsCount.value)
const renderEndIdx   = computed(() => Math.min(props.images.length, visibleEndRow.value * columnsCount.value))

const renderedImages = computed(() => {
  if (!virtualEnabled.value) return props.images
  return props.images.slice(renderStartIdx.value, renderEndIdx.value)
})

// Spacer-Höhen: bei kleinen Listen (compressionRatio=1) klassisch — N Reihen
// × rowHeight + (N-1) × GRID_GAP, damit Items exakt an Reihen-Grenzen sitzen.
// Bei Compression: kontinuierliches Sub-Row-Mapping — siehe topSpacer-Formel
// in gridLayout.js (dort auch Unit-Tests für die Math).
function spacerHeightForRows(n) {
  return _spacerHeightForRows(n, rowHeight.value, GRID_GAP)
}

const topSpacerHeight = computed(() => computeTopSpacerHeight({
  virtualEnabled:    virtualEnabled.value,
  compressionRatio:  compressionRatio.value,
  visibleStartRow:   visibleStartRow.value,
  rowHeight:         rowHeight.value,
  rowStride:         rowStride.value,
  gap:               GRID_GAP,
  scrollTop:         scrollTop.value,
  logicalScrollTop:  logicalScrollTop.value,
  bufferRows:        VIRTUAL_BUFFER_ROWS,
}))

const bottomSpacerHeight = computed(() => {
  if (!virtualEnabled.value) return 0
  if (compressionRatio.value === 1) {
    return spacerHeightForRows(totalRows.value - visibleEndRow.value)
  }
  const itemsBlockHeight = (visibleEndRow.value - visibleStartRow.value) * rowStride.value
  return Math.max(0, physicalScrollHeight.value - topSpacerHeight.value - itemsBlockHeight)
})

// ─── Thumbnail-Loading: IntersectionObserver + Concurrency-Queue ─────────────

const THUMB_CONCURRENCY = 5
// Vorlauf-Margin für Thumbnail-Preload: Items innerhalb dieser Distanz zum
// Viewport (oben/unten) starten ihren Load, bevor sie sichtbar werden. Wert
// gemeinsam genutzt von IO rootMargin und observeAllItems-Safety-Net, damit
// beide Pfade konsistent dieselbe Schwelle anwenden.
const THUMB_PRELOAD_MARGIN_PX = 400
let thumbObserver = null
// Set der aktuell ladenden Image-Objekte. Wir tracken die echte In-Flight-
// Menge statt eines Zählers, weil Virtualisierung Items mid-load unmounten
// kann — der Browser cancelt dann die Image-Requests, aber @load/@error
// feuert nicht mehr. Ein reiner Counter würde driften und den Concurrency-
// Pool dauerhaft blockieren. Per Set können wir gezielt die abgehängten
// Items rauswerfen (siehe pruneCancelledLoads).
const loadingItems = new Set()
const loadQueue   = []   // kein ref – wir brauchen keine Reaktivität

function setupThumbObserver() {
  thumbObserver = new IntersectionObserver((entries) => {
    // Reverse-Iteration: Ein Batch kommt in DOM-Reihenfolge (top→bottom).
    // enqueueThumb mit priority=true unshift't vorne in die Queue. Würden wir
    // vorwärts iterieren, wäre das Ergebnis bottom→top. Indem wir rückwärts
    // durchgehen, landet innerhalb einer Batch DOM-Reihenfolge korrekt in der
    // Queue (top wird zuletzt unshift'd und liegt ganz vorne).
    // Zwischen verschiedenen Batches gewinnt die neueste Batch (z. B. nach
    // schnellem Scrollen) vor älteren, noch wartenden Items.
    for (let i = entries.length - 1; i >= 0; i--) {
      const entry = entries[i]
      if (!entry.isIntersecting) continue
      const idx   = parseInt(entry.target.dataset.index, 10)
      const image = props.images[idx]
      if (!image || image.thumbLoaded || image.thumbLoading) { thumbObserver.unobserve(entry.target); continue }
      if (thumbCache.value[image.id]) {
        image.thumbUrl    = thumbCache.value[image.id]
        image.thumbLoaded = true
      } else {
        const inViewport = entry.boundingClientRect.bottom > 0
          && entry.boundingClientRect.top < (window.innerHeight || document.documentElement.clientHeight)
        enqueueThumb(image, inViewport)
      }
      thumbObserver.unobserve(entry.target)
    }
  }, { rootMargin: `${THUMB_PRELOAD_MARGIN_PX}px 0px` })
}

function observeAllItems() {
  nextTick(() => {
    if (!gridEl.value || !thumbObserver) return
    const items = gridEl.value.querySelectorAll('.sr-grid__item[data-index]')
    items.forEach(el => {
      const idx = parseInt(el.dataset.index, 10)
      const img = props.images[idx]
      if (!img || img.thumbLoaded) return
      thumbObserver.observe(el)
      // Cache-Hit: thumb direkt setzen, kein Queue-Roundtrip nötig.
      // Echtes Enqueueing läuft zentral über enqueueRenderedRange() im
      // renderStartIdx/EndIdx-Watch — sonst würde dieses Safety-Net mit
      // seiner DOM-Order-forEach die Viewport-Priorität wieder zerstören
      // (Items landen vor enqueueRenderedRange in der Queue, dessen
      // Priority-Argument greift dann nicht mehr).
      if (thumbCache.value[img.id]) {
        img.thumbUrl    = thumbCache.value[img.id]
        img.thumbLoaded = true
        thumbObserver.unobserve(el)
      }
    })
  })
}

function enqueueThumb(image, priority = false) {
  if (image.thumbLoading || loadQueue.some(i => i.id === image.id)) return
  // Priority = aktuell im Viewport → vorne in die Queue. Nach schnellem Scrollen
  // überholt der neue Viewport automatisch ältere, noch wartende Items.
  if (priority) loadQueue.unshift(image)
  else loadQueue.push(image)
  drainQueue()
}

function drainQueue() {
  while (loadingItems.size < THUMB_CONCURRENCY && loadQueue.length > 0) {
    const image = loadQueue.shift()
    if (image.thumbLoaded) continue
    loadThumb(image)
  }
}

function loadThumb(image) {
  image.thumbLoading = true
  loadingItems.add(image)
  const sz  = THUMB_SIZE
  // Logged-in: /core/preview nutzt NCs nativen Preview-Cache (schneller als App-Endpunkt,
  // der bei jedem Request erneut durch PreviewManager läuft). Guest-Modus setzt eigene URL
  // via thumbnailUrlFn, weil /core/preview eine NC-Session braucht.
  const url = props.thumbnailUrlFn
    ? props.thumbnailUrlFn(image.id, sz)
    : generateUrl(`/core/preview?fileId=${image.id}&x=${sz}&y=${sz}&a=1&forceIcon=0&mode=cover`)
  // KEIN separater `new Image()`-Preload mehr. Vor 1.2.11 wurde die URL erst via
  // versteckter Image-Instanz vorgeladen, dann `image.thumbUrl` gesetzt → das DOM-<img>
  // hat dadurch einen ZWEITEN Request abgesetzt (Cache-Hit, aber trotzdem eigener Slot
  // im Browser-Pipelining). Bei 600er Ordnern führte das zu 1200 queued Requests und
  // verstopfte HTTP/2-Streams + PHP-FPM-Worker. Jetzt: URL direkt setzen, DOM-<img>
  // lädt nativ, `@load`/`@error` steuern die Queue.
  image.thumbUrl = url
}

function onImgLoad(image) {
  // Initialer Render mit BLANK_PIXEL feuert auch `load` — ignorieren.
  if (!image.thumbLoading) return
  thumbCache.value[image.id] = image.thumbUrl
  image.thumbLoaded  = true
  image.thumbLoading = false
  loadingItems.delete(image)
  drainQueue()
}

// Backoff zwischen Retry-Versuchen. Lang gewählt, weil NCs Lazy-Generation für
// große JPEGs/RAWs 10–30s dauern kann; kurze Backoffs (3/6/9s) ließen unsere
// Retries mitten in den noch laufenden Server-Job feuern und triggerten Cascade-
// Errors. Mit 15s/60s ist der Server beim Retry meist fertig — Cache-Hit, instant.
const RETRY_BACKOFF_MS = [15000, 60000]

function onImgError(image) {
  if (!image.thumbLoading) return
  image.thumbLoading = false
  loadingItems.delete(image)
  image.thumbRetries = (image.thumbRetries ?? 0) + 1
  if (image.thumbRetries < 3) {
    // src zurücksetzen, damit beim Retry das <img> wirklich erneut lädt.
    image.thumbUrl = ''
    setTimeout(() => enqueueThumb(image), RETRY_BACKOFF_MS[image.thumbRetries - 1])
  } else {
    image.thumbError = true
  }
  drainQueue()
}

// Items, deren Loading durch ein Virtualisierungs-Unmount abgebrochen wurde,
// aus dem aktiven Set entfernen. Browser cancelt Image-Requests beim DOM-
// Detach ohne @load/@error zu feuern; ohne diesen Cleanup driftet der
// Concurrency-Pool und neue Items kommen nicht mehr durch die Queue.
function pruneCancelledLoads() {
  if (!virtualEnabled.value || loadingItems.size === 0) return
  const renderedIds = new Set()
  for (let i = renderStartIdx.value; i < renderEndIdx.value; i++) {
    const img = props.images[i]
    if (img) renderedIds.add(img.id)
  }
  for (const img of Array.from(loadingItems)) {
    if (!renderedIds.has(img.id)) {
      // Item nicht mehr im DOM → Browser hat den Request gecancelt. Flags
      // resetten, damit er beim nächsten Auftauchen neu enqueued werden kann.
      img.thumbLoading = false
      loadingItems.delete(img)
    }
  }
}

// Bilder-Array wechselt (Filter / Ordner / Shape-Change bei Upload): Observer + Queue neu aufsetzen.
// Visibility-Reload und Background-Sync triggern das normalerweise NICHT, weil Gallery.vue
// den Fast-Path (sameShape) nutzt und das Array in-place merged.
// WICHTIG: loadingItems muss ebenfalls geleert werden — die alten Image-Objekte sind
// nicht mehr Teil des neuen Arrays und ihre Loads sind effektiv verloren.
watch(() => props.images, () => {
  loadQueue.length = 0
  loadingItems.clear()
  thumbObserver?.disconnect()
  observeAllItems()
  // Falls renderStartIdx/EndIdx beim Image-Wechsel numerisch identisch bleiben
  // (z.B. visibleStart=0 und neuer Range hat zufällig dieselbe Endgrenze),
  // feuert der renderStartIdx/EndIdx-Watch nicht — dann würde die Queue leer
  // bleiben, weil observeAllItems nicht mehr selbst enqueued. Hier explizit
  // anstoßen.
  nextTick(() => {
    enqueueRenderedRange()
    drainQueue()
  })
})

// ─── Auswahl ──────────────────────────────────────────────────────────────────

function isSelected(id) {
  return selectedIds.value.has(id)
}

function onItemClick(event, image, index) {
  if (event.shiftKey && lastClickIdx.value >= 0) {
    // Shift+Klick → Bereich markieren
    const from = Math.min(lastClickIdx.value, index)
    const to   = Math.max(lastClickIdx.value, index)
    for (let i = from; i <= to; i++) {
      selectedIds.value.add(props.images[i].id)
    }
  } else if (event.ctrlKey || event.metaKey) {
    // Strg/Cmd+Klick → einzelnes Bild togglen
    // Beim ersten Cmd+Klick: fokussiertes Anker-Bild automatisch mitselektieren
    if (selectedIds.value.size === 0 && lastClickIdx.value >= 0) {
      const anchor = props.images[lastClickIdx.value]
      if (anchor) selectedIds.value.add(anchor.id)
    }
    if (selectedIds.value.has(image.id)) {
      selectedIds.value.delete(image.id)
    } else {
      selectedIds.value.add(image.id)
    }
  } else {
    // Normaler Klick → Auswahl aufheben wenn vorhanden, sonst nur Focus setzen
    if (selectedIds.value.size > 0) {
      selectedIds.value.clear()
      focusedIndex.value = index
      lastClickIdx.value = index
      emit('selection-change', new Set())
      return
    }
    focusedIndex.value = index
    lastClickIdx.value = index
    return  // kein selection-change bei reinem Focus
  }

  lastClickIdx.value = index
  emit('selection-change', new Set(selectedIds.value))
}

function clearSelection() {
  selectedIds.value.clear()
  emit('selection-change', new Set())
}

function selectAll() {
  props.images.forEach(img => selectedIds.value.add(img.id))
  emit('selection-change', new Set(selectedIds.value))
}

// ─── Tastatur ────────────────────────────────────────────────────────────────

function onGlobalKeydown(e) {
  const idx = focusedIndex.value

  switch (e.key) {
    // Pos1 / Ende
    case 'Home':
      e.preventDefault()
      if (props.images.length > 0) {
        focusedIndex.value = 0
        lastClickIdx.value  = 0
        scrollItemIntoView(0)
      }
      break
    case 'End':
      e.preventDefault()
      if (props.images.length > 0) {
        const last = props.images.length - 1
        focusedIndex.value = last
        lastClickIdx.value  = last
        scrollItemIntoView(last)
      }
      break

    // Navigation (Shift+Arrow = Mehrfachauswahl)
    case 'ArrowRight':
      e.preventDefault()
      moveFocus(1, e.shiftKey)
      break
    case 'ArrowLeft':
      e.preventDefault()
      moveFocus(-1, e.shiftKey)
      break
    case 'ArrowDown':
      e.preventDefault()
      moveFocus(columnsEstimate(), e.shiftKey)
      break
    case 'ArrowUp':
      e.preventDefault()
      moveFocus(-columnsEstimate(), e.shiftKey)
      break

    // Enter / Doppelklick → Lupenansicht
    case 'Enter':
      if (idx >= 0 && idx < props.images.length) {
        emit('open-loupe', props.images[idx], idx)
      }
      break

    // Sternebewertung (0–5)
    case '0': case '1': case '2':
    case '3': case '4': case '5':
      if (props.enableRatingUi && !e.shiftKey) {
        e.preventDefault()
        if (selectedIds.value.size > 0) {
          emit('batch-rate', parseInt(e.key), undefined, undefined)
        } else if (idx >= 0) {
          const img = props.images[idx]
          if (img) emit('rate', img, parseInt(e.key), undefined)
        }
      }
      break

    // Farben (6=Rot, 7=Gelb, 8=Grün, 9=Blau, V=Lila) — Toggle
    case '6': case '7': case '8': case '9': {
      if (!props.enableColorUi) break
      e.preventDefault()
      const colorMap = { '6': 'Red', '7': 'Yellow', '8': 'Green', '9': 'Blue' }
      const c = colorMap[e.key]
      if (selectedIds.value.size > 0) {
        const sel = props.images.filter(img => selectedIds.value.has(img.id))
        const allHave = sel.length > 0 && sel.every(img => img.color === c)
        emit('batch-rate', undefined, allHave ? null : c, undefined)
      } else if (idx >= 0) {
        const img = props.images[idx]
        if (img) emit('rate', img, undefined, img.color === c ? null : c)
      }
      break
    }
    case 'v': case 'V':
      if (props.enableColorUi && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        if (selectedIds.value.size > 0) {
          const sel = props.images.filter(img => selectedIds.value.has(img.id))
          const allHave = sel.length > 0 && sel.every(img => img.color === 'Purple')
          emit('batch-rate', undefined, allHave ? null : 'Purple', undefined)
        } else if (idx >= 0) {
          const img = props.images[idx]
          if (img) emit('rate', img, undefined, img.color === 'Purple' ? null : 'Purple')
        }
      }
      break

    // P = Pick, X = Reject
    case 'p': case 'P':
      if (props.enablePickUi) {
        e.preventDefault()
        if (selectedIds.value.size > 0) {
          emit('batch-rate', undefined, undefined, 'pick')
        } else if (idx >= 0) {
          const img = props.images[idx]
          if (img) emit('rate', img, undefined, undefined, img.pick === 'pick' ? 'none' : 'pick')
        }
      }
      break
    case 'x': case 'X':
      if (props.enablePickUi) {
        e.preventDefault()
        if (selectedIds.value.size > 0) {
          emit('batch-rate', undefined, undefined, 'reject')
        } else if (idx >= 0) {
          const img = props.images[idx]
          if (img) emit('rate', img, undefined, undefined, img.pick === 'reject' ? 'none' : 'reject')
        }
      }
      break

    // Strg+A = alle auswählen
    case 'a':
    case 'A':
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault()
        selectAll()
      }
      break

    // Escape = Auswahl aufheben
    case 'Escape':
      clearSelection()
      break
  }
}

function moveFocus(delta, extend = false) {
  if (props.images.length === 0) return

  // Kein Focus gesetzt → starte beim zuletzt bekannten Index oder 0
  if (focusedIndex.value < 0) {
    focusedIndex.value = (props.currentIndex >= 0 && props.currentIndex < props.images.length)
      ? props.currentIndex : 0
    lastClickIdx.value = focusedIndex.value
  }

  const newIdx = Math.max(0, Math.min(props.images.length - 1, focusedIndex.value + delta))
  if (newIdx === focusedIndex.value) return

  focusedIndex.value = newIdx
  scrollItemIntoView(newIdx)

  if (extend) {
    // Shift+Arrow: Bereich vom Anker (lastClickIdx) bis newIdx selektieren
    const anchor = lastClickIdx.value >= 0 ? lastClickIdx.value : newIdx
    selectedIds.value.clear()
    const from = Math.min(anchor, newIdx)
    const to   = Math.max(anchor, newIdx)
    for (let i = from; i <= to; i++) {
      selectedIds.value.add(props.images[i].id)
    }
    emit('selection-change', new Set(selectedIds.value))
  } else {
    lastClickIdx.value = newIdx
  }
}

function columnsEstimate() {
  // Auf Mobile greift in columnsCount die min(THUMB_SIZE, 50vw-16)-Logik für
  // garantierte 2 Spalten — eine eigene Schätzung über offsetWidth/THUMB_SIZE
  // würde 1 liefern und ↑/↓ falsch um eine Spalte statt um eine Reihe bewegen.
  return columnsCount.value || 4
}

function scrollItemIntoView(index, behavior = 'smooth') {
  nextTick(() => {
    const el = gridEl.value?.querySelector(`[data-index="${index}"]`)
    if (el) {
      el.scrollIntoView?.({ block: 'nearest', behavior })
      return
    }
    // Item nicht gerendert (außerhalb des Virtual-Range): direkt zu seiner
    // berechneten Reihe scrollen. Nach dem Scroll rerendert sich der visible
    // Range automatisch. Bei compressionRatio>1 ist die physische scroll-
    // Position kleiner als die logische — durch ratio teilen.
    if (!virtualEnabled.value || !gridEl.value) return
    const targetRow = Math.floor(index / columnsCount.value)
    const targetTop = targetRow * rowStride.value / compressionRatio.value
    const containerH = gridEl.value.clientHeight
    const currentTop = gridEl.value.scrollTop
    // Nur scrollen, wenn das Item nicht ohnehin im Viewport-Range liegen würde.
    if (targetTop < currentTop || targetTop + rowHeight.value > currentTop + containerH) {
      gridEl.value.scrollTo({
        top: Math.max(0, targetTop - containerH / 2 + rowHeight.value / 2),
        behavior,
      })
    }
  })
}

// ─── Sync mit Lupenansicht ────────────────────────────────────────────────────

let firstScrollSync = true
watch(() => props.currentIndex, idx => {
  if (idx >= 0) {
    focusedIndex.value = idx
    // Erster Sync (Mount / Loupe→Grid): sofort, nicht animiert. Sonst smooth.
    scrollItemIntoView(idx, firstScrollSync ? 'auto' : 'smooth')
    firstScrollSync = false
  }
}, { immediate: true })   // immediate: focusedIndex beim Mount sofort setzen

// Keyboard-Navigation und Click ändern focusedIndex — Parent über das Bild
// informieren, damit der dynamische Breadcrumb-Tail mitläuft. Hover wird
// separat im Template via @mouseover gefeuert.
watch(focusedIndex, idx => {
  if (idx >= 0 && idx < props.images.length) {
    emit('focus-preview', props.images[idx])
  }
})

// Re-Aktivierung nach Loupe-Schließen: aktuell fokussiertes Tile in den
// Viewport scrollen UND Keyboard-Fokus aufs Grid legen. Ohne den focus-Call
// wandert der DOM-Fokus beim Loupe-Unmount zur document.body — Cursor-
// Tasten scrollen dann den Body statt durchs Grid zu navigieren.
//
// Außerdem: Errored Thumbs im Render-Range zurücksetzen, damit
// enqueueRenderedRange() sie wieder als unloaded sieht und mit Viewport-
// Priorität re-queued. Loupe-Visit hat NC gezwungen, Previews zu generieren
// (mindestens für das fokussierte Bild + ←/→-Nachbarn). Diese Items haben
// jetzt einen Server-side Cache und liefern beim Retry instant. Items die
// NC immer noch nicht hat, scheitern halt wieder.
watch(() => props.active, isActive => {
  if (!isActive) return
  const idx = focusedIndex.value >= 0 ? focusedIndex.value : props.currentIndex
  if (idx >= 0) {
    scrollItemIntoView(idx, 'auto')
  }
  nextTick(() => {
    gridEl.value?.focus({ preventScroll: true })
    const start = virtualEnabled.value ? renderStartIdx.value : 0
    const end   = virtualEnabled.value ? renderEndIdx.value   : props.images.length
    for (let i = start; i < end; i++) {
      const img = props.images[i]
      if (!img || !img.thumbError || img.thumbLoading) continue
      img.thumbError   = false
      img.thumbRetries = 0
      img.thumbUrl     = ''
    }
    enqueueRenderedRange()
    drainQueue()
  })
})

// ─── Autofocus beim Mount ─────────────────────────────────────────────────────

function measureContainer() {
  if (!gridEl.value) return
  const w = gridEl.value.clientWidth
  const h = gridEl.value.clientHeight
  // Wenn das Grid via v-show ausgeblendet ist (display:none), liefert
  // clientWidth/Height = 0. Ohne diesen Guard würde virtualEnabled false,
  // renderedImages auf das komplette Array zurückfallen und Vue tausende
  // versteckter Items mounten — bei 25k Bildern hat das 6s Lag beim Loupe-
  // Öffnen und 4s beim Schließen verursacht. Letzten validen Wert behalten.
  if (w === 0 && h === 0) return
  containerWidth.value = w
  viewportHeight.value = h
  syncMaxHeight()
}

// Dynamische max-height: vom Container-Top bis zum Window-Boden. Statisches
// calc(100vh - 160px) im CSS verschätzte sich bei vollem NC-Header oder
// ausgeklappter Filterbar — der Container reichte unter den Viewport, der
// Scrollbar-Boden inkl. Down-Chevron wurde geclippt, der User-Drag erreichte
// nicht 100%. Diff-Check verhindert eine ResizeObserver→Style→Resize-Schleife.
function syncMaxHeight() {
  if (!gridEl.value) return
  const top = gridEl.value.getBoundingClientRect().top
  const safety = 8
  const newMax = Math.max(0, window.innerHeight - top - safety)
  const currentMax = parseFloat(gridEl.value.style.maxHeight) || 0
  if (Math.abs(newMax - currentMax) > 1) {
    gridEl.value.style.maxHeight = newMax + 'px'
  }
}

onMounted(() => {
  setupThumbObserver()
  measureContainer()
  // Erste Messung: Scroll-Position + Container-Maße. ResizeObserver übernimmt
  // danach automatisch alle Layout-Änderungen (Window-Resize, NC-Sidebar
  // toggle, Filter-Bar wachsend, etc.).
  if (gridEl.value) {
    gridEl.value.addEventListener('scroll', onScroll, { passive: true })
    resizeObserver = new ResizeObserver(measureContainer)
    resizeObserver.observe(gridEl.value)
    // Auch den Wrap-Parent beobachten: bei SPA-Folder-Wechsel ändert sich der
    // Header darüber (Subfolder-Pills tauchen auf/verschwinden), damit auch der
    // verfügbare Wrap-Bereich. Das gridEl selbst behält seine Größe → der
    // ResizeObserver darauf feuert nicht. Ohne Parent-Observer bleibt die alte
    // maxHeight stehen → Grid wirkt zu kurz/zu lang bis zum Reload.
    if (gridEl.value.parentElement) {
      resizeObserver.observe(gridEl.value.parentElement)
    }
  }
  observeAllItems()
  window.addEventListener('resize', syncMaxHeight)
  nextTick(() => gridEl.value?.focus({ preventScroll: true }))
})

onUnmounted(() => {
  thumbObserver?.disconnect()
  resizeObserver?.disconnect()
  if (scrollRafId) cancelAnimationFrame(scrollRafId)
  if (scrollEndTimer) clearTimeout(scrollEndTimer)
  gridEl.value?.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', syncMaxHeight)
})

// Wenn der Virtual-Range neue Items in den DOM bringt: Thumbnail-Loading
// direkt anstoßen, statt auf den IntersectionObserver zu warten.
//
// Hintergrund: Bei schnellem Mobile-Scroll (Touch-Flick mit Momentum) feuert
// der IO unzuverlässig — wir haben Fälle gesehen, wo Tiles nach einem
// Sprung-Scroll nie luden, bis der Nutzer den Viewport durch erneutes
// hoch/runter scrollen "anstößt". Bei aktiver Virtualisierung wissen wir
// deterministisch, welche Items im sichtbaren Bereich (plus Buffer) sind —
// enqueueRenderedRange() füllt die Queue mit Viewport-Items zuerst, Buffer
// danach (siehe dortigen Kommentar), keine IO-Wartezeit nötig.
//
// observeAllItems() läuft hier zusätzlich, hat aber eine engere Rolle als
// früher: es registriert nur den IO als Backup-Pfad (für Range-Wechsel ohne
// Watch-Feuer, z.B. nach Reactivity-Tick-Coalescing) und setzt cached Items
// direkt aus thumbCache, ohne Queue-Roundtrip. Das eigentliche Enqueueing
// passiert ausschließlich in enqueueRenderedRange().
watch([renderStartIdx, renderEndIdx], () => {
  if (!virtualEnabled.value) return
  pruneCancelledLoads()  // Slot-Freigabe für aus dem DOM verschwundene Items
  // Queue verwerfen: Items dort waren entweder priority-Loads aus früheren
  // Range-Ticks, die durch einen Pool-Stau (NC liefert lang) nie drankamen,
  // oder out-of-Range-Items ohne <img> im DOM. Die nicht-mehr-sichtbaren
  // brauchen wir nicht; die noch sichtbaren werden gleich frisch ge-enqueued.
  // Ohne dieses Reset blockiert der loadQueue.some()-Guard in enqueueThumb
  // spätere Re-Enqueues für Items, die hinten in der Queue stecken — sie
  // gelten als "schon queued", werden aber nie geladen → "ganze Reihen fehlen".
  loadQueue.length = 0
  observeAllItems()
  nextTick(() => {
    enqueueRenderedRange()
    drainQueue()
  })
}, { immediate: true })

// ─── Expose für SelectionBar ─────────────────────────────────────────────────

defineExpose({ clearSelection, selectAll, selectedIds })
</script>

<style scoped>
.sr-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); /* Fallback; wird via :style überschrieben */
  gap: 6px;
  padding: 8px;
  padding-bottom: max(8px, env(safe-area-inset-bottom, 0px));
  outline: none;
  align-content: start;
  /* max-height unabhängig von der Elternkette – NC-Header 50px + Breadcrumb ~36px + Filterbar ~62px + Puffer */
  max-height: calc(100vh - 160px);
  overflow-y: auto;
  /* Scroll-Anchoring deaktivieren: Defense-in-Depth gegen Browser-Versuche, scrollTop
     zu korrigieren, wenn topSpacer / Items im Render-Range neu erscheinen. Im
     Compression-Mode wandert topSpacer kontinuierlich mit dem Scroll (siehe topSpacer-
     Formel im JS); manche Mobile-Browser interpretieren auch gewollte Sub-Pixel-
     Shifts als Layout-Bewegung und schnappen zurück. Auf Spacer + Items als direkte
     Kinder gesetzt, damit auch tiefer liegende Elemente nicht als Anker gewählt werden. */
  overflow-anchor: none;
}

.sr-grid > * {
  overflow-anchor: none;
}

/* Android Nav-Bar (|||  O  <): sicherstellen dass letzte Zeile nicht verdeckt wird */
@media (max-width: 768px) {
  .sr-grid {
    padding-bottom: max(72px, env(safe-area-inset-bottom, 72px));
  }
}

/* ── Virtualisierungs-Spacer ─────────────────────────────────────────────── */
.sr-grid__spacer {
  grid-column: 1 / -1;
  pointer-events: none;
}

/* ── Item ─────────────────────────────────────────────────────────────────── */
.sr-grid__item {
  position: relative;
  background: #1e1e2e;
  border-radius: 4px;
  overflow: hidden;
  cursor: pointer;
  border: 2px solid transparent;
  transition: border-color 100ms ease, box-shadow 100ms ease;
  user-select: none;
}

@media (pointer: fine) {
  .sr-grid__item:hover {
    border-color: rgba(255,255,255,0.15);
  }
}

.sr-grid__item--selected {
  border-color: #4a90d9 !important;
  box-shadow: 0 0 0 1px #4a90d9;
}

.sr-grid__item--focused {
  box-shadow: 0 0 0 2px #4a90d9aa;
}

.sr-grid__item--reject {
  opacity: 0.45;
}

/* ── Thumbnail ────────────────────────────────────────────────────────────── */
.sr-grid__thumb-wrap {
  position: relative;
  width: 100%;
  padding-top: 75%; /* 4:3 Aspektverhältnis */
  background: #111;
  overflow: hidden;
}

.sr-grid__thumb {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  /* Kein opacity-Toggle — <img> bleibt permanent opacity:1. Paint-Suppression-Quelle
     war das Fade von 0→1: Chromium recycelt den compositor layer des <img>, neuer
     frame wird manchmal nicht geflushed (pitchblack Items bis Window-Redraw).
     Stattdessen: solange src=BLANK_PIXEL ist, schimmert der darunterliegende
     Placeholder durch das transparente 1×1. Sobald ein opakes JPEG lädt, überdeckt
     es den Placeholder ohne Opacity-Gefrickel. */
}

@media (pointer: fine) {
  .sr-grid__item:hover .sr-grid__thumb {
    transform: scale(1.02);
    transition: transform 200ms ease;
  }
}

.sr-grid__thumb-placeholder {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #1e1e2e 25%, #2a2a3e 50%, #1e1e2e 75%);
  background-size: 400% 400%;
  animation: shimmer 1.5s infinite;
  pointer-events: none;
}

/* Loaded: Animation aus (CPU sparen). Opacity bleibt 1 — das opake <img> liegt
   drüber und verdeckt den Placeholder komplett, kein Fade nötig, kein DOM-Umbau. */
.sr-grid__thumb-placeholder--hidden {
  animation: none;
}

@keyframes shimmer {
  0%   { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}
.sr-grid__thumb-placeholder--error {
  animation: none;
  background: #1a1a2a;
}

/* ── Overlays ─────────────────────────────────────────────────────────────── */
.sr-grid__pick-badge {
  position: absolute;
  top: 4px;
  left: 4px;
  pointer-events: none;
  z-index: 1;
}

.sr-grid__reject-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.4);
  color: #e94560;
  font-size: 3rem;
  font-weight: bold;
  pointer-events: none;
}

.sr-grid__select-badge {
  position: absolute;
  top: 6px;
  left: 6px;
  width: 22px;
  height: 22px;
  background: #4a90d9;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  z-index: 2;
}

.sr-grid__select-badge svg {
  width: 14px;
  height: 14px;
}


/* ── Info-Leiste ─────────────────────────────────────────────────────────── */
.sr-grid__info {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 6px;
  background: #16162a;
  min-height: 26px;
}

.sr-grid__info-stars {
  flex-shrink: 0;
}

.sr-grid__info-name {
  flex: 1;
  font-size: 11px;
  color: #aaa;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.sr-grid__info-color {
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  display: inline-block;
}

.sr-grid__info-color--red    { background: #e05252; }
.sr-grid__info-color--yellow { background: #e0c252; }
.sr-grid__info-color--green  { background: #52a852; }
.sr-grid__info-color--blue   { background: #5277e0; }
.sr-grid__info-color--purple { background: #9b52e0; }

/* ── Skeleton ─────────────────────────────────────────────────────────────── */
.sr-grid__item--skeleton {
  pointer-events: none;
  border: none;
}

.sr-grid__skeleton-img {
  width: 100%;
  padding-top: 75%;
  background: linear-gradient(135deg, #1e1e2e 25%, #2a2a3e 50%, #1e1e2e 75%);
  background-size: 400% 400%;
  animation: shimmer 1.5s infinite;
}

.sr-grid__skeleton-bar {
  height: 26px;
  background: #16162a;
  margin-top: 2px;
  border-radius: 0 0 4px 4px;
}

/* ── Leer-Zustand ─────────────────────────────────────────────────────────── */
.sr-grid__empty-cta {
  margin-top: 4px;
  padding: 6px 16px;
  border-radius: 20px;
  border: 1px dashed #7a3050;
  background: transparent;
  color: #c06070;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
}
.sr-grid__empty-cta:hover {
  background: #3a2030;
  border-color: #9a4060;
  color: #e0a0b0;
}

.sr-grid__empty {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  color: #555;
  gap: 12px;
}

.sr-grid__empty svg {
  width: 64px;
  height: 64px;
}

.sr-grid__empty p {
  margin: 0;
  font-size: 14px;
}

</style>
