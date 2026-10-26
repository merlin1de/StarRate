<template>
  <div
    class="sr-loupe"
    ref="loupeEl"
    @wheel.prevent="onWheel"
    @dblclick="onDblClick"
    @mousedown="onMouseDown"
    @mousemove="onMouseMove"
    @mouseup="onMouseUp"
    @mouseleave="onMouseUp"
    @touchstart.passive="onTouchStart"
    @touchmove.prevent="onTouchMove"
    @touchend="onTouchEnd"
  >
    <!-- Hauptbild -->
    <Transition :name="transitionName">
      <div
        class="sr-loupe__stage"
        :key="currentIndex"
      >
        <img
          v-if="currentImage && !previewError"
          ref="imgEl"
          class="sr-loupe__img"
          :src="actualSrc"
          :alt="currentImage.name"
          :style="imgStyle"
          draggable="false"
          decoding="sync"
          @load="onImgLoad"
          @error="onImgError"
        />
        <div v-else-if="previewError" class="sr-loupe__placeholder sr-loupe__placeholder--error">
          <svg viewBox="0 0 64 64" fill="none"><rect x="8" y="16" width="48" height="36" rx="4" stroke="#555" stroke-width="2"/><line x1="8" y1="52" x2="56" y2="16" stroke="#555" stroke-width="2"/></svg>
        </div>
        <div v-else class="sr-loupe__placeholder">
          <svg viewBox="0 0 64 64" fill="none"><rect x="8" y="16" width="48" height="36" rx="4" stroke="#333" stroke-width="2"/></svg>
        </div>
      </div>
    </Transition>

    <!-- Ladeindikator -->
    <Transition name="fade">
      <div v-if="loadingPreview" class="sr-loupe__loading">
        <div class="sr-loupe__spinner" />
      </div>
    </Transition>

    <!-- Zurück-Button (oben links) -->
    <button
      class="sr-loupe__back"
      type="button"
      :title="t('starrate', 'Zurück zur Rasteransicht (Esc)')"
      @click="$emit('close')"
    >
      <svg viewBox="0 0 24 24" fill="none"><polyline points="15 18 9 12 15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <!-- Download + Zoom-Anzeige + Schließen (oben rechts) -->
    <div class="sr-loupe__top-right">
      <!-- Download getrennt links, mit Abstand zum Schließen-Button: beim Cullen
           ist man unten im Dauerklick, oben drohen keine Fehl-Taps. -->
      <button
        v-if="canDownload"
        class="sr-loupe__download"
        type="button"
        :title="t('starrate', 'Bild herunterladen')"
        :aria-label="t('starrate', 'Bild herunterladen')"
        @click="$emit('download', currentImage)"
      >
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 3v12m0 0l-4-4m4 4l4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
      <div class="sr-loupe__zoom-level" aria-live="polite">{{ zoomLabel }}</div>
      <button
        class="sr-loupe__close"
        type="button"
        :title="t('starrate', 'Schließen')"
        @click="$emit('close')"
      >
        <svg viewBox="0 0 24 24" fill="none">
          <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <!-- Navigations-Pfeile -->
    <button
      v-if="hasPrev"
      class="sr-loupe__nav sr-loupe__nav--prev"
      type="button"
      :aria-label="t('starrate', 'Vorheriges Bild')"
      @click.stop="navigate(-1)"
    >
      <svg viewBox="0 0 24 24" fill="none"><polyline points="15 18 9 12 15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <button
      v-if="hasNext"
      class="sr-loupe__nav sr-loupe__nav--next"
      type="button"
      :aria-label="t('starrate', 'Nächstes Bild')"
      @click.stop="navigate(1)"
    >
      <svg viewBox="0 0 24 24" fill="none"><polyline points="9 18 15 12 9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <!-- Untere Overlay: Dateiname + Rating + Farbe -->
    <Transition name="slide-up">
      <div class="sr-loupe__footer" v-show="showControls">
        <!-- Diashow: Play/Pause + (im Play-Modus) Intervall-Dropdown -->
        <div class="sr-loupe__footer-slideshow">
          <button
            class="sr-loupe__slideshow-btn"
            :class="{ 'sr-loupe__slideshow-btn--active': playing }"
            type="button"
            :title="playing ? t('starrate', 'Diashow pausieren (S)') : t('starrate', 'Diashow starten (S)')"
            :aria-label="playing ? t('starrate', 'Diashow pausieren') : t('starrate', 'Diashow starten')"
            :aria-pressed="playing"
            @click="togglePlay"
          >
            <svg v-if="!playing" class="sr-loupe__slideshow-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <polygon points="6,4 20,12 6,20" />
            </svg>
            <svg v-else class="sr-loupe__slideshow-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <rect x="6" y="4" width="4" height="16"/>
              <rect x="14" y="4" width="4" height="16"/>
            </svg>
          </button>
          <Transition name="fade">
            <select
              v-if="playing"
              class="sr-loupe__slideshow-select"
              :value="slideshowSec"
              :title="t('starrate', 'Diashow-Intervall')"
              :aria-label="t('starrate', 'Diashow-Intervall in Sekunden')"
              @change="changeInterval(parseInt($event.target.value, 10))"
            >
              <option v-for="s in SLIDESHOW_INTERVALS" :key="s" :value="s">{{ s }} s</option>
            </select>
          </Transition>
        </div>

        <div class="sr-loupe__footer-left">
          <span class="sr-loupe__filename">{{ currentImage?.name }}</span>
          <span class="sr-loupe__index">{{ currentIndex + 1 }} / {{ images.length }}</span>
        </div>
        <button
          v-if="allowComment || commentsEnabledOwner"
          class="sr-loupe__comment-btn"
          :class="{ 'sr-loupe__comment-btn--active': hasComment }"
          type="button"
          :title="t('starrate', 'Kommentar')"
          @click="openCommentSheet"
        >
          <svg class="sr-loupe__comment-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <span class="sr-loupe__comment-label">{{ t('starrate', 'Kommentar') }}</span>
        </button>
        <div class="sr-loupe__footer-center">
          <RatingStars
            v-if="enableRatingUi"
            :model-value="currentImage?.rating ?? 0"
            :interactive="canRate"
            @change="(r) => $emit('rate', currentImage, r, undefined)"
          />
          <ColorLabel
            v-if="enableColorUi"
            :model-value="currentImage?.color ?? null"
            :interactive="canRate"
            @change="(c) => $emit('rate', currentImage, undefined, c)"
          />
        </div>
        <div v-if="enablePickUi" class="sr-loupe__footer-right">
          <!-- Pick / Reject Badges -->
          <button
            class="sr-loupe__pick-btn"
            :class="{ 'sr-loupe__pick-btn--active': currentImage?.pick === 'pick' }"
            type="button"
            :title="t('starrate', 'Pick (P)')"
            @click="$emit('rate', currentImage, undefined, undefined, currentImage?.pick === 'pick' ? 'none' : 'pick')"
          >
            <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px" aria-hidden="true"><polyline points="20 6 9 17 4 12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <button
            class="sr-loupe__pick-btn sr-loupe__pick-btn--reject"
            :class="{ 'sr-loupe__pick-btn--active': currentImage?.pick === 'reject' }"
            type="button"
            :title="t('starrate', 'Ablehnen (X)')"
            @click="$emit('rate', currentImage, undefined, undefined, currentImage?.pick === 'reject' ? 'none' : 'reject')"
          >
            <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><line x1="17" y1="7" x2="7" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>
      </div>
    </Transition>

    <!-- Kommentar Bottom Sheet (immer im DOM, CSS-only Transition) -->
    <div
      class="sr-loupe__comment-sheet-overlay"
      :class="{ 'sr-loupe__comment-sheet-overlay--open': commentSheetOpen }"
      @click.self="closeCommentSheet"
    >
      <div class="sr-loupe__comment-sheet">
        <div class="sr-loupe__comment-sheet-header">
          <span class="sr-loupe__comment-meta">
            <template v-if="commentAuthor && commentDate">
              {{ commentAuthor }} · {{ formatCommentDate(commentDate) }}
            </template>
            <template v-else>
              {{ t('starrate', 'Neuer Kommentar') }}
            </template>
          </span>
          <button class="sr-loupe__comment-close" type="button" @click="closeCommentSheet">✕</button>
        </div>

        <!-- View-Modus -->
        <div v-if="commentSheetState === 'view'" class="sr-loupe__comment-body">
          <p class="sr-loupe__comment-text">{{ commentText }}</p>
          <div class="sr-loupe__comment-actions">
            <button class="sr-loupe__comment-action" type="button" @click="commentSheetState = 'edit'; commentDraft = commentText">
              <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="sr-loupe__comment-action sr-loupe__comment-action--delete" type="button" @click="commentSheetState = 'confirm-delete'">
              <svg viewBox="0 0 24 24" fill="none" style="width:14px;height:14px" aria-hidden="true"><polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M19 6l-1 14H6L5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
        </div>

        <!-- Löschen-Bestätigung -->
        <div v-else-if="commentSheetState === 'confirm-delete'" class="sr-loupe__comment-body">
          <p class="sr-loupe__comment-text">{{ commentText }}</p>
          <div class="sr-loupe__comment-actions sr-loupe__comment-actions--confirm">
            <button class="sr-loupe__comment-btn-cancel" type="button" @click="commentSheetState = 'view'">
              {{ t('starrate', 'Abbrechen') }}
            </button>
            <button
              class="sr-loupe__comment-btn-save sr-loupe__comment-btn-save--danger"
              type="button"
              :disabled="commentSaving"
              @click="confirmDeleteComment"
            >
              {{ t('starrate', 'Ja, löschen') }}
            </button>
          </div>
        </div>

        <!-- Neu / Edit-Modus -->
        <div v-else class="sr-loupe__comment-body">
          <textarea
            v-model="commentDraft"
            class="sr-loupe__comment-textarea"
            :placeholder="t('starrate', 'Kommentar hinzufügen...')"
            rows="3"
            maxlength="2000"
          />
          <div class="sr-loupe__comment-actions sr-loupe__comment-actions--edit">
            <span v-if="commentStatus === 'ok'" class="sr-loupe__comment-status sr-loupe__comment-status--ok">✓</span>
            <span v-if="commentStatus === 'error'" class="sr-loupe__comment-status sr-loupe__comment-status--error">✗</span>
            <button
              class="sr-loupe__comment-btn-save"
              type="button"
              :disabled="commentSaving || !commentDraft.trim()"
              @click="saveComment"
            >
              {{ t('starrate', 'Speichern') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, watchEffect, onMounted, onUnmounted } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import RatingStars from './RatingStars.vue'
import ColorLabel from './ColorLabel.vue'
import {
  SLIDESHOW_INTERVALS,
  SLIDESHOW_DEFAULT_SEC,
  SLIDESHOW_GUEST_LS_KEY,
  isValidSlideshowInterval,
} from '../utils/slideshow.js'

const props = defineProps({
  images: {
    type: Array,
    default: () => [],
  },
  initialIndex: {
    type: Number,
    default: 0,
  },
  onRefreshRating: {
    type: Function,
    default: null,
  },
  previewUrlFn: {
    type: Function,
    default: null,
  },
  enableRatingUi: {
    type: Boolean,
    default: true,
  },
  enableColorUi: {
    type: Boolean,
    default: true,
  },
  enablePickUi: {
    type: Boolean,
    default: false,
  },
  allowComment: {
    type: Boolean,
    default: false,
  },
  /** Gast-Kommentar-API { save, load, remove } — null = Owner-API */
  commentApi: {
    type: Object,
    default: null,
  },
  /** Owner-Modus: Kommentare global aktiviert */
  commentsEnabledOwner: {
    type: Boolean,
    default: false,
  },
  /** Diashow-Intervall in Sekunden (User-Setting). Loupe-lokale Änderungen sind ephemer. */
  slideshowInterval: {
    type: Number,
    default: SLIDESHOW_DEFAULT_SEC,
  },
  /** Gast-Modus: localStorage-Backup statt User-Setting. */
  guestMode: {
    type: Boolean,
    default: false,
  },
  /** Download-Button anzeigen (eingeloggt immer; Gast nur wenn Share erlaubt). */
  canDownload: {
    type: Boolean,
    default: false,
  },
  /** Rating-/Color-Widgets interaktiv? Owner immer; Gast nur bei "View + Rate".
   *  View-Only-Gast bekommt read-only Sterne statt klickbarer, die nichts speichern. */
  canRate: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['rate', 'close', 'index-change', 'download'])

// ─── Zustand ──────────────────────────────────────────────────────────────────

const loupeEl      = ref(null)
const imgEl        = ref(null)
const currentIndex = ref(props.initialIndex)
const loadingPreview  = ref(true)
const previewError    = ref(false)
const actualSrc       = ref('')
let   previewRetries  = 0
let   previewRetryTimer = null
const showControls   = ref(true)
let   hideControlsTimer = null

// Zoom + Pan
const MAX_ZOOM    = 4.0
// Doppelklick-Over-Zoom: fester Faktor relativ zum Fit. Formatunabhängig (Hoch/Quer
// gleich) und auf jedem Display gleich (FHD/Tablet/4K), weil relativ zur Fit-Größe.
// Bewusst > 1 ("over zoom"): 1:1 wäre auf großen Displays oft ein Schrumpfen.
const DETAIL_ZOOM = 3.0
const zoom        = ref(1.0)  // 1.0 = fit
const isFit     = ref(true)
const panX      = ref(0)
const panY      = ref(0)
const isPanning = ref(false)
const panStart  = ref({ x: 0, y: 0, panX: 0, panY: 0 })

// Touch-Pinch
let   lastPinchDist = 0
// Gesten-Modus: einmal festgelegt, gilt für die ganze Geste (bis alle Finger weg).
// 'pinch' sobald je 2 Finger unten waren — kein Zurückwechseln, damit ein kurz
// vorausgewanderter Finger den Zoom nicht abwürgt (war der alte 30px-Abbruch-Bug).
let   gestureMode = 'none'   // 'none' | 'single' | 'pinch'
let   gestureWasPinch = false  // war die Geste je ein Pinch? → kein Tap am Ende werten
let   swipeOrigin = null
let   lastTouchEnd = 0

// Doppeltipp = zwei stehende Taps kurz hintereinander (bewegungs-gated, ortsfrei):
// ein Swipe wandert >60px und kann so nie als Tap zählen → keine Kollision.
const TAP_MAX_MOVE    = 10    // px — darüber ist es Swipe/Pan, kein Tap
const DOUBLE_TAP_MS   = 300
const DOUBLE_TAP_DIST = 40    // px Abstand der zwei Taps
let   lastTapTime = 0
let   lastTapPos  = { x: 0, y: 0 }

// Bildübergang
const transitionName = ref('slide-right')

// Diashow (Konstanten siehe utils/slideshow.js)
const playing      = ref(false)
const slideshowSec = ref(SLIDESHOW_DEFAULT_SEC)
let   slideshowTimer = null

// ─── Helpers ─────────────────────────────────────────────────────────────────

function previewUrlFor(id) {
  return props.previewUrlFn
    ? props.previewUrlFn(id)
    : generateUrl(`/apps/starrate/api/preview/${id}?width=1920&height=1200`)
}

// ─── Computed ─────────────────────────────────────────────────────────────────

const currentImage = computed(() => props.images[currentIndex.value] ?? null)

const hasPrev = computed(() => currentIndex.value > 0)
const hasNext = computed(() => currentIndex.value < props.images.length - 1)

const previewUrl = computed(() => {
  if (!currentImage.value) return ''
  return previewUrlFor(currentImage.value.id)
})

const zoomLabel = computed(() => {
  if (isFit.value) return t('starrate', 'Eingepasst')
  return `${Math.round(zoom.value * 100)}%`
})

const imgStyle = computed(() => {
  if (isFit.value) {
    return {
      transform: 'translate(-50%, -50%) scale(1)',
      cursor: 'default',
    }
  }
  return {
    transform: `translate(calc(-50% + ${panX.value}px), calc(-50% + ${panY.value}px)) scale(${zoom.value})`,
    cursor: isPanning.value ? 'grabbing' : (zoom.value > 1 ? 'grab' : 'default'),
    transformOrigin: 'center center',
  }
})

// ─── Navigation ───────────────────────────────────────────────────────────────

function navigate(delta) {
  const newIdx = currentIndex.value + delta
  if (newIdx < 0 || newIdx >= props.images.length) return

  transitionName.value = delta > 0 ? 'slide-left' : 'slide-right'
  currentIndex.value   = newIdx
  resetZoom()
  emit('index-change', newIdx)

  // Rating vom Server nachladen (Sync-Light für Multi-User)
  props.onRefreshRating?.(props.images[newIdx])
}

// ─── Diashow ─────────────────────────────────────────────────────────────────

function clearSlideshowTimer() {
  if (slideshowTimer) {
    clearTimeout(slideshowTimer)
    slideshowTimer = null
  }
}

function scheduleNext() {
  clearSlideshowTimer()
  if (!playing.value) return
  if (props.images.length === 0) {
    playing.value = false
    return
  }
  slideshowTimer = setTimeout(() => {
    if (!playing.value) return
    if (currentIndex.value < props.images.length - 1) {
      navigate(1)
    } else {
      // Loop zurück zum ersten Bild — Slideshow läuft endlos.
      transitionName.value = 'slide-left'
      currentIndex.value   = 0
      resetZoom()
      emit('index-change', 0)
      props.onRefreshRating?.(props.images[0])
    }
  }, slideshowSec.value * 1000)
}

function togglePlay() {
  // Bei offenem Comment-Sheet kein Toggle (Sicherheitsnetz neben dem Sheet-Guard
  // in onKeydown — schützt z.B. Click auf Play während Sheet offen ist).
  if (commentSheetOpen.value) return
  playing.value = !playing.value
}

function changeInterval(sec) {
  if (!isValidSlideshowInterval(sec)) return
  slideshowSec.value = sec
  if (props.guestMode) {
    try { localStorage.setItem(SLIDESHOW_GUEST_LS_KEY, String(sec)) } catch {}
  }
}

watch(playing, (v) => {
  if (v) scheduleNext()
  else   clearSlideshowTimer()
})

watch(currentIndex, () => {
  if (playing.value) scheduleNext()
})

watch(slideshowSec, () => {
  if (playing.value) scheduleNext()
})

// Owner-Modus: Prop ist single source of truth — watchEffect spiegelt
// Initial- und Update-Pfad konsistent. Gäste werden im onMounted aus
// localStorage initialisiert; ein Prop-Update kann sie nicht mehr überschreiben.
watchEffect(() => {
  if (props.guestMode) return
  if (isValidSlideshowInterval(props.slideshowInterval)) {
    slideshowSec.value = props.slideshowInterval
  }
})

// Tab-Visibility: pausiert die Slideshow wenn der Tab in den Hintergrund geht.
// Browser throtteln setTimeout im Hintergrund (Chrome ab 1 min hart) — ohne
// Pause käme bei Rückkehr eine Bilderlawine.
function onVisibilityChange() {
  if (document.hidden && playing.value) playing.value = false
}

const preloadedUrls = new Set()

function preloadAdjacent(idx) {
  [-1, 1].forEach(d => {
    const img = props.images[idx + d]
    if (img) {
      const url = previewUrlFor(img.id)
      if (!preloadedUrls.has(url)) {
        const preImg = new Image()
        preImg.onload = /* c8 ignore next */ () => preloadedUrls.add(url)
        preImg.src = url
      }
    }
  })
}

// ─── Zoom ─────────────────────────────────────────────────────────────────────

function setZoom(newZoom, pivot = null) {
  newZoom = Math.min(MAX_ZOOM, newZoom)
  // Fit (zoom = 1.0) ist die Untergrenze: kleiner als eingepasst gibt es nicht.
  // Jedes Rauszoomen, das darunter ginge, schnappt auf Fit — zentral für Wheel,
  // Tastatur und Pinch, damit Rein-/Rauszoomen symmetrisch an derselben Grenze endet.
  if (newZoom <= 1.0) {
    resetZoom()
    return
  }
  isFit.value = false

  if (pivot && loupeEl.value) {
    // Punkt unter dem Cursor bleibt fix. Reine Screen-Space-Geometrie (Transform =
    // translate(pan) ∘ scale um die Container-Mitte), daher ohne echte Bildmaße:
    // pan' = d − ratio·(d − pan), mit d = Cursor relativ zur Container-Mitte.
    const rect    = loupeEl.value.getBoundingClientRect()
    const dx      = pivot.x - rect.left - rect.width  / 2
    const dy      = pivot.y - rect.top  - rect.height / 2
    const ratio   = newZoom / zoom.value
    panX.value    = dx - ratio * (dx - panX.value)
    panY.value    = dy - ratio * (dy - panY.value)
  }

  zoom.value = newZoom
  constrainPan()
  resetControlsTimer()   // jeder Zoom-Wechsel (Wheel, Pinch, Tastatur) armiert frisch
}

function resetZoom() {
  isFit.value = true   // zuerst setzen damit constrainPan() überspringt
  zoom.value  = 1.0
  panX.value  = 0
  panY.value  = 0
  resetControlsTimer()   // Fit → sichtbar, kein Hide-Timer (siehe resetControlsTimer)
}

function zoomToDetail(pivot = null) {
  // Fester Over-Zoom relativ zum Fit — identisch für Hoch- und Querformat und
  // über alle Displays. setZoom erledigt Clamp, Pivot, Pan-Constrain und Controls.
  setZoom(DETAIL_ZOOM, pivot)
}

function constrainPan() {
  if (isFit.value || !loupeEl.value || !imgEl.value) return

  const containerW = loupeEl.value.offsetWidth
  const containerH = loupeEl.value.offsetHeight
  const nw         = imgEl.value.naturalWidth  || containerW
  const nh         = imgEl.value.naturalHeight || containerH

  // Im Fit ist das Bild via object-fit:contain skaliert; angezeigt wird
  // nw·fitScale × nh·fitScale. Pan-Grenzen gegen DIESE Größe rechnen (× zoom),
  // nicht gegen die rohen Naturalmaße — sonst driftet Pan, v.a. beim Rauszoomen.
  const fitScale = Math.min(containerW / nw, containerH / nh)
  const imgW     = nw * fitScale * zoom.value
  const imgH     = nh * fitScale * zoom.value

  const maxPanX = Math.max(0, (imgW - containerW) / 2)
  const maxPanY = Math.max(0, (imgH - containerH) / 2)

  panX.value = Math.max(-maxPanX, Math.min(maxPanX, panX.value))
  panY.value = Math.max(-maxPanY, Math.min(maxPanY, panY.value))
}

// ─── Events: Maus ────────────────────────────────────────────────────────────

function onWheel(e) {
  // Floor (Fit) und Ceiling (MAX_ZOOM) regelt setZoom zentral.
  const delta = e.deltaY > 0 ? 0.85 : 1 / 0.85
  setZoom(zoom.value * delta, { x: e.clientX, y: e.clientY })
}

function onDblClick(e) {
  e.preventDefault()
  // Ignore dblclick from touch — fast swiping can trigger accidental double-taps
  if (Date.now() - lastTouchEnd < 500) return
  // Nur auf dem Bild zoomen, nicht auf Controls: schnelles Doppelklicken der
  // Nav-Pfeile (@click.stop stoppt nur click, nicht dblclick) löste sonst Zoom aus.
  if (e.target.closest('button')) return

  // Toggle: Fit → Over-Zoom auf die Klickposition, sonst zurück auf Fit.
  if (isFit.value) {
    zoomToDetail({ x: e.clientX, y: e.clientY })
  } else {
    resetZoom()
  }
}

function onMouseDown(e) {
  if (e.button !== 0) return
  if (isFit.value) return   // im Fit (= Zoom-Untergrenze) gibt es nichts zu pannen

  startPan(e.clientX, e.clientY)
  e.preventDefault()
}

function onMouseMove(e) {
  if (!isPanning.value) return
  panTo(e.clientX, e.clientY)
}

function onMouseUp() {
  isPanning.value = false
}

// ─── Events: Touch (Swipe + Pinch) ───────────────────────────────────────────

function startPan(clientX, clientY) {
  isPanning.value = true
  panStart.value  = { x: clientX, y: clientY, panX: panX.value, panY: panY.value }
}

// Pan-Schritt — von Maus (onMouseMove) und Ein-Finger-Touch (onTouchMove) geteilt.
function panTo(clientX, clientY) {
  panX.value = panStart.value.panX + (clientX - panStart.value.x)
  panY.value = panStart.value.panY + (clientY - panStart.value.y)
  constrainPan()
  resetControlsTimer()   // Pan hält die Leiste sichtbar und re-armt den Hide-Timer
}

function onTouchStart(e) {
  const cur = Array.from(e.touches)
  resetControlsTimer()   // jede Berührung holt die Leiste zurück (Touch-Parität zur Maus)

  if (cur.length >= 2) {
    // Zwei Finger unten → für den Rest der Geste Pinch-Modus. Kein Distanz-Check:
    // wer einen zweiten Finger aufsetzt, will zoomen. Ein versehentlicher Zoom ist
    // jederzeit zurückzoombar — das ist robuster als die alte Abbruch-Heuristik.
    gestureMode    = 'pinch'
    gestureWasPinch = true
    isPanning.value = false
    lastPinchDist  = getPinchDist(cur)
  } else if (cur.length === 1 && gestureMode !== 'pinch') {
    gestureMode = 'single'
    gestureWasPinch = false
    swipeOrigin = { x: cur[0].clientX, y: cur[0].clientY }
    if (!isFit.value) startPan(cur[0].clientX, cur[0].clientY)
  }
}

function onTouchMove(e) {
  const currentTouches = Array.from(e.touches)

  if (gestureMode === 'pinch' && currentTouches.length >= 2) {
    // Pinch-to-Zoom — auf den Finger-Mittelpunkt als Pivot, damit das Bild dort
    // wächst, wo die Finger sind (nicht um die Bildmitte).
    isPanning.value = false
    const dist  = getPinchDist(currentTouches)
    const ratio = dist / lastPinchDist
    // lastPinchDist > 0 schützt vor ratio = Infinity, wenn zwei Touch-Punkte
    // beim Start zusammenfallen (Handballen, grober Digitizer) → kein Sprung auf MAX.
    if (lastPinchDist > 0 && Math.abs(ratio - 1) > 0.01) {
      setZoom(zoom.value * ratio, pinchMidpoint(currentTouches))
      lastPinchDist = dist
    }
  } else if (gestureMode === 'single' && currentTouches.length === 1 && isPanning.value) {
    panTo(currentTouches[0].clientX, currentTouches[0].clientY)
  }
}

function onTouchEnd(e) {
  const remaining = Array.from(e.touches)

  if (remaining.length === 0) {
    // Alle Finger weg — Geste auswerten (Tap/Doppeltipp vs. Swipe).
    const t1 = e.changedTouches[0]
    if (gestureMode === 'single' && swipeOrigin && t1) {
      const dx    = t1.clientX - swipeOrigin.x
      const dy    = t1.clientY - swipeOrigin.y
      const moved = Math.hypot(dx, dy)

      if (!gestureWasPinch && moved < TAP_MAX_MOVE && !e.target.closest('button')) {
        // Stehender Tap auf dem Bild (nicht auf einem Control wie dem Nav-Pfeil)
        // → Doppeltipp-Erkennung (ortsfrei, bewegungs-gated).
        const now  = Date.now()
        const near = Math.hypot(t1.clientX - lastTapPos.x, t1.clientY - lastTapPos.y) < DOUBLE_TAP_DIST
        if (now - lastTapTime < DOUBLE_TAP_MS && near) {
          // Wie Desktop-Doppelklick: Fit ⇄ Over-Zoom auf den Tipppunkt.
          if (isFit.value) zoomToDetail({ x: t1.clientX, y: t1.clientY })
          else resetZoom()
          lastTapTime = 0   // verbraucht, kein Triple-Tap-Doppelauslösen
        } else {
          lastTapTime = now
          lastTapPos  = { x: t1.clientX, y: t1.clientY }
        }
      } else if (isFit.value && Math.abs(dx) > 60 && Math.abs(dy) < 80) {
        navigate(dx < 0 ? 1 : -1)
      }
    }
    isPanning.value = false
    gestureMode     = 'none'
    swipeOrigin     = null
    lastTouchEnd    = Date.now()
  } else if (remaining.length === 1 && !isFit.value) {
    // Pinch endet, ein Finger bleibt → in Ein-Finger-Pan übergehen, damit
    // "pinch, dann mit einem Finger ziehen" funktioniert. panStart fängt die
    // aktuelle Fingerposition, daher kein Sprung. Kein Swipe (nicht im Fit).
    gestureMode = 'single'
    swipeOrigin = { x: remaining[0].clientX, y: remaining[0].clientY }
    startPan(remaining[0].clientX, remaining[0].clientY)
  } else {
    isPanning.value = false
  }
}

function getPinchDist(ts) {
  const dx = ts[0].clientX - ts[1].clientX
  const dy = ts[0].clientY - ts[1].clientY
  return Math.sqrt(dx * dx + dy * dy)
}

function pinchMidpoint(ts) {
  return {
    x: (ts[0].clientX + ts[1].clientX) / 2,
    y: (ts[0].clientY + ts[1].clientY) / 2,
  }
}

// ─── Tastatur ────────────────────────────────────────────────────────────────

function onKeydown(e) {
  // ESC schließt das Kommentar-Sheet zuerst
  if (e.key === 'Escape' && commentSheetOpen.value) {
    e.stopPropagation()
    e.preventDefault()
    closeCommentSheet()
    return
  }

  // Bei offenem Comment-Sheet alle Shortcuts deaktivieren — schützt nicht
  // nur die Textarea-Eingabe (TEXTAREA-Guard greift dafür ohnehin), sondern
  // auch Klicks außerhalb des Textfelds bei offenem Sheet.
  if (commentSheetOpen.value) return

  // Eingabefelder in Ruhe lassen
  const tag = document.activeElement?.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return

  switch (e.key) {
    case 'ArrowLeft':
      e.preventDefault()
      navigate(-1)
      break
    case 'ArrowRight':
      e.preventDefault()
      navigate(1)
      break
    case 'Home':
      e.preventDefault()
      navigate(-currentIndex.value)
      break
    case 'End':
      e.preventDefault()
      navigate(props.images.length - 1 - currentIndex.value)
      break
    case 'Escape':
      e.preventDefault()
      if (!isFit.value) { resetZoom() } else { emit('close') }
      break

    // Zoom
    case '+':
    case '=':
      e.preventDefault()
      setZoom(zoom.value * 1.25)
      break
    case '-':
    case '_':
      e.preventDefault()
      setZoom(zoom.value * 0.8)   // floort zentral auf Fit
      break
    case ' ':
      e.preventDefault()
      resetZoom()
      break

    case 's': case 'S':
      e.preventDefault()
      togglePlay()
      break

    // Bewertungen
    case '0': case '1': case '2':
    case '3': case '4': case '5':
      if (props.enableRatingUi && !e.ctrlKey && !e.metaKey) {
        e.preventDefault()
        emit('rate', currentImage.value, parseInt(e.key), undefined)
      }
      break

    case '6': case '7': case '8': case '9': {
      if (!props.enableColorUi) break
      e.preventDefault()
      const colorMap = { '6': 'Red', '7': 'Yellow', '8': 'Green', '9': 'Blue' }
      const img = currentImage.value
      if (img) {
        const c = colorMap[e.key]
        emit('rate', img, undefined, img.color === c ? null : c)
      }
      break
    }
    case 'v': case 'V': {
      if (!props.enableColorUi) break
      e.preventDefault()
      const img = currentImage.value
      if (img) emit('rate', img, undefined, img.color === 'Purple' ? null : 'Purple')
      break
    }

    case 'p': case 'P': {
      if (!props.enablePickUi) break
      e.preventDefault()
      const img = currentImage.value
      if (img) emit('rate', img, undefined, undefined, img.pick === 'pick' ? 'none' : 'pick')
      break
    }
    case 'x': case 'X': {
      if (!props.enablePickUi) break
      e.preventDefault()
      const img = currentImage.value
      if (img) emit('rate', img, undefined, undefined, img.pick === 'reject' ? 'none' : 'reject')
      break
    }
  }

  resetControlsTimer()
}

// ─── Steuerelemente automatisch ausblenden ────────────────────────────────────

function resetControlsTimer() {
  showControls.value = true
  clearTimeout(hideControlsTimer)
  // Im Fit-Modus bleibt die Leiste immer sichtbar — gar kein Hide-Timer.
  // Nur wenn reingezoomt ist, nach 3s ausblenden (Bild nicht verdecken).
  // Wird aus jedem Zoom-Wechsel und jeder Touch-Interaktion neu armiert, daher
  // ist die Sichtbarkeit eine reine Funktion des Zustands — kein One-Shot-Race.
  if (isFit.value) return
  hideControlsTimer = setTimeout(() => {
    if (isPanning.value) return  // mitten im Pan: sichtbar lassen, re-armt beim nächsten Move
    showControls.value = false
  }, 3000)
}

function onImgLoad() {
  loadingPreview.value = false
  previewError.value   = false
  previewRetries       = 0
  resetControlsTimer()
  // Fallback-Pfad: Preview lädt direkt im <img>, hier ist "image ready" =
  // "preview ready" → Nachbarn preloaden.
  if (actualSrc.value === previewUrl.value) {
    preloadAdjacent(currentIndex.value)
  }
}

function onImgError() {
  previewRetries++
  if (previewRetries < 3) {
    // NC generiert Previews beim ersten Zugriff lazy — nach kurzer Pause nochmals versuchen
    loadingPreview.value = true
    clearTimeout(previewRetryTimer)
    previewRetryTimer = setTimeout(() => {
      // Cache-Buster erzwingt neuen Request (Browser würde sonst gecachte 404 nehmen)
      actualSrc.value = previewUrl.value + (previewUrl.value.includes('?') ? '&' : '?') + '_r=' + previewRetries
    }, previewRetries * 1000)
  } else {
    loadingPreview.value = false
    previewError.value   = true
  }
}

watch(previewUrl, (url) => {
  previewError.value   = false
  previewRetries       = 0
  clearTimeout(previewRetryTimer)

  if (preloadedUrls.has(url)) {
    // Preview already cached by preloadAdjacent — show directly
    actualSrc.value      = url
    loadingPreview.value = false
    // Preload neighbors now that current preview is ready — avoids bandwidth contention
    preloadAdjacent(currentIndex.value)
    return
  }

  // Show thumbnail instantly as placeholder (already in browser cache from grid).
  // Aspect-Mismatch zwischen cover-gecropptem Thumb und Preview erzeugt beim
  // ersten Grid→Loupe-Wechsel einen 2-5% Größensprung — bewusst akzeptiert,
  // weil sofort etwas Sichtbares auf dem Screen ist. Bei großen Bildern, wo
  // die Preview-Generierung serverseitig dauern kann, ist das deutlich
  // wertvoller als ein Spinner-leerer Stage.
  const thumb = currentImage.value?.thumbUrl
  if (thumb) {
    actualSrc.value      = thumb
    loadingPreview.value = true
  } else {
    actualSrc.value      = url
    loadingPreview.value = true
    return
  }

  // Load full preview in background, swap on load
  const img = new Image()
  img.onload = () => {
    preloadedUrls.add(url)
    if (previewUrl.value === url) {
      requestAnimationFrame(() => {
        if (previewUrl.value === url) {
          actualSrc.value      = url
          loadingPreview.value = false
          preloadAdjacent(currentIndex.value)
        }
      })
    }
  }
  img.onerror = () => {
    if (previewUrl.value === url) {
      actualSrc.value = url
    }
  }
  img.src = url
}, { immediate: true })

// ─── Kommentar-Sheet ──────────────────────────────────────────────────────────
// States: 'closed' | 'new' | 'view' | 'edit' | 'confirm-delete'

const commentSheetState = ref('closed')
const commentText       = ref('')
const commentDraft      = ref('')
const commentAuthor     = ref('')
const commentDate       = ref(0)
const commentSaving     = ref(false)
const commentStatus     = ref('')   // '' | 'ok' | 'error'
const commentLoaded     = ref(false)
let   commentStatusTimer   = null
let   _commentLoadPromise  = null   // Promise-Lock gegen parallele Loads

const hasComment = computed(() => commentText.value !== '')
const commentSheetOpen = computed(() => commentSheetState.value !== 'closed')

function formatCommentDate(ts) {
  if (!ts) return ''
  const d = new Date(ts * 1000)
  return d.toLocaleDateString('de-DE') + ' ' + d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}

function loadComment(fileId) {
  if (!fileId) return Promise.resolve()
  if (commentLoaded.value) return Promise.resolve()
  // Schon am Laden? Bestehenden Promise zurückgeben statt zweiten Request zu starten
  if (_commentLoadPromise) return _commentLoadPromise

  _commentLoadPromise = (async () => {
    commentText.value   = ''
    commentAuthor.value = ''
    commentDate.value   = 0
    try {
      let result = null
      if (props.commentApi) {
        result = await props.commentApi.load(fileId)
      } else if (props.commentsEnabledOwner) {
        const url = generateUrl(`/apps/starrate/api/rating/${fileId}/comment`)
        const { data } = await axios.get(url)
        result = data ?? null
      }
      if (result && typeof result === 'object') {
        commentText.value   = result.comment    ?? ''
        commentAuthor.value = result.author_name ?? ''
        commentDate.value   = result.updated_at  ?? 0
      } else if (typeof result === 'string') {
        commentText.value = result
      }
    } catch { /* ignore */ } finally {
      commentLoaded.value  = true
      _commentLoadPromise  = null
    }
  })()

  return _commentLoadPromise
}

async function openCommentSheet() {
  // Sheet öffnen pausiert die Slideshow — sonst wechselt das Bild unter dem
  // offenen Sheet weg und der User kommentiert das falsche Bild.
  playing.value = false
  commentStatus.value = ''
  // Sicherstellen dass Kommentar geladen ist (kein Doppel-Request dank commentLoaded-Flag)
  if (!commentLoaded.value) {
    await loadComment(currentImage.value?.id)
  }
  if (hasComment.value) {
    commentSheetState.value = 'view'
    commentDraft.value      = commentText.value
  } else {
    commentSheetState.value = 'new'
    commentDraft.value      = ''
    // Focus erst nach CSS-Transition (200ms) um Reflow-Jitter zu vermeiden
    setTimeout(() => document.querySelector('.sr-loupe__comment-textarea')?.focus(), 250)
  }
}

function closeCommentSheet() {
  commentSheetState.value = 'closed'
  commentStatus.value     = ''
}

async function saveComment() {
  const fileId = currentImage.value?.id
  if (!fileId || !commentDraft.value.trim()) return
  commentSaving.value = true
  commentStatus.value = ''
  try {
    if (props.commentApi) {
      const result = await props.commentApi.save(fileId, commentDraft.value.trim())
      commentText.value   = result.comment      ?? commentDraft.value.trim()
      commentAuthor.value = result.author_name  ?? ''
      commentDate.value   = result.updated_at   ?? Math.floor(Date.now() / 1000)
    } else {
      const url = generateUrl(`/apps/starrate/api/rating/${fileId}/comment`)
      const { data } = await axios.post(url, { comment: commentDraft.value.trim() })
      commentText.value   = data.comment     ?? commentDraft.value.trim()
      commentAuthor.value = data.author_name ?? ''
      commentDate.value   = data.updated_at  ?? Math.floor(Date.now() / 1000)
    }
    commentSheetState.value = 'view'
    commentStatus.value     = 'ok'
    clearTimeout(commentStatusTimer)
    commentStatusTimer = setTimeout(() => { commentStatus.value = '' }, 2500)
  } catch {
    commentStatus.value = 'error'
  } finally {
    commentSaving.value = false
  }
}

async function confirmDeleteComment() {
  const fileId = currentImage.value?.id
  if (!fileId) return
  commentSaving.value = true
  try {
    if (props.commentApi) {
      await props.commentApi.remove(fileId)
    } else {
      const url = generateUrl(`/apps/starrate/api/rating/${fileId}/comment`)
      await axios.delete(url)
    }
    commentText.value       = ''
    commentAuthor.value     = ''
    commentDate.value       = 0
    commentSheetState.value = 'closed'
  } catch {
    commentStatus.value = 'error'
    commentSheetState.value = 'view'
  } finally {
    commentSaving.value = false
  }
}

// Kommentar laden wenn Bild wechselt (kein immediate — verhindert Jiggling beim Mount)
watch(currentImage, (img) => {
  if (props.allowComment || props.commentsEnabledOwner) {
    closeCommentSheet()
    commentLoaded.value  = false
    _commentLoadPromise  = null
    loadComment(img?.id)
  }
})

// ─── Mount / Unmount ──────────────────────────────────────────────────────────

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  document.addEventListener('visibilitychange', onVisibilityChange)
  resetControlsTimer()
  // Kommentar für das erste Bild laden (ohne Watch-immediate um Jiggling zu vermeiden)
  if (props.allowComment || props.commentsEnabledOwner) {
    loadComment(currentImage.value?.id)
  }
  // Self-healing XMP-Read auch beim ersten Loupe-Open auslösen — Pfeil-Navigation
  // triggert via navigate(), aber der Initial-Mount fehlte sonst.
  props.onRefreshRating?.(currentImage.value)
  // Gast-Modus: localStorage-Backup für Slideshow-Intervall einlesen.
  // Im Owner-Modus übernimmt das der watchEffect auf props.slideshowInterval.
  if (props.guestMode) {
    try {
      const v = parseInt(localStorage.getItem(SLIDESHOW_GUEST_LS_KEY) || '', 10)
      if (isValidSlideshowInterval(v)) slideshowSec.value = v
    } catch {}
  }
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  clearTimeout(hideControlsTimer)
  clearTimeout(previewRetryTimer)
  clearTimeout(commentStatusTimer)
  clearSlideshowTimer()
})

