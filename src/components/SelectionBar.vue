<template>
  <Teleport to="body">
    <Transition name="selection-bar">
      <div class="sr-selbar" role="toolbar" :aria-label="t('starrate', 'Stapel-Bewertung')">

        <!-- Anzahl + Schließen -->
        <div class="sr-selbar__info">
          <span class="sr-selbar__count">
            {{ n('starrate', '%n Bild ausgewählt', '%n Bilder ausgewählt', count) }}
          </span>
          <button
            class="sr-selbar__clear"
            type="button"
            :title="t('starrate', 'Auswahl aufheben (Esc)')"
            @click="$emit('clear')"
          >
            <svg viewBox="0 0 24 24" fill="none"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          </button>
        </div>

        <div v-if="enableRatingUi" class="sr-selbar__sep" />

        <!-- Sterne-Buttons -->
        <div v-if="enableRatingUi" class="sr-selbar__section" :aria-label="t('starrate', 'Bewertung setzen')">
          <span class="sr-selbar__label">{{ t('starrate', 'Bewertung:') }}</span>
          <button
            v-for="star in [0, 1, 2, 3, 4, 5]"
            :key="`star-${star}`"
            class="sr-selbar__btn sr-selbar__btn--star"
            :class="{ 'sr-selbar__btn--active': activeRating === star }"
            type="button"
            :title="star === 0 ? t('starrate', 'Bewertung entfernen') : n('starrate', '%n Stern', '%n Sterne', star)"
            @click="applyRating(star)"
          >
            <template v-if="star === 0">✕</template>
            <template v-else>{{ '★'.repeat(star) }}</template>
          </button>
        </div>

        <div v-if="enableColorUi" class="sr-selbar__sep" />

        <!-- Farb-Buttons -->
        <div v-if="enableColorUi" class="sr-selbar__section" :aria-label="t('starrate', 'Farbe setzen')">
          <span class="sr-selbar__label">{{ t('starrate', 'Farbe:') }}</span>
          <button
            v-for="color in COLORS"
            :key="color.key"
            class="sr-selbar__btn sr-selbar__btn--color"
            :class="{ 'sr-selbar__btn--active': activeColor === color.key }"
            type="button"
            :title="`${t('starrate', color.label)} (${color.shortcut})`"
            :style="{ '--dot-color': color.hex }"
            @click="applyColor(color.key)"
          >
            <span class="sr-selbar__color-dot" />
          </button>
          <!-- Farbe entfernen: leerer Kreis -->
          <button
            class="sr-selbar__btn sr-selbar__btn--color-none"
            :class="{ 'sr-selbar__btn--active': activeColor === null }"
            type="button"
            :title="t('starrate', 'Farbe entfernen')"
            @click="applyColor(null)"
          >
            <span class="sr-selbar__color-dot sr-selbar__color-dot--none" />
          </button>
        </div>

        <!-- Pick/Reject-Buttons -->
        <template v-if="enablePickUi">
          <div class="sr-selbar__sep" />
          <div class="sr-selbar__section" :aria-label="t('starrate', 'Pick/Reject setzen')">
            <span class="sr-selbar__label">{{ t('starrate', 'Pick:') }}</span>
            <button
              class="sr-selbar__btn sr-selbar__btn--pick"
              :class="{ 'sr-selbar__btn--active': activePick === 'pick' }"
              type="button"
              :title="t('starrate', 'Pick (P)')"
              @click="applyPick('pick')"
            >✓</button>
            <button
              class="sr-selbar__btn sr-selbar__btn--reject"
              :class="{ 'sr-selbar__btn--active': activePick === 'reject' }"
              type="button"
              :title="t('starrate', 'Reject (X)')"
              @click="applyPick('reject')"
            >⊘</button>
            <button
              class="sr-selbar__btn sr-selbar__btn--pick-none"
              :class="{ 'sr-selbar__btn--active': activePick === 'none' }"
              type="button"
              :title="t('starrate', 'Pick/Reject entfernen')"
              @click="applyPick('none')"
            >—</button>
          </div>
        </template>

      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { t, n } from '@nextcloud/l10n'
import { COLORS } from './ColorLabel.vue'

