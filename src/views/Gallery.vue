<template>
  <div class="sr-app" :class="{ 'sr-app--loupe': mode === 'loupe' }" @keydown="onAppKeydown">

    <!-- Nav-Zeile: Breadcrumb + Unterordner -->
    <div class="sr-nav-row">
      <div class="sr-breadcrumb">
        <button class="sr-breadcrumb__seg" @click="navigateTo('/')">⌂</button>
        <template v-for="(seg, i) in pathSegments" :key="i">
          <span class="sr-breadcrumb__sep">/</span>
          <button class="sr-breadcrumb__seg" @click="navigateTo(pathUpTo(i))">{{ seg }}</button>
        </template>

        <!-- Dynamischer Tail im Recursive-Modus: Subfolder des aktuell
             gehoverten/fokussierten Tiles. Klick → Recursion verlassen, in
             den jeweiligen Subfolder navigieren. -->
        <template v-for="(seg, i) in hoveredFolderSegments" :key="`hov-${i}`">
          <span class="sr-breadcrumb__sep sr-breadcrumb__sep--dynamic">/</span>
          <button
            class="sr-breadcrumb__seg sr-breadcrumb__seg--dynamic"
            :title="t('starrate', 'In diesen Unterordner wechseln')"
            @click="exitRecursionInto(i)"
          >{{ seg }}</button>
        </template>

        <!-- Mobile-only: Unterordner-Popover am Ende des Pfads -->
        <FolderPopover
          v-if="subFolders.length && mode !== 'loupe'"
          :folders="subFolders"
          @navigate="navigateTo"
        />

        <span v-if="guestMode && guestLabel" class="sr-breadcrumb__guest-label">{{ guestLabel }}</span>

        <!-- Utility-Cluster (Desktop-only via CSS): Modus-Toggle, Help, Version -->
        <div class="sr-breadcrumb__utility">
          <div class="sr-breadcrumb__mode">
            <button
              class="sr-breadcrumb__mode-btn"
              :class="{ 'sr-breadcrumb__mode-btn--active': mode === 'grid' }"
              type="button"
              :title="t('starrate', 'Rasteransicht')"
              @click="toggleMode"
            >
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1" fill="currentColor"/>
                <rect x="14" y="3" width="7" height="7" rx="1" fill="currentColor"/>
                <rect x="3" y="14" width="7" height="7" rx="1" fill="currentColor"/>
                <rect x="14" y="14" width="7" height="7" rx="1" fill="currentColor"/>
              </svg>
            </button>
            <button
              class="sr-breadcrumb__mode-btn"
              :class="{ 'sr-breadcrumb__mode-btn--active': mode === 'loupe' }"
              type="button"
              :title="t('starrate', 'Lupenansicht')"
              @click="toggleMode"
            >
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="2" y="2" width="20" height="20" rx="2" stroke="currentColor" stroke-width="2"/>
                <circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2"/>
              </svg>
            </button>
          </div>

          <!-- Shortcut-Hilfe -->
          <button class="sr-breadcrumb__help" :title="t('starrate', 'Tastaturkürzel')" @click="showShortcuts = true">?</button>

          <span class="sr-breadcrumb__version">
            StarRate v{{ appVersion }}<br/>
            by <a href="https://whitespace.de" target="_blank" rel="noopener noreferrer" class="sr-breadcrumb__version-link">Whitespace</a>
          </span>
        </div>
      </div>

      <!-- Desktop-only: Unterordner-Pills als eigene Zeile -->
      <div v-if="subFolders.length && mode !== 'loupe'" class="sr-folders">
        <button
          v-for="f in subFolders"
          :key="f.path"
          class="sr-folders__item"
          @click="navigateTo(f.path)"
        >
          <span class="sr-folders__icon">📁</span>
          <span class="sr-folders__name">{{ f.name }}</span>
        </button>
      </div>
    </div><!-- /.sr-nav-row -->

    <!-- Filterleiste -->
    <FilterBar
      v-model:filter="activeFilter"
      :total="allImages.length"
      :filtered-count="filteredImages.length"
      :mode="mode"
      :enable-pick-ui="settings.enable_pick_ui"
      :allow-share="!guestMode"
      :allow-export="!guestMode || allowExport"
      :can-export="filteredImages.length > 0"
      :can-download="canDownload"
      :selected-count="selectedIds.size"
      :allow-recursive="recursionAvailable"
      :recursive="recursive"
      :depth="depth"
      @toggle-mode="toggleMode"
      @open-share-list="showShareList = true"
      @open-export-modal="showExportModal = true"
      @download-zip="triggerZipDownload"
      @update:recursive="setRecursive"
      @update:depth="setDepth"
    />

    <!-- Ansichts-Wrapper: nimmt den restlichen Platz, gibt dem Grid eine definite Höhe -->
    <div class="sr-view-wrap">
      <GridView
        v-show="mode === 'grid'"
        ref="gridRef"
        :active="mode === 'grid'"
        :images="filteredImages"
        :loading="loading"
        :has-active-filter="hasActiveFilter"
        :current-index="currentIndex"
        :grid-columns="settings.grid_columns"
        :show-filename="settings.show_filename"
        :show-rating-info="settings.show_rating_overlay"
        :show-color-info="settings.show_color_overlay"
        :enable-pick-ui="settings.enable_pick_ui"
        :thumbnail-url-fn="thumbnailUrlFn"
        @rate="onRate"
        @batch-rate="onBatchRate"
        @open-loupe="openLoupe"
        @selection-change="onSelectionChange"
        @clear-filter="resetFilter"
        @focus-preview="onFocusPreview"
      />

      <!-- Lupenansicht -->
      <LoupeView
        v-if="mode === 'loupe'"
        :images="filteredImages"
        :initial-index="currentIndex"
        :on-refresh-rating="guestMode ? null : refreshImageRating"
        :preview-url-fn="previewUrlFn"
        :enable-pick-ui="settings.enable_pick_ui"
        :allow-comment="settings.comments_enabled || allowComment"
        :comment-api="commentApi"
        :comments-enabled-owner="settings.comments_enabled"
        :slideshow-interval="settings.slideshow_interval ?? 4"
        :guest-mode="guestMode"
        :can-rate="canRate"
        :can-download="canDownload"
        @rate="onRate"
        @close="mode = 'grid'"
        @index-change="currentIndex = $event"
        @download="triggerDownload"
      />
    </div>

    <!-- Stapel-Bewertungsleiste -->
    <SelectionBar
      v-if="selectedIds.size > 0"
      :count="selectedIds.size"
      :active-rating="batchActiveRating"
      :active-color="batchActiveColor"
      :active-pick="batchActivePick"
      :enable-pick-ui="settings.enable_pick_ui"
      @rate="onBatchRate"
      @clear="gridRef?.clearSelection()"
    />

    <!-- Share-Liste -->
    <ShareList
      v-if="!guestMode && showShareList"
      ref="shareListRef"
      :nc-path="currentPath"
      @close="showShareList = false"
      @create="openShareModalForCreate"
      @edit="openShareModalForEdit"
    />

    <!-- Share anlegen oder bearbeiten — gleicher Modal, Modus per editingShare -->
    <ShareModal
      v-if="!guestMode && showShareModal"
      :nc-path="currentPath"
      :comments-globally-enabled="settings.comments_enabled"
      :recursion-enabled="!!settings.recursion_enabled"
      :recursive-default="!!settings.recursive_default"
      :recursive-default-depth="settings.recursive_default_depth"
      :edit-share="editingShare"
      @close="closeShareModal"
      @created="onShareCreated"
      @updated="onShareUpdated"
    />

    <!-- Export List Modal -->
    <ExportModal
      v-if="showExportModal"
      :images="filteredImages"
      :show-pick-col="settings.enable_pick_ui"
      @close="showExportModal = false"
    />

    <!-- Shortcut-Hilfe-Modal -->
    <Teleport to="body">
      <Transition name="sr-shortcuts">
        <div v-if="showShortcuts" class="sr-shortcuts-overlay" @click.self="showShortcuts = false">
          <div class="sr-shortcuts-dialog">
            <div class="sr-shortcuts-header">
              <span>{{ t('starrate', 'Tastaturkürzel') }}</span>
              <button class="sr-shortcuts-close" @click="showShortcuts = false">✕</button>
            </div>
            <div class="sr-shortcuts-body">
              <div class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Navigation') }}</div>
                <div class="sr-shortcuts-row"><kbd>← → ↑ ↓</kbd><span>{{ t('starrate', 'Bild wechseln') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Shift + Pfeile</kbd><span>{{ t('starrate', 'Mehrfachauswahl') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Strg + A</kbd><span>{{ t('starrate', 'Alle auswählen') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Home / End</kbd><span>{{ t('starrate', 'Erstes / Letztes Bild') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Esc</kbd><span>{{ t('starrate', 'Auswahl aufheben') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>Enter</kbd><span>{{ t('starrate', 'Lupenansicht öffnen') }}</span></div>
              </div>
              <div class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Zoom (Lupe)') }}</div>
                <div class="sr-shortcuts-row"><kbd>+ / −</kbd><span>{{ t('starrate', 'Rein- / Rauszoomen') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>{{ t('starrate', 'Leertaste') }}</kbd><span>{{ t('starrate', 'Eingepasst') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>{{ t('starrate', 'Doppelklick') }}</kbd><span>{{ t('starrate', '100% / Eingepasst') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>{{ t('starrate', 'Mausrad') }}</kbd><span>{{ t('starrate', 'Zoom auf Cursor') }}</span></div>
              </div>
              <div class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Bewertung') }}</div>
                <div class="sr-shortcuts-row"><kbd>0 – 5</kbd><span>{{ t('starrate', 'Sterne setzen') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>6</kbd><span>{{ t('starrate', 'Rot') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>7</kbd><span>{{ t('starrate', 'Gelb') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>8</kbd><span>{{ t('starrate', 'Grün') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>9</kbd><span>{{ t('starrate', 'Blau') }}</span></div>
                <div class="sr-shortcuts-row"><kbd>V</kbd><span>{{ t('starrate', 'Lila') }}</span></div>
              </div>
              <div v-if="settings.enable_pick_ui" class="sr-shortcuts-group">
                <div class="sr-shortcuts-group-title">{{ t('starrate', 'Auswahl') }}</div>
                <div class="sr-shortcuts-row"><kbd>P</kbd><span>Pick</span></div>
                <div class="sr-shortcuts-row"><kbd>X</kbd><span>Reject</span></div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Toast-Nachrichten -->
    <Teleport to="body">
      <div class="sr-toasts">
        <TransitionGroup name="toast">
          <div
            v-for="toast in toasts"
            :key="toast.id"
            class="sr-toast"
            :class="`sr-toast--${toast.type}`"
          >
            {{ toast.message }}
          </div>
        </TransitionGroup>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

/* global __APP_VERSION__ */
const appVersion = __APP_VERSION__
import { useRoute, useRouter } from 'vue-router'
import { t, n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import GridView from '../components/GridView.vue'
import FilterBar from '../components/FilterBar.vue'
import SelectionBar from '../components/SelectionBar.vue'
import LoupeView from '../components/LoupeView.vue'
import ShareModal from '../components/ShareModal.vue'
import ShareList from '../components/ShareList.vue'
import ExportModal from '../components/ExportModal.vue'
import FolderPopover from '../components/FolderPopover.vue'
import { readFolderState, writeFolderState } from '../utils/folderRecursiveState.js'

// ─── Gast-Modus-Props (alle optional, Defaults = normales Verhalten) ───────────

const props = defineProps({
  /** Gast-Modus: kein Settings-Abruf, kein Share-UI, kein localStorage-Filter */
  guestMode:      { type: Boolean,  default: false },
  /** Name-Badge im Breadcrumb (nur im Gast-Modus) */
  guestLabel:     { type: String,   default: '' },
  /** Ersetzt /api/images — fn(path) → { images, folders } */
  loadImagesFn:   { type: Function, default: null },
  /** Ersetzt /api/rating/{id} POST — fn(fileId, payload) */
  rateFn:         { type: Function, default: null },
  /** Ersetzt /api/rating/batch POST — fn(ids, payload) */
  batchRateFn:    { type: Function, default: null },
  /** Weitergereicht an GridView */
  thumbnailUrlFn: { type: Function, default: null },
  /** Weitergereicht an LoupeView */
  previewUrlFn:   { type: Function, default: null },
  /** Überschreibt enable_pick_ui im Gast-Modus (per-Share Einstellung) */
  enablePickOverride: { type: [Boolean, null], default: null },
  /** Gast-Modus: Bewerten erlaubt (per-Share Einstellung, default: false).
   *  Owner ist immer erlaubt (siehe canRate-Computed). */
  allowRate: { type: Boolean, default: false },
  /** Gast-Modus: Export List erlaubt (per-Share Einstellung, default: false) */
  allowExport: { type: Boolean, default: false },
  /** Gast-Modus: Kommentare erlaubt (per-Share Einstellung, default: false) */
  allowComment: { type: Boolean, default: false },
  /** Kommentar-API (Gast) — { save, load, remove } */
  commentApi: { type: Object, default: null },
  /** Gast-Modus: Download erlaubt (per-Share Einstellung, default: false) */
  allowDownload: { type: Boolean, default: false },
  /** Ersetzt /api/download/{id} — fn(fileId) → URL (Gast, mit pw_token) */
  downloadUrlFn: { type: Function, default: null },
  /** Ersetzt /api/download-zip — fn(ids[]) → URL (Gast, mit pw_token) */
  downloadZipUrlFn: { type: Function, default: null },
})

// ─── Zustand ──────────────────────────────────────────────────────────────────

const route  = useRoute()
const router = useRouter()

const mode         = ref('grid')       // 'grid' | 'loupe'
const loading      = ref(false)
const allImages    = ref([])

const settings = ref({
  default_sort:             'name',
  default_sort_order:       'asc',
  show_filename:             true,
  show_rating_overlay:       true,
  show_color_overlay:        true,
  grid_columns:             'auto',
  enable_pick_ui:            false,
  write_xmp:                 true,
  comments_enabled:          false,
  recursion_enabled:         false,
  recursive_default:         false,
  recursive_default_depth:   0,
  slideshow_interval:        4,
})
const subFolders   = ref([])
const currentIndex = ref(0)
const selectedIds       = ref(new Set())
const batchActiveRating = ref(null)        // zuletzt per Batch gesetztes Rating
const batchActiveColor  = ref(undefined)   // undefined=nie gesetzt, null=entfernt, String=Farbe
const batchActivePick   = ref(undefined)   // undefined=nie gesetzt, 'none'=entfernt, 'pick'|'reject'
const gridRef      = ref(null)
const shareListRef = ref(null)
const toasts       = ref([])
let   toastCounter = 0

const showShareList  = ref(false)
const showShareModal = ref(false)
const showShortcuts  = ref(false)
const showExportModal = ref(false)

const activeFilter = ref({
  minRating: 0,     // 0 = alle  (>=)
  exactRating: null,// null = kein Exact-Filter (=)
  maxRating: null,  // null = kein Max-Filter    (<=)
  color: null,      // null = alle
  pick: null,       // null | 'pick' | 'reject'
})

// ─── Aktueller Ordnerpfad ─────────────────────────────────────────────────────

const currentPath = computed(() => {
  const p = route.params.path
  return p ? `/${Array.isArray(p) ? p.join('/') : p}` : '/'
})

// ─── Recursive-View State (URL > localStorage > Settings-Default) ─────────────
//
// Master-Schalter recursion_enabled (User-Setting, Default false): wenn aus,
// wird das gesamte Feature inkl. URL-Param-Auswertung neutralisiert. Damit
// können Nutzer das Feature vollständig ausblenden, und ein versehentlicher
// Share-Link mit ?recursive=1 hat keinen Effekt.
//
// Hierarchie der Werte-Quellen (höchste Priorität zuerst):
//   1. URL-Query (?recursive=… / ?depth=…) — Sharing/Bookmarking
//   2. Per-Folder-Memory (localStorage via folderRecursiveState) — Convenience
//      damit der User beim Wiederbesuch desselben Folders an seinem Toggle-
//      Zustand andockt, ohne URL-Manipulation
//   3. Personal Settings Default — globaler Fallback
//
// Im Gast-Modus immer aus — Guest-API unterstützt Recursive aktuell nicht.
const recursionAvailable = computed(() => !props.guestMode && !!settings.value.recursion_enabled)

// Download: eingeloggte User dürfen immer (NC-Recht des Eigentümers); im
// Gast-Modus nur, wenn der Share-Ersteller es erlaubt hat (allow_download).
const canDownload = computed(() => !props.guestMode || props.allowDownload)
// Bewerten: Owner immer, Gast nur bei "View + Rate"-Share. Steuert die
// Interaktivität der Rating-Widgets (View-Only zeigt sie read-only).
const canRate = computed(() => !props.guestMode || props.allowRate)

// Browser-Download per programmatischem <a>-Klick anstoßen. Da die Response
// Content-Disposition: attachment liefert, lädt der Klick die Datei herunter,
// ohne die Galerie zu verlassen.
function clickDownloadLink(url, filename = '') {
  const a = document.createElement('a')
  a.href = url
  if (filename) a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
}

// Einzel-Download der Originaldatei. Eingeloggt → /api/download/{id};
// Gast → downloadUrlFn (enthält Token + pw_token).
function triggerDownload(image) {
  if (!image || !canDownload.value) return
  const url = props.downloadUrlFn
    ? props.downloadUrlFn(image.id)
    : generateUrl(`/apps/starrate/api/download/${image.id}`)
  clickDownloadLink(url, image.name || '')
}

// Soft-Schwellen für den ZIP-Download. ZIP_MAX MUSS mit der Server-Hartgrenze
// ShareService::MAX_ZIP_FILES übereinstimmen (Backend gibt sonst 422) —
// bei Änderung beide Stellen anpassen. ZIP_WARN_COUNT ist nur die Vorab-Warnung.
const ZIP_WARN_COUNT = 100
const ZIP_MAX = 500

// Lädt die aktuelle Auswahl als ZIP. Eingeloggt → /api/download-zip?ids=…;
// Gast → downloadZipUrlFn (Token + pw_token). Ein <a>-Klick streamt das ZIP.
function triggerZipDownload() {
  if (!canDownload.value) return
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) return

  if (ids.length > ZIP_MAX) {
    window.alert(t('starrate', 'Maximal {max} Bilder pro ZIP. Bitte die Auswahl verkleinern.', { max: ZIP_MAX }))
    return
  }

  const selected   = filteredImages.value.filter(img => selectedIds.value.has(img.id))
  const totalBytes = selected.reduce((sum, img) => sum + (img.size || 0), 0)
  if (ids.length > ZIP_WARN_COUNT || totalBytes > 1024 * 1024 * 1024) {
    const mb = Math.round(totalBytes / (1024 * 1024))
    if (!window.confirm(t('starrate', '{n} Bilder ({mb} MB) als ZIP herunterladen?', { n: ids.length, mb }))) {
      return
    }
  }

  const url = props.downloadZipUrlFn
    ? props.downloadZipUrlFn(ids)
    : generateUrl(`/apps/starrate/api/download-zip?ids=${ids.join(',')}`)
  clickDownloadLink(url)
}

const recursive = computed(() => {
  if (!recursionAvailable.value) return false
  const q = route.query.recursive
  if (q !== undefined) return q === '1' || q === 'true'
  const stored = readFolderState(currentPath.value)
  if (stored) return stored.recursive
  return settings.value.recursive_default
})

const depth = computed(() => {
  if (!recursionAvailable.value) return 0
  const q = route.query.depth
  if (q !== undefined) {
    const d = parseInt(q, 10)
    if (Number.isFinite(d) && d >= 0 && d <= 4) return d
  }
  const stored = readFolderState(currentPath.value)
  if (stored) return stored.depth
  return settings.value.recursive_default_depth
})

// ─── Dynamischer Breadcrumb-Tail (nur Recursive-Modus) ────────────────────────
//
// Beim Hover/Focus über ein Tile wird dessen Subfolder-Pfad als Breadcrumb-
// Erweiterung sichtbar. Visualisiert dem User „woher kommt dieses Bild" ohne
// Per-Tile-Klutter. Hover-out behält letzten Wert (per Design).

const hoveredImage = ref(null)

function onFocusPreview(image) {
  if (image) hoveredImage.value = image
}

// Folder-Wechsel resettet hoveredImage — sonst zeigt der dynamische
// Breadcrumb-Tail im neuen Folder die Pfad-Segmente des zuletzt im alten
// Folder gehoverten Bildes (z.B. '... / 2025 / Wedding' obwohl der neue
// Folder gar nicht /Vacation/2025/Wedding/ enthält).
watch(currentPath, () => { hoveredImage.value = null })

const hoveredFolderSegments = computed(() => {
  if (!recursive.value || !hoveredImage.value?.relPath) return []
  const segments = hoveredImage.value.relPath.split('/')
  segments.pop()  // Dateiname raus, nur Folder-Anteile
  return segments
})

// Klick auf dynamischen Segment → in den entsprechenden Subfolder navigieren,
// Recursion verlassen (über Query-Override).
function exitRecursionInto(segmentIndex) {
  const subPath = hoveredFolderSegments.value.slice(0, segmentIndex + 1).join('/')
  const target = currentPath.value === '/' ? `/${subPath}` : `${currentPath.value}/${subPath}`
  router.push({ path: `/folder${target}`, query: { recursive: '0' } })
}

// FilterBar-Toggle/Stepper schreiben in URL-Query — die Computed-Werte oben
// reagieren darauf und triggern via Watch das Reload. Wir mergen in die
// existierende Query, damit andere URL-Params (Filter etc.) erhalten bleiben.
//
// Zusätzlich persistieren wir den neuen Zustand pro Folder in localStorage,
// damit der User beim Wiederbesuch desselben Folders dort weiterarbeitet wo
// er aufgehört hat — ohne URL-Manipulation. Bewusst NICHT bei reinen URL-
// Aufrufen (z.B. von einem Share-Link), nur bei expliziter UI-Interaktion.
function setRecursive(value) {
  const q = { ...route.query, recursive: value ? '1' : '0' }
  router.replace({ path: route.path, query: q })
  writeFolderState(currentPath.value, value, depth.value)
}
function setDepth(value) {
  const q = { ...route.query, depth: String(value) }
  router.replace({ path: route.path, query: q })
  writeFolderState(currentPath.value, recursive.value, value)
}

const pathSegments = computed(() =>
  currentPath.value.split('/').filter(Boolean)
)

function pathUpTo(index) {
  return '/' + pathSegments.value.slice(0, index + 1).join('/')
}

function navigateTo(path) {
  if (mode.value === 'loupe') mode.value = 'grid'
  if (path === '/') {
    router.push('/')
  } else {
    router.push(`/folder${path}`)
  }
}

// ─── Bilder filtern ───────────────────────────────────────────────────────────

const filteredImages = computed(() => {
  let imgs = allImages.value

  const f = activeFilter.value

  if (f.exactRating !== null) {
    imgs = imgs.filter(i => i.rating === f.exactRating)
  } else if (f.minRating > 0) {
    imgs = imgs.filter(i => i.rating >= f.minRating)
  } else if (f.maxRating !== null) {
    imgs = imgs.filter(i => i.rating <= f.maxRating)
  }

  if (f.color) {
    imgs = imgs.filter(i => i.color === f.color)
  }

  if (f.pick && settings.value.enable_pick_ui) {
    imgs = imgs.filter(i => i.pick === f.pick)
  }

  return imgs
})

const hasActiveFilter = computed(() =>
  activeFilter.value.minRating > 0 ||
  activeFilter.value.exactRating !== null ||
  activeFilter.value.maxRating !== null ||
  activeFilter.value.color !== null ||
  (settings.value.enable_pick_ui && activeFilter.value.pick !== null)
)

// ─── Bilder laden ─────────────────────────────────────────────────────────────

let loadSeq = 0  // Sequenzzähler: verhindert dass alte Requests neuere überschreiben

async function loadImages({ silent = false } = {}) {
  const seq = ++loadSeq
  // silent=true: keine Skeleton-Anzeige während des Requests. Für Background-Sync
  // und Visibility-Revisit — dort ist das Grid bereits voll gefüllt, ein kurzer
  // Flash auf Skeleton würde die Scroll-Position zerstören (DOM-Struktur wechselt
  // komplett, scroll-anchoring kommt nur teilweise zurück).
  if (!silent) loading.value = true
  try {
    let data
    if (props.loadImagesFn) {
      data = await props.loadImagesFn(currentPath.value)
    } else {
      const url = generateUrl('/apps/starrate/api/images')
      const res = await axios.get(url, {
        params: {
          path:      currentPath.value,
          sort:      settings.value.default_sort,
          order:     settings.value.default_sort_order,
          recursive: recursive.value ? 1 : 0,
          depth:     depth.value,
        },
        timeout: 15000,
      })
      data = res.data
    }
    if (seq !== loadSeq) return  // veralteter Request – ignorieren
    const incoming = data.images || []
    const current  = allImages.value

    // Fast-path: Wenn Ordner-Inhalt sich semantisch NICHT geändert hat (gleiche IDs
    // in gleicher Reihenfolge), nur Metadata (rating/color/pick) in-place mergen.
    // Kein Array-Swap → kein GridView-Watch-Trigger → keine Scroll-Position-Verloren,
    // keine Thumb-Queue-Reset, kein <img>-Rebinding (Paint-Suppression-Risiko).
    // Das ist der Normalfall bei visibilitychange und Background-Sync.
    const sameShape = current.length === incoming.length
      && current.every((cur, i) => cur.id === incoming[i].id)

    if (sameShape) {
      incoming.forEach((inc, i) => {
        const cur = current[i]
        if (cur.rating !== inc.rating) cur.rating = inc.rating
        if (cur.color  !== inc.color)  cur.color  = inc.color
        if (cur.pick   !== inc.pick)   cur.pick   = inc.pick
        if (cur.name   !== inc.name)   cur.name   = inc.name
      })
    } else {
      // Shape hat sich geändert (neuer Ordner, neue Datei, Umsortierung): neu aufbauen.
      // Thumb-State für bereits bekannte IDs mergen, damit z. B. nach Upload nicht
      // alle anderen Thumbs neu geladen werden.
      const existing = new Map(current.map(i => [i.id, i]))
      allImages.value = incoming.map(img => {
        const prev = existing.get(img.id)
        const done = prev?.thumbLoaded === true && prev?.thumbUrl
        return {
          ...img,
          thumbLoaded:  done,
          thumbLoading: false,
          thumbUrl:     done ? prev.thumbUrl : null,
          thumbRetries: 0,
          thumbError:   false,
        }
      })
    }
    subFolders.value = data.folders || []
  } catch {
    if (seq !== loadSeq) return
    showToast(t('starrate', 'Bilder konnten nicht geladen werden'), 'error')
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

// Pfadwechsel ODER Recursive-Toggle/Depth-Änderung → Bilder neu laden.
// loadGate verhindert doppelten Load beim Initial-Mount: ohne den Gate würde
// die Watch sofort feuern, sobald loadSettings() recursive_default in den
// settings-ref schreibt (computed `recursive` ändert sich von Default→Wert).
// Erst NACH dem manuellen loadImages() in onMounted öffnen wir das Tor.
let loadGate = false
watch([currentPath, recursive, depth], () => {
  if (loadGate) loadImages()
})

// Pick-Filter zurücksetzen wenn Pick-UI deaktiviert wird
watch(() => settings.value.enable_pick_ui, enabled => {
  if (!enabled && activeFilter.value.pick !== null) {
    activeFilter.value = { ...activeFilter.value, pick: null }
  }
})

// Loupe: Index anpassen wenn Filter das aktuelle Bild entfernt
watch(filteredImages, (newList, oldList) => {
  if (mode.value !== 'loupe') return

  if (newList.length === 0) {
    // Kein Bild übrig → zurück zur Grid-Ansicht (dort CTA "Alle Filter löschen")
    mode.value = 'grid'
    return
  }

  // Gleiches Bild in neuer Liste suchen
  const currentImg = oldList?.[currentIndex.value]
  if (currentImg) {
    const newIdx = newList.findIndex(i => i.id === currentImg.id)
    if (newIdx >= 0) {
      currentIndex.value = newIdx
      return
    }
  }

  // Bild wurde rausgefiltert → Index clampen (nächstes verfügbares Bild)
  if (currentIndex.value >= newList.length) {
    currentIndex.value = newList.length - 1
  }
})

// ─── Bewertung setzen ─────────────────────────────────────────────────────────

async function onRate(image, rating, color, pick) {
  const payload = {}
  if (rating !== undefined) payload.rating = rating
  if (color  !== undefined) payload.color  = color
  if (pick   !== undefined) payload.pick   = pick

  if (Object.keys(payload).length === 0) return

  // Optimistisch updaten
  const local = allImages.value.find(i => i.id === image.id)
  if (local) Object.assign(local, payload)

  try {
    if (props.rateFn) {
      await props.rateFn(image.id, payload)
    } else {
      const url = generateUrl(`/apps/starrate/api/rating/${image.id}`)
      await axios.post(url, payload)
    }

    if (settings.value.write_xmp) {
      if (payload.rating !== undefined) {
        const stars = '★'.repeat(payload.rating) + '☆'.repeat(5 - payload.rating)
        showToast(t('starrate', '{name}: {stars}', { name: image.name, stars }), 'success')
      } else if (payload.color !== undefined) {
        const label = payload.color || '○'
        showToast(t('starrate', '{name}: {label}', { name: image.name, label }), 'success')
      } else if (payload.pick !== undefined) {
        const label = payload.pick === 'pick' ? '✓ Pick' : payload.pick === 'reject' ? '⊘ Reject' : '— ' + t('starrate', 'kein Pick')
        showToast(t('starrate', '{name}: {label}', { name: image.name, label }), 'success')
      }
    }
  } catch {
    // Rollback
    if (local) await loadImages()
    showToast(t('starrate', 'Bewertung konnte nicht gespeichert werden'), 'error')
  }
}

// ─── Stapel-Bewertung ─────────────────────────────────────────────────────────

// Debounce-State: mehrere schnelle Klicks (Stern + Farbe + Pick) werden zu einem
// kombinierten API-Request zusammengeführt, um konkurrierende JPEG-Writes zu vermeiden.
let _batchDebounceTimer = null
let _pendingBatch = null

function onBatchRate(rating, color, pick) {
  const ids = Array.from(selectedIds.value)
  if (ids.length === 0) return

  // Optimistischer UI-Update sofort (unabhängig vom Debounce)
  if (rating !== undefined) batchActiveRating.value = rating
  if (color  !== undefined) batchActiveColor.value  = color
  if (pick   !== undefined) batchActivePick.value   = pick

  ids.forEach(id => {
    const local = allImages.value.find(i => i.id === id)
    if (local) {
      if (rating !== undefined) local.rating = rating
      if (color  !== undefined) local.color  = color
      if (pick   !== undefined) local.pick   = pick
    }
  })

  // Payload akkumulieren — mehrere Klicks innerhalb des Debounce-Fensters
  // werden zu einem einzigen Request zusammengeführt.
  // fileIds wird bei jedem Klick aktualisiert, damit eine geänderte Auswahl
  // immer den letzten Stand widerspiegelt.
  const isFirst = !_pendingBatch
  if (!_pendingBatch) _pendingBatch = {}
  _pendingBatch.fileIds = ids
  if (rating !== undefined) _pendingBatch.rating = rating
  if (color  !== undefined) _pendingBatch.color  = color
  if (pick   !== undefined) _pendingBatch.pick   = pick

  // Sofort-Feedback nur wenn XMP-Writes aktiv — dann dauert der Batch spürbar länger
  if (isFirst && ids.length > 10 && settings.value.write_xmp) {
    showToast(n('starrate', '%n Bild wird bewertet…\nBitte warten', '%n Bilder werden bewertet…\nBitte warten', ids.length), 'info')
  }

  clearTimeout(_batchDebounceTimer)
  _batchDebounceTimer = setTimeout(() => _sendBatch(), 2000)
}

async function _sendBatch() {
  const payload = _pendingBatch
  _pendingBatch = null

  if (!payload) return

  // Grid-Focus zurückgeben (SelectionBar-Klick nimmt Focus vom Grid weg)
  await nextTick()
  gridRef.value?.$el?.focus?.()

  const bildText = n('starrate', '%n Bild', '%n Bilder', payload.fileIds.length)
  const stars = payload.rating !== undefined
    ? ' — ' + '★'.repeat(payload.rating) + (payload.rating < 5 ? '☆'.repeat(5 - payload.rating) : '')
    : ''

  try {
    if (props.batchRateFn) {
      const { fileIds: _, ...ratingData } = payload
      await props.batchRateFn(payload.fileIds, ratingData)
    } else {
      const url = generateUrl('/apps/starrate/api/rating/batch')
      const { data } = await axios.post(url, payload)

      if (data.errors > 0) {
        showToast(n('starrate', '%n Fehler', '%n Fehler', data.errors), 'error')
      }
      if (data.xmpSkipped > 0) {
        const xmpLine = t('starrate', 'XMP: {written} geschrieben, {skipped} nicht geschrieben\nBitte nochmal setzen',
                          { written: data.xmpWritten, skipped: data.xmpSkipped })
        showToast(xmpLine, 'warning', 7000)
      }
    }

    showToast(`${bildText} ${t('starrate', 'bewertet')}${stars}`, 'success')
  } catch {
    // Rollback: lokalen State wiederherstellen und Bar-Anzeige zurücksetzen
    batchActiveRating.value = null
    batchActiveColor.value  = undefined
    batchActivePick.value   = undefined
    await loadImages()
    showToast(t('starrate', 'Stapel-Bewertung fehlgeschlagen'), 'error')
  }
}

// ─── Filter ↔ URL-Query-Params ────────────────────────────────────────────────

function filterToQuery(f) {
  const q = {}
  if (f.minRating   > 0)      q.r  = String(f.minRating)
  if (f.exactRating !== null)  q.re = String(f.exactRating)
  if (f.maxRating   !== null)  q.rm = String(f.maxRating)
  if (f.color)                 q.c  = f.color
  if (f.pick)                  q.p  = f.pick
  return q
}

function queryToFilter(q) {
  return {
    minRating:   q.r  !== undefined ? Number(q.r)  : 0,
    exactRating: q.re !== undefined ? Number(q.re) : null,
    maxRating:   q.rm !== undefined ? Number(q.rm) : null,
    color:       q.c  || null,
    pick:        q.p  || null,
  }
}

// ─── Filter zurücksetzen ──────────────────────────────────────────────────────

function resetFilter() {
  activeFilter.value = { minRating: 0, exactRating: null, maxRating: null, color: null, pick: null }
}

// ─── Modus-Wechsel ────────────────────────────────────────────────────────────

function toggleMode() {
  mode.value = mode.value === 'grid' ? 'loupe' : 'grid'
}

function openLoupe(image, index) {
  currentIndex.value = index
  selectedIds.value = new Set()
  mode.value = 'loupe'
}

// Android Back-Button: Loupe → Grid statt Browser-Navigation
// Beim Öffnen der Loupe einen History-Eintrag pushen; beim Schließen (ESC/X)
// den Eintrag konsumieren; popstate (Android Back) setzt mode auf grid.
function onPopState() {
  if (mode.value === 'loupe') {
    mode.value = 'grid'
  }
}

watch(mode, (newVal, oldVal) => {
  if (newVal === 'loupe') {
    history.pushState({ srLoupe: true }, '')
  } else if (oldVal === 'loupe') {
    // Loupe per ESC/X geschlossen (nicht per popstate) → History-Eintrag konsumieren
    if (history.state?.srLoupe) {
      history.back()
    }
  }
})

// ─── Auswahl ──────────────────────────────────────────────────────────────────

function onSelectionChange(ids) {
  selectedIds.value = ids
  if (ids.size === 0) {
    batchActiveRating.value = null
    batchActiveColor.value  = undefined
    batchActivePick.value   = undefined
  }
}

// ─── Toast ────────────────────────────────────────────────────────────────────

function showToast(message, type = 'success', duration = 3000) {
  const id = ++toastCounter
  toasts.value.push({ id, message, type })
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }, duration)
}

// ─── Share ────────────────────────────────────────────────────────────────────

const editingShare = ref(null)

function openShareModalForCreate() {
  editingShare.value = null
  showShareModal.value = true
}

function openShareModalForEdit(share) {
  editingShare.value = share
  showShareModal.value = true
}

function closeShareModal() {
  showShareModal.value = false
  editingShare.value   = null
}

function onShareCreated() {
  closeShareModal()
  shareListRef.value?.loadShares()
}

function onShareUpdated() {
  closeShareModal()
  shareListRef.value?.loadShares()
}

// Escape auf App-Root-Ebene: stoppt NC's ESC-Handler bevor das Event bubbled.
// Nur aktiv wenn ein Modal offen ist — sonst NC Folder-Navigation normal lassen.
function onAppKeydown(e) {
  if (e.key !== 'Escape') return
  const modalOpen = showExportModal.value || showShareModal.value
    || showShareList.value || showShortcuts.value
  if (!modalOpen) return
  e.stopPropagation()
  e.preventDefault()
}

// Escape auf Dokument-Ebene: schließt Modals von innen nach außen, dann Auswahl.
// Muss in Capture-Phase laufen, da NC einen eigenen ESC-Handler auf document hat,
// der sonst zuerst feuert. Modals sind per Teleport in <body> — @keydown auf
// .sr-app greift dort nicht, deshalb reicht nur onAppKeydown nicht aus.
function onDocKeydown(e) {
  if (e.key !== 'Escape') return
  // FolderPopover schließt sich selbst — nicht zusätzlich Selektion räumen
  if (document.querySelector('.sr-folder-popover__menu')) return
  const stop = () => { e.preventDefault(); e.stopPropagation() }
  if (showExportModal.value)      { showExportModal.value = false; try { document.activeElement?.blur() } catch { /* ignore */ } stop(); return }
  if (showShareModal.value)       { showShareModal.value = false; try { document.activeElement?.blur() } catch { /* ignore */ } stop(); return }
  if (showShareList.value)        { showShareList.value  = false; try { document.activeElement?.blur() } catch { /* ignore */ } stop(); return }
  if (showShortcuts.value)        { showShortcuts.value  = false; stop(); return }
  if (selectedIds.value.size > 0) { gridRef.value?.clearSelection?.() }
}

async function loadSettings() {
  if (props.guestMode) {
    // Gast: Pick-UI vom Share-Override steuern
    if (props.enablePickOverride != null) {
      settings.value.enable_pick_ui = props.enablePickOverride
    }
    return
  }
  try {
    const url = generateUrl('/apps/starrate/api/settings')
    const { data } = await axios.get(url)
    Object.assign(settings.value, data)
  } catch {
    // Standardwerte behalten
  }
}

// ─── Sync-Light: einzelnes Bild aktualisieren (für Loupe-Navigation) ──────────
//
// Debounced damit beim Durchscrollen via Pfeiltasten keine Queue von Server-
// Requests aufläuft — der Backend-Endpoint führt zusätzlich einen Self-healing
// XMP-Read durch (~30–250 ms pro Bild). Erst wenn der User auf einem Bild
// stehen bleibt (LINGER_MS), feuert genau ein Request für das aktuelle Bild.

const REFRESH_LINGER_MS = 400
let _refreshTimer = null

function refreshImageRating(image) {
  if (!image?.id) return
  clearTimeout(_refreshTimer)
  _refreshTimer = setTimeout(async () => {
    try {
      const url = generateUrl(`/apps/starrate/api/rating/${image.id}`)
      const { data } = await axios.get(url, { timeout: 5000 })
      const local = allImages.value.find(i => i.id === image.id)
      if (local) Object.assign(local, { rating: data.rating, color: data.color, pick: data.pick })
    } catch {
      // still ignore – user sees last known value
    }
  }, REFRESH_LINGER_MS)
}

// ─── Background-Sync: alle 5 Minuten stiller Reload wenn Tab sichtbar ──────

const SYNC_INTERVAL_MS = 5 * 60_000  // 5 Minuten
let syncTimer = null

function startBackgroundSync() {
  clearInterval(syncTimer)
  syncTimer = setInterval(() => {
    if (!document.hidden && !loading.value) loadImages({ silent: true })
  }, SYNC_INTERVAL_MS)
}

function stopBackgroundSync() {
  clearInterval(syncTimer)
  syncTimer = null
}

// ─── Visibility-Refresh: Tab kommt in Vordergrund → Ordner neu laden ──────────

function onVisibilityChange() {
  if (!document.hidden && !loading.value) loadImages({ silent: true })
}

onMounted(async () => {
  document.addEventListener('keydown', onDocKeydown, true)
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('popstate', onPopState)
  startBackgroundSync()

  // Settings laden, dann erst Bilder (damit sort/order korrekt ist)
  await loadSettings()

  // URL-Query-Params / localStorage nur im normalen Modus
  if (!props.guestMode) {
    const q = route.query
    const hasUrlFilter = ['r', 're', 'rm', 'c', 'p'].some(k => k in q)
    if (hasUrlFilter) {
      Object.assign(activeFilter.value, queryToFilter(q))
    } else {
      const saved = localStorage.getItem(`starrate_filter_${currentPath.value}`)
      if (saved) {
        try { Object.assign(activeFilter.value, JSON.parse(saved)) } catch {}
      }
    }
  }

  // Erster Bildladevorgang nach Settings & Filter; danach Watch aktivieren.
  loadImages()
  loadGate = true
})

onUnmounted(() => {
  document.removeEventListener('keydown', onDocKeydown, true)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('popstate', onPopState)
  stopBackgroundSync()
})

// Filter in localStorage + URL persistieren (nur normaler Modus)
watch(activeFilter, val => {
  if (props.guestMode) return
  localStorage.setItem(`starrate_filter_${currentPath.value}`, JSON.stringify(val))
  router.replace({ query: filterToQuery(val) })
}, { deep: true })

// Browser-Back/Forward: URL-Änderung → Filter aktualisieren
watch(() => route.query, q => {
  Object.assign(activeFilter.value, queryToFilter(q))
}, { deep: true })
</script>

<style scoped>
.sr-app {
  display: flex;
  flex-direction: column;
  width: 100%;
  flex: 1;        /* height:100% ist in Flex-Kontext unzuverlässig → flex:1 */
  min-height: 0;  /* erlaubt Flex-Kind zu schrumpfen und intern zu scrollen */
  background: #1a1a2e;
  color: #e0e0e0;
  font-family: 'Inter', system-ui, -apple-system, sans-serif;
  overflow: hidden;
}

.sr-app--loupe {
  background: #000;
}

/* Toasts */
.sr-toasts {
  position: fixed;
  top: 60px;
  right: 16px;
  bottom: auto;
  left: auto;
  transform: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
  z-index: 9999;
  pointer-events: none;
}

.sr-toast {
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  white-space: pre-line;
  box-shadow: 0 4px 12px rgba(0,0,0,0.4);
  pointer-events: auto;
}

.sr-toast--success { background: #2a4a2a; color: #7ecf7e; border: 1px solid #3a6a3a; }
.sr-toast--error   { background: #4a1a1a; color: #e94560; border: 1px solid #6a2a2a; }
.sr-toast--warning { background: #4a2e0a; color: #f0a030; border: 1px solid #6a4a1a; }
.sr-toast--info    { background: #1a2a4a; color: #7eaecf; border: 1px solid #2a3a6a; }

.toast-enter-active,
.toast-leave-active {
  transition: all 250ms ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}

/* Nav-Zeile: Breadcrumb + SubFolders */
.sr-nav-row {
  display: contents; /* Desktop: transparent, Kinder nehmen am sr-app-Flex teil */
}

.sr-folders {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  gap: 4px;
  padding: 4px 8px;
  width: 100%;
}

.sr-folders__item {
  display: flex;
  align-items: center;
  gap: 4px;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 4px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 12px;
  padding: 2px 8px;
  white-space: nowrap;
  transition: color 0.15s, border-color 0.15s;
}
@media (pointer: fine) {
  .sr-folders__item:hover {
    color: #d4d4d8;
    border-color: #5a5a8a;
  }
}

/* Breadcrumb: scoped für höhere Spezifizität gegenüber NC-Styles */
.sr-breadcrumb {
  display: flex;
  align-items: center;
  width: 100%;
}

/* Dynamischer Tail im Recursive-Modus: optisch zurückgenommen, damit man auf
   einen Blick sieht „das ist der Hover-Kontext, nicht meine Position". */
.sr-breadcrumb__seg--dynamic,
.sr-breadcrumb__sep--dynamic {
  opacity: 0.6;
  font-weight: 400;
}
.sr-breadcrumb__seg--dynamic:hover { opacity: 1; }

.sr-view-wrap {
  flex: 1;
  min-height: 0;
}

.sr-breadcrumb__utility {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  flex-shrink: 0;
}

.sr-breadcrumb__guest-label {
  background: #2a2a3e;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #a1a1aa;
  font-size: 11px;
  padding: 2px 8px;
  white-space: nowrap;
  flex-shrink: 0;
  text-align: right;
  line-height: 1.5;
}

.sr-breadcrumb__version-link,
.sr-breadcrumb__version-link:visited,
.sr-breadcrumb__version-link:hover,
.sr-breadcrumb__version-link:active {
  color: #8a8aa8 !important;
  text-decoration: none;
}

.sr-breadcrumb__version-link:hover {
  text-decoration: underline;
}

.sr-breadcrumb__mode {
  display: flex;
  background: #1a1a2e;
  border: 1px solid #2a2a4a;
  border-radius: 6px;
  overflow: hidden;
  flex-shrink: 0;
}

.sr-breadcrumb__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 28px;
  padding: 0 !important;
  border: none !important;
  background: transparent;
  color: #666;
  cursor: pointer;
  transition: background 150ms, color 150ms;
  appearance: none !important;
  -webkit-appearance: none !important;
  box-shadow: none !important;
}

.sr-breadcrumb__mode-btn:hover { color: #aaa; background: #2a2a4a; }
.sr-breadcrumb__mode-btn:focus,
.sr-breadcrumb__mode-btn:focus-visible,
.sr-breadcrumb__mode-btn:active { box-shadow: none !important; outline: none !important; }
.sr-breadcrumb__mode-btn--active { background: #3a1a28 !important; color: #d08090 !important; }
.sr-breadcrumb__mode-btn svg { width: 15px; height: 15px; }

.sr-breadcrumb__version {
  margin-left: 8px;
  font-size: 10px;
  color: #7a7a96;
  user-select: none;
  letter-spacing: 0.04em;
  padding-left: 12px;
  white-space: nowrap;
  flex-shrink: 0;
  text-align: right;
  line-height: 1.5;
  opacity: 0.35;
  transition: opacity 250ms;
}
.sr-breadcrumb__version:hover { opacity: 1; }

.sr-breadcrumb__version-link,
.sr-breadcrumb__version-link:visited,
.sr-breadcrumb__version-link:hover,
.sr-breadcrumb__version-link:active {
  color: #8a8aa8 !important;
  text-decoration: underline !important;
}

/* ── Help-Button ─────────────────────────────────────────────────────────── */
.sr-breadcrumb__help {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid #2a2a4a;
  background: transparent;
  color: #555;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
  margin-left: 6px;
  transition: color 150ms, border-color 150ms;
  box-shadow: none !important;
  line-height: 1;
  padding: 0 !important;
}
.sr-breadcrumb__help:hover { color: #aaa; border-color: #555; }
.sr-breadcrumb__help:focus,
.sr-breadcrumb__help:active { outline: none !important; box-shadow: none !important; }

/* ── Shortcut-Modal ──────────────────────────────────────────────────────── */
.sr-shortcuts-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}

.sr-shortcuts-dialog {
  background: #16213e;
  border: 1px solid #2a2a4a;
  border-radius: 12px;
  width: min(480px, 92vw);
  overflow: hidden;
}

.sr-shortcuts-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid #2a2a4a;
  font-size: 13px;
  font-weight: 600;
  color: #d4d4e8;
}

.sr-shortcuts-close {
  background: transparent;
  border: none;
  color: #666;
  cursor: pointer;
  font-size: 14px;
  padding: 0;
  line-height: 1;
  transition: color 150ms;
}
.sr-shortcuts-close:hover { color: #aaa; }

.sr-shortcuts-body {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0;
  padding: 0;
}

.sr-shortcuts-group {
  padding: 14px 16px;
  border-right: 1px solid #2a2a4a;
}
.sr-shortcuts-group:last-child { border-right: none; }

.sr-shortcuts-group-title {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #555;
  margin-bottom: 10px;
  font-weight: 600;
}

.sr-shortcuts-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 6px;
}

.sr-shortcuts-row kbd {
  font-size: 10px;
  background: #1a1a2e;
  border: 1px solid #3a3a5a;
  border-radius: 4px;
  padding: 1px 5px;
  color: #a1a1cc;
  white-space: nowrap;
  flex-shrink: 0;
  font-family: inherit;
}

.sr-shortcuts-row span {
  font-size: 11px;
  color: #888;
}

.sr-shortcuts-enter-active,
.sr-shortcuts-leave-active { transition: opacity 150ms; }
.sr-shortcuts-enter-from,
.sr-shortcuts-leave-to { opacity: 0; }

/* ── Mobile: Nav-Zeile kompakt, Breadcrumb scrollbar ─────────────────────── */
@media (max-width: 640px) {
  .sr-nav-row {
    display: flex;
    flex-direction: row;
    align-items: center;
    flex-shrink: 0;
    gap: 0;
  }

  .sr-nav-row .sr-breadcrumb {
    flex: 1;
    min-width: 0;
    width: auto;
    padding: 4px 8px;
    overflow-x: auto;
    scrollbar-width: none;
  }
  .sr-nav-row .sr-breadcrumb::-webkit-scrollbar { display: none; }

  /* Unterordner-Pills auf Mobile ausblenden — sind im FolderPopover verfügbar */
  .sr-nav-row .sr-folders { display: none; }

  /* Utility-Cluster (Modus/Help/Version) nur Desktop */
  .sr-breadcrumb__utility { display: none; }

  /* Pfad-Segmente dürfen schrumpfen */
  .sr-breadcrumb__seg {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