watch(() => props.initialIndex, idx => {
  currentIndex.value = idx
})
</script>

<style scoped>
.sr-loupe {
  position: relative;
  width: 100%;
  height: 100%;
  background: #000;
  overflow: hidden;
  outline: none;
  display: flex;
  align-items: center;
  justify-content: center;
  /* Browser-Touch-Handling komplett deaktivieren — Pinch-Zoom, Pan und
     Swipe-Navigation laufen alle in JS (touchstart/move/end-Handler).
     Wichtig auf Chrome Android: ohne `none` interpretiert der Browser eine
     Pinch-Geste, bei der der untere Finger zuerst registriert wird, häufig
     fälschlich als Pull-to-Refresh und lädt die Seite neu, statt zu zoomen.
     touch-action:none unterbindet alle Browser-Default-Gesten (Scroll, Pinch,
     Pull-to-Refresh, Double-Tap-Zoom) auf dem Loupe-Container. */
  touch-action: none;
}

/* ── Stage & Bild ─────────────────────────────────────────────────────────── */
.sr-loupe__stage {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sr-loupe__img {
  position: absolute;
  top: 50%;
  left: 50%;
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  will-change: transform;
  user-select: none;
  -webkit-user-drag: none;
}

.sr-loupe__placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}

.sr-loupe__placeholder svg {
  width: 80px;
  height: 80px;
}