defineProps({
  count: {
    type: Number,
    required: true,
  },
  // Zuletzt per Batch gesetztes Rating (null = keins / nie gesetzt)
  activeRating: {
    type: [Number, null],
    default: null,
  },
  // Zuletzt per Batch gesetzte Farbe (undefined = nie gesetzt, null = entfernt, String = Farbe)
  activeColor: {
    type: [String, null],
    default: undefined,
  },
  // undefined=nie gesetzt, 'none'=entfernt, 'pick'|'reject'
  activePick: {
    type: String,
    default: undefined,
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
})

const emit = defineEmits(['rate', 'clear'])

function applyRating(star) {
  emit('rate', star, undefined, undefined)
}

function applyColor(colorKey) {
  emit('rate', undefined, colorKey, undefined)
}

function applyPick(pickValue) {
  emit('rate', undefined, undefined, pickValue)
}
</script>

<style scoped>
.sr-selbar {
  position: fixed;
  bottom: 28px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 0;
  background: #12122a !important;
  border: 1px solid #e9456040 !important;
  border-radius: 10px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.6), 0 0 0 1px rgba(233,69,96,0.15);
  padding: 8px 14px;
  z-index: 1000;
  backdrop-filter: blur(12px);
  white-space: nowrap;
}

.sr-selbar__info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sr-selbar__count {
  font-size: 13px;
  font-weight: 600;
  color: #e94560;
}

.sr-selbar__clear {
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #333;
  border-radius: 4px;
  background: transparent;
  color: #777;
  cursor: pointer;
  padding: 0;
  transition: background 150ms, color 150ms;
}

@media (pointer: fine) {
  .sr-selbar__clear:hover {
    background: #2a2a4a;
    color: #e94560;
  }
}

.sr-selbar__clear svg { width: 14px; height: 14px; }

.sr-selbar__sep {
  width: 1px;
  height: 28px;
  background: #2a2a4a;
  margin: 0 12px;
  flex-shrink: 0;
}

.sr-selbar__section {
  display: flex;
  align-items: center;
  gap: 5px;
}

.sr-selbar__label {
  font-size: 11px;
  color: #555;
  margin-right: 2px;
}

/* Buttons */
.sr-selbar__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 30px;
  height: 28px;
  padding: 0 6px;
  border: 1px solid #2a2a4a !important;
  border-radius: 5px;
  background: transparent !important;
  color: #888 !important;
  font-size: 12px;
  cursor: pointer;
  transition: background 120ms, color 120ms, border-color 120ms;
  box-shadow: none !important;
}

@media (pointer: fine) {
  .sr-selbar__btn:hover {
    background: #2a2a4a !important;
    color: #ddd !important;
    border-color: #3a3a5a !important;
  }
}

.sr-selbar__btn--active {
  background: #e94560 !important;
  border-color: #e94560 !important;
  color: #fff !important;
}

.sr-selbar__btn--star {
  letter-spacing: -1px;
  font-size: 11px;
  color: #f5a623;
}

.sr-selbar__btn--star:first-of-type {
  color: #777;
}

/* Farbpunkt-Button */
.sr-selbar__btn--color,
.sr-selbar__btn--color-none {
  width: 28px;
  min-width: 28px;
  padding: 0;
}

.sr-selbar__color-dot {
  width: 13px;
  height: 13px;
  border-radius: 50%;
  background: var(--dot-color, #888);
  display: block;
}

/* Leerer Kreis für "keine Farbe" */
.sr-selbar__color-dot--none {
  background: transparent;
  border: 1.5px solid #555;
}

.sr-selbar__btn--color.sr-selbar__btn--active {
  background: transparent !important;
  border-color: #fff !important;
  box-shadow: 0 0 0 2px var(--dot-color, #888) !important;
}

.sr-selbar__btn--color-none.sr-selbar__btn--active {
  background: transparent !important;
  border-color: #fff !important;
  box-shadow: 0 0 0 2px #555 !important;
}

.sr-selbar__btn--color-none.sr-selbar__btn--active .sr-selbar__color-dot--none {
  border-color: #aaa;
}

/* Pick/Reject */
.sr-selbar__btn--pick.sr-selbar__btn--active {
  background: #22c55e !important;
  border-color: #22c55e !important;
  color: #fff !important;
}

.sr-selbar__btn--reject.sr-selbar__btn--active {
  background: #e94560 !important;
  border-color: #e94560 !important;
  color: #fff !important;
}

/* ── Mobile: Android-Navigationsleiste ───────────────────────────────────── */
@media (pointer: coarse) {
  .sr-selbar {
    bottom: max(80px, env(safe-area-inset-bottom));
  }
}

/* Slide-up Transition */
.selection-bar-enter-active,
.selection-bar-leave-active {
  transition: transform 250ms cubic-bezier(0.34, 1.56, 0.64, 1), opacity 200ms ease;
}

.selection-bar-enter-from,
.selection-bar-leave-to {
  transform: translateX(-50%) translateY(20px);
  opacity: 0;
}
</style>
