<template>
  <Teleport to="body">
    <div class="sr-export-modal__overlay" @click.self="$emit('close')" @keydown.escape="$emit('close')">
      <div class="sr-export-modal">

        <header class="sr-export-modal__header">
          <h2 class="sr-export-modal__title">{{ t('starrate', 'Bewertungsliste exportieren') }}</h2>
          <button class="sr-export-modal__close" @click="$emit('close')">✕</button>
        </header>

        <div class="sr-export-modal__body">

          <!-- Spalten-Auswahl -->
          <div class="sr-export-modal__columns">
            <span class="sr-export-modal__col-fixed">{{ t('starrate', 'Dateiname') }}</span>
            <label v-if="showRatingCol" class="sr-export-modal__col-label">
              <input type="checkbox" v-model="cols.rating" class="sr-export-modal__checkbox" />
              {{ t('starrate', 'Sterne') }}
            </label>
            <label v-if="showColorCol" class="sr-export-modal__col-label">
              <input type="checkbox" v-model="cols.color" class="sr-export-modal__checkbox" />
              {{ t('starrate', 'Farbe') }}
            </label>
            <label v-if="showPickCol" class="sr-export-modal__col-label">
              <input type="checkbox" v-model="cols.pick" class="sr-export-modal__checkbox" />
              {{ t('starrate', 'Pick/Reject') }}
            </label>
          </div>

          <!-- Vorschau -->
          <div class="sr-export-modal__preview-wrap">
            <pre class="sr-export-modal__preview">{{ previewText }}</pre>
          </div>

          <p class="sr-export-modal__count">{{ t('starrate', '{n} Bilder', { n: images.length }) }}</p>

        </div>

        <div class="sr-export-modal__actions">
          <button class="sr-export-modal__btn sr-export-modal__btn--secondary" @click="downloadCsv">
            {{ t('starrate', 'CSV herunterladen') }}
          </button>
          <button
            class="sr-export-modal__btn sr-export-modal__btn--primary"
            :class="{ 'sr-export-modal__btn--done': copied }"
            @click="copyToClipboard"
          >
            {{ copied ? '✓ ' + t('starrate', 'Kopiert') : t('starrate', 'In Zwischenablage kopieren') }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { t } from '@nextcloud/l10n'

const props = defineProps({
  images:        { type: Array,   required: true },
  showRatingCol: { type: Boolean, default: true },
  showColorCol:  { type: Boolean, default: true },
  showPickCol:   { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

function onKeydown(e) { if (e.key === 'Escape') emit('close') }
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

// ── Spalten-State ──────────────────────────────────────────────────────────────

const cols = ref({
  rating: false,
  color:  false,
  pick:   false,
})

const copied = ref(false)

// ── CSV-Generierung ───────────────────────────────────────────────────────────

function buildCsv() {
  const header = ['filename']
  if (cols.value.rating) header.push('rating')
  if (cols.value.color)  header.push('color')
  if (cols.value.pick)   header.push('pick')

  const lines = [header.join(',')]

  for (const img of props.images) {
    const row = [csvEscape(img.name ?? '')]
    if (cols.value.rating) row.push(img.rating ?? 0)
    if (cols.value.color)  row.push(img.color ?? '')
    if (cols.value.pick)   row.push(img.pick === 'pick' ? 'pick' : img.pick === 'reject' ? 'reject' : '')
    lines.push(row.join(','))
  }

  return lines.join('\n')
}

function csvEscape(val) {
  if (/[,"\n]/.test(val)) return '"' + val.replace(/"/g, '""') + '"'
  return val
}

// ── Vorschau (max. 8 Zeilen + ggf. "…") ───────────────────────────────────────

const previewText = computed(() => {
  const csv = buildCsv()
  const lines = csv.split('\n')
  const preview = lines.slice(0, 9)
  if (lines.length > 9) preview.push(`… (${lines.length - 1} ${t('starrate', 'Bilder gesamt')})`)
  return preview.join('\n')
})

// ── Aktionen ──────────────────────────────────────────────────────────────────

async function copyToClipboard() {
  const csv = buildCsv()
  try {
    await navigator.clipboard.writeText(csv)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch { /* ignore */ }
}

function downloadCsv() {
  const csv = buildCsv()
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href     = url
  a.download = 'starrate-export.csv'
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<style scoped>
.sr-export-modal__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9100;
}

.sr-export-modal {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  width: min(520px, 95vw);
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sr-export-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid #2a2a3e;
  flex-shrink: 0;
}

.sr-export-modal__title {
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}

.sr-export-modal__close {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
  line-height: 1;
}
.sr-export-modal__close:hover { color: #d4d4d8; }

.sr-export-modal__body {
  padding: 1.25rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  overflow-y: auto;
  flex: 1;
}

.sr-export-modal__columns {
  display: flex;
  gap: 1.25rem;
  flex-wrap: wrap;
}

.sr-export-modal__col-fixed {
  display: flex;
  align-items: center;
  color: #d4d4d8;
  font-size: 0.85rem;
}

.sr-export-modal__col-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: #d4d4d8;
  font-size: 0.85rem;
  cursor: pointer;
}

.sr-export-modal__checkbox {
  accent-color: #e94560;
  width: 15px;
  height: 15px;
  cursor: pointer;
}

.sr-export-modal__preview-wrap {
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  overflow: auto;
  max-height: 260px;
}

.sr-export-modal__preview {
  color: #a1a1aa;
  font-family: monospace;
  font-size: 0.78rem;
  line-height: 1.5;
  margin: 0;
  padding: 0.75rem;
  white-space: pre;
}

.sr-export-modal__count {
  color: #52525b;
  font-size: 0.78rem;
  margin: 0;
}

.sr-export-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid #2a2a3e;
  flex-shrink: 0;
}

.sr-export-modal__btn {
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.875rem;
  padding: 0.5rem 1.1rem;
}
.sr-export-modal__btn--primary {
  background: #e94560;
  color: #fff;
}
.sr-export-modal__btn--done,
.sr-export-modal__btn--done:focus,
.sr-export-modal__btn--done:hover,
.sr-export-modal__btn--done:active {
  background: #4caf50 !important;
  color: #fff !important;
  outline: none;
}
.sr-export-modal__btn--secondary {
  background: #2a2a3e;
  color: #a1a1aa;
}
.sr-export-modal__btn--secondary:hover { color: #d4d4d8; }
</style>