/* ── Lade-Spinner ─────────────────────────────────────────────────────────── */
.sr-loupe__loading {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 5;
}

.sr-loupe__spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(255,255,255,0.1);
  border-top-color: #e94560;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ── Zoom + Schließen (oben rechts) ──────────────────────────────────────── */
.sr-loupe__top-right {
  position: absolute;
  top: 10px;
  right: 10px;
  display: flex;
  align-items: center;
  gap: 6px;
  z-index: 10;
}

.sr-loupe__zoom-level {
  padding: 4px 10px;
  background: rgba(0,0,0,0.6);
  color: #aaa;
  font-size: 12px;
  border-radius: 4px;
  pointer-events: none;
  backdrop-filter: blur(4px);
  white-space: nowrap;
}

.sr-loupe__close {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.6);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 6px;
  color: #aaa;
  cursor: pointer;
  flex-shrink: 0;
  transition: background 150ms, color 150ms;
  backdrop-filter: blur(4px);
}

@media (pointer: fine) {
  .sr-loupe__close:hover {
    background: rgba(233,69,96,0.7);
    color: #fff;
  }
}

.sr-loupe__download {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.6);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 6px;
  color: #aaa;
  cursor: pointer;
  flex-shrink: 0;
  margin-right: 4px;            /* Extra-Abstand zur Zoom/Close-Gruppe gegen Fehl-Taps */
  transition: background 150ms, color 150ms;
  backdrop-filter: blur(4px);
}

.sr-loupe__download svg { width: 18px; height: 18px; }

@media (pointer: fine) {
  .sr-loupe__download:hover {
    background: rgba(255,255,255,0.15);
    color: #fff;
  }
}

.sr-loupe__close svg { width: 16px; height: 16px; }

/* ── Zurück-Button ────────────────────────────────────────────────────────── */
.sr-loupe__back {
  position: absolute;
  top: 10px;
  left: 10px;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.6);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 6px;
  color: #aaa;
  cursor: pointer;
  z-index: 10;
  transition: background 150ms, color 150ms;
  backdrop-filter: blur(4px);
}

@media (pointer: fine) {
  .sr-loupe__back:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
  }
}

.sr-loupe__back svg { width: 20px; height: 20px; }

/* ── Navigations-Pfeile ───────────────────────────────────────────────────── */
.sr-loupe__nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 44px;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.5);
  border: 1px solid rgba(255,255,255,0.08);
  color: #aaa;
  cursor: pointer;
  z-index: 10;
  opacity: 0;
  transition: opacity 200ms, background 150ms, color 150ms;
  backdrop-filter: blur(4px);
}

/* Desktop: Nav bei Hover einblenden */
@media (pointer: fine) {
  .sr-loupe:hover .sr-loupe__nav {
    opacity: 1;
  }
  .sr-loupe__nav:hover {
    background: rgba(233,69,96,0.7);
    color: #fff;
  }
}

/* Mobile: Nav immer sichtbar (kein Hover zum Einblenden) */
@media (pointer: coarse) {
  .sr-loupe__nav {
    opacity: 1;
  }
}

.sr-loupe__nav--prev { left: 0;  border-radius: 0 6px 6px 0; }
.sr-loupe__nav--next { right: 0; border-radius: 6px 0 0 6px; }
.sr-loupe__nav svg { width: 24px; height: 24px; }

/* ── Footer ───────────────────────────────────────────────────────────────── */
.sr-loupe__footer {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 16px 20px 20px;
  background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, transparent 100%);
  display: flex;
  align-items: center;
  gap: 16px;
  z-index: 10;
}

.sr-loupe__footer-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.sr-loupe__filename {
  font-size: 13px;
  color: #ddd;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sr-loupe__index {
  font-size: 11px;
  color: #666;
}

.sr-loupe__footer-center {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  flex: 1;
}

.sr-loupe__footer-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

/* ── Diashow Footer-Block ─────────────────────────────────────────────────── */
.sr-loupe__footer-slideshow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.sr-loupe__slideshow-btn {
  background: transparent;
  border: none;
  color: #a8a8b8;
  cursor: pointer;
  padding: 0 4px;
  display: flex;
  align-items: center;
  transition: color 150ms;
}

.sr-loupe__slideshow-btn:hover         { color: #d4d4d8; }
.sr-loupe__slideshow-btn--active       { color: #d4d4d8; }
.sr-loupe__slideshow-icon              { width: 22px; height: 22px; }

.sr-loupe__slideshow-select {
  appearance: none;
  -webkit-appearance: none;
  background-color: rgba(0, 0, 0, 0.5);
  background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='%23bbbbbb' d='M0 0l5 6 5-6z'/></svg>");
  background-repeat: no-repeat;
  background-position: right 6px center;
  color: #d4d4d8;
  border: 1px solid #44446a;
  border-radius: 4px;
  padding: 2px 18px 2px 6px;
  font-size: 12px;
  outline: none;
  cursor: pointer;
}
.sr-loupe__slideshow-select:focus      { border-color: #e94560; }

/* Desktop: Slideshow-Block direkt links neben Comment-Button (in der Mitte) */
@media (pointer: fine) {
  .sr-loupe__footer-left      { order: 1; }
  .sr-loupe__footer-slideshow { order: 2; }
  .sr-loupe__comment-btn      { order: 3; }
  .sr-loupe__footer-center    { order: 4; }
  .sr-loupe__footer-right     { order: 5; }
}

.sr-loupe__pick-btn {
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #444;
  border-radius: 4px;
  background: transparent;
  color: #888;
  cursor: pointer;
  transition: background 150ms, color 150ms, border-color 150ms;
}

@media (pointer: fine) {
  .sr-loupe__pick-btn:hover {
    border-color: #888;
    color: #ddd;
  }
}

.sr-loupe__pick-btn--active {
  background: #2a4a2a;
  border-color: #52a852;
  color: #7ecf7e;
}

.sr-loupe__pick-btn--reject.sr-loupe__pick-btn--active {
  background: #4a1a1a;
  border-color: #e05252;
  color: #e05252;
}

/* ── Übergänge ────────────────────────────────────────────────────────────── */
.slide-left-enter-active,
.slide-right-enter-active {
  transition: transform 200ms ease, opacity 200ms ease;
  position: absolute;
  inset: 0;
}

.slide-left-leave-active,
.slide-right-leave-active {
  transition: opacity 80ms ease;
  position: absolute;
  inset: 0;
}

.slide-left-enter-from  { transform: translateX( 40px); opacity: 0; }
.slide-left-leave-to    { transform: translateX(-40px); opacity: 0; }
.slide-right-enter-from { transform: translateX(-40px); opacity: 0; }
.slide-right-leave-to   { transform: translateX( 40px); opacity: 0; }

.slide-up-enter-active,
.slide-up-leave-active {
  transition: opacity 200ms ease, transform 200ms ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  opacity: 0;
  transform: translateY(10px);
}

.fade-enter-active,
.fade-leave-active { transition: opacity 200ms; }
.fade-enter-from,
.fade-leave-to     { opacity: 0; }

/* X-Buttons im Footer ausblenden: Nochmals klicken entfernt Bewertung/Farbe */
.sr-loupe__footer :deep(.sr-stars__clear),
.sr-loupe__footer :deep(.sr-color-label__clear) {
  display: none;
}

/* ── Mobile: Stage + Pfeile über Footer-Bereich zentrieren ───────────────── */
/* Footer auf Mobile: ~150px (padding 16+72 + 2 Zeilen Controls ~44+18+gap6) */
@media (pointer: coarse) {
  .sr-loupe__stage {
    bottom: 150px; /* Stage endet oberhalb des Footers → Bild zentriert sich korrekt */
  }
  .sr-loupe__nav {
    top: calc(50% - 75px); /* 150px/2 Korrektur damit Pfeile in sichtbarer Fläche liegen */
  }
}

/* ── Kommentar-Button ──────────────────────────────────────────────────────── */

.sr-loupe__comment-btn {
  background: none;
  border: none;
  color: #52525b;
  cursor: pointer;
  padding: 0 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  align-self: center;
  flex-shrink: 0;
  transition: color 0.15s;
}
.sr-loupe__comment-icon { width: 16px; height: 16px; }
.sr-loupe__comment-label {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.02em;
}
.sr-loupe__comment-btn--active { color: #e94560; }
.sr-loupe__comment-btn:hover   { color: #d4d4d8; }

/* ── Mobile: zweizeiliger Footer + Android-Navigationsleiste ─────────────── */
@media (pointer: coarse) {
  .sr-loupe__footer {
    padding-bottom: max(72px, env(safe-area-inset-bottom));
    flex-wrap: wrap;
    justify-content: center;
    gap: 2px 12px;
  }
  /* Zeile 1: Steuerelemente */
  .sr-loupe__footer-center { order: 1; flex: 0 0 auto; justify-content: center; }
  .sr-loupe__footer-right  { order: 1; }
  /* Zeilenumbruch zwischen Zeile 1 und 2 */
  .sr-loupe__footer::after {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }
  /* Zeile 2: Dateiname + Kommentar-Button, zentriert */
  .sr-loupe__footer-left {
    order: 2;
    flex: 0 1 auto;
    align-items: center;
    text-align: center;
  }
  .sr-loupe__comment-btn {
    order: 2;
    align-self: center;
    padding: 0 10px;
    margin: 0 0 0 14px;
  }
  .sr-loupe__comment-icon { width: 26px; height: 26px; }
  .sr-loupe__comment-label { display: none; }
  /* Diashow als dritter Block in Zeile 2, ganz links: [Play] [File] [Comment] */
  .sr-loupe__footer-slideshow {
    order: 2;
    align-self: center;
  }
  .sr-loupe__slideshow-icon { width: 26px; height: 26px; }
  .sr-loupe__slideshow-select { font-size: 13px; padding: 3px 6px; }
}

/* ── Kommentar Bottom Sheet ────────────────────────────────────────────────── */

.sr-loupe__comment-sheet-overlay {
  position: absolute;
  inset: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  transform: translateY(100%);
  opacity: 0;
  pointer-events: none;
  will-change: transform, opacity;
  transition: transform 0.2s ease-out, opacity 0.2s ease-out;
}
.sr-loupe__comment-sheet-overlay--open {
  transform: translateY(0);
  opacity: 1;
  pointer-events: auto;
}

.sr-loupe__comment-sheet {
  background: #1a1a2e;
  border-top: 1px solid #2a2a4a;
  padding: 6px 16px 10px;
  max-height: 60%;
  overflow-y: auto;
}
@media (pointer: coarse) {
  .sr-loupe__comment-sheet {
    /* Platz für Android-Navigationsleiste (~56px) */
    padding-bottom: max(72px, env(safe-area-inset-bottom, 72px));
  }
}

.sr-loupe__comment-sheet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2px;
}
.sr-loupe__comment-meta {
  font-size: 11px;
  color: #71717a;
}
.sr-loupe__comment-close {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 14px;
  padding: 2px 4px;
}
.sr-loupe__comment-close:hover { color: #d4d4d8; }

.sr-loupe__comment-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  /* min-height verhindert Höhen-Sprung beim State-Wechsel new/edit → view */
  min-height: 80px;
}

.sr-loupe__comment-text {
  color: #d4d4d8;
  font-size: 14px;
  white-space: pre-wrap;
  word-break: break-word;
  margin: 0;
}

.sr-loupe__comment-textarea {
  width: 100%;
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #d4d4d8;
  font-size: 13px;
  padding: 8px;
  resize: vertical;
  box-sizing: border-box;
}
.sr-loupe__comment-textarea:focus { outline: none; border-color: #7a3050; }

.sr-loupe__comment-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}
.sr-loupe__comment-actions--confirm { justify-content: flex-end; }
.sr-loupe__comment-actions--edit    { justify-content: flex-end; }

.sr-loupe__comment-action {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  padding: 4px;
  display: inline-flex;
  align-items: center;
}
.sr-loupe__comment-action:hover         { color: #d4d4d8; }
.sr-loupe__comment-action--delete:hover { color: #e94560; }

.sr-loupe__comment-btn-save {
  background: #7a3050;
  border: none;
  border-radius: 4px;
  color: #fff;
  cursor: pointer;
  font-size: 12px;
  padding: 5px 12px;
}
.sr-loupe__comment-btn-save:hover    { background: #9a3060; }
.sr-loupe__comment-btn-save:disabled { opacity: 0.5; cursor: default; }
.sr-loupe__comment-btn-save--danger  { background: #7f1d1d; }
.sr-loupe__comment-btn-save--danger:hover { background: #991b1b; }

.sr-loupe__comment-btn-cancel {
  background: none;
  border: 1px solid #3f3f5a;
  border-radius: 4px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 12px;
  padding: 5px 12px;
}
.sr-loupe__comment-btn-cancel:hover { color: #d4d4d8; border-color: #5a5a8a; }

.sr-loupe__comment-status { font-size: 13px; }
.sr-loupe__comment-status--ok    { color: #7ecf7e; }
.sr-loupe__comment-status--error { color: #e94560; }

</style>
