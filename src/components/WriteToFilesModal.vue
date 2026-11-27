<template>
  <Teleport to="body">
    <div class="sr-wtf__overlay" @click.self="close">
      <div class="sr-wtf" role="dialog" aria-modal="true" :aria-label="t('starrate', 'Bewertungen in Dateien schreiben')">

        <header class="sr-wtf__header">
          <h2 class="sr-wtf__title">{{ t('starrate', 'Bewertungen in Dateien schreiben') }}</h2>
          <button class="sr-wtf__close" type="button" :disabled="phase === 'writing'" @click="close">✕</button>
        </header>

        <div class="sr-wtf__body">
          <p class="sr-wtf__intro">
            {{ t('starrate', 'Schreibt die StarRate-Bewertungen in die XMP-Dateien, damit Lightroom sie mit „Metadaten aus Datei lesen“ übernehmen kann. Werte, die nur in der Datei stehen, werden nie gelöscht.') }}
          </p>

          <!-- Laden -->
          <p v-if="phase === 'loading'" class="sr-wtf__status">
            {{ t('starrate', 'Vergleiche {done} von {total} Bildern …', { done: progress.done, total: progress.total }) }}
          </p>

          <p v-else-if="phase === 'error'" class="sr-wtf__status sr-wtf__status--error">{{ errorText }}</p>

          <template v-else>
            <!-- Felder -->
            <div class="sr-wtf__fields">
              <label class="sr-wtf__field">
                <input v-model="fields.rating" type="checkbox" class="sr-wtf__checkbox" :disabled="locked" />
                <span>{{ t('starrate', 'Sterne') }}</span>
                <span class="sr-wtf__counts">{{ countLabel('rating') }}</span>
              </label>
              <label class="sr-wtf__field">
                <input v-model="fields.color" type="checkbox" class="sr-wtf__checkbox" :disabled="locked" />
                <span>{{ t('starrate', 'Farben') }}</span>
                <span class="sr-wtf__counts">{{ countLabel('color') }}</span>
              </label>
              <label v-if="showPickCol" class="sr-wtf__field">
                <input v-model="fields.pick" type="checkbox" class="sr-wtf__checkbox" :disabled="locked" />
                <span>{{ t('starrate', 'Pick/Reject') }}</span>
                <span class="sr-wtf__counts">{{ countLabel('pick') }}</span>
              </label>
            </div>

            <!-- Modus -->
            <div class="sr-wtf__modes">
              <label class="sr-wtf__mode">
                <input v-model="overwrite" type="radio" :value="false" class="sr-wtf__checkbox" :disabled="locked" />
                <span>{{ t('starrate', 'Nur ergänzen, was in der Datei fehlt (empfohlen)') }}</span>
              </label>
              <label class="sr-wtf__mode">
                <input v-model="overwrite" type="radio" :value="true" class="sr-wtf__checkbox" :disabled="locked" />
                <span>{{ t('starrate', 'Auch abweichende Werte in der Datei ersetzen') }}</span>
              </label>
              <p v-if="overwrite" class="sr-wtf__warn">
                {{ t('starrate', 'Ersetzt auch Werte, die du in Lightroom bewusst geändert hast.') }}
              </p>
            </div>

            <!-- Liste -->
            <div v-if="rows.length" class="sr-wtf__list">
              <div v-for="row in rows.slice(0, MAX_ROWS)" :key="row.id" class="sr-wtf__row">
                <span class="sr-wtf__name">{{ row.name }}</span>
                <span class="sr-wtf__changes">
                  <span v-for="c in row.changes" :key="c.field" class="sr-wtf__change">
                    {{ formatValue(c.field, c.db) }}
                    <span class="sr-wtf__arrow">{{ c.kind === 'missing' ? t('starrate', '(fehlt in Datei)') : t('starrate', 'statt {value}', { value: formatValue(c.field, c.file) }) }}</span>
                  </span>
                </span>
              </div>
              <p v-if="rows.length > MAX_ROWS" class="sr-wtf__more">
                {{ t('starrate', '… und {n} weitere', { n: rows.length - MAX_ROWS }) }}
              </p>
            </div>
            <p v-else-if="phase === 'ready'" class="sr-wtf__status">
              {{ t('starrate', 'Alles aktuell — die Dateien enthalten bereits diese Bewertungen.') }}
            </p>

            <p v-if="skippedText" class="sr-wtf__meta">{{ skippedText }}</p>

            <!-- Schreiben / Ergebnis -->
            <p v-if="phase === 'writing'" class="sr-wtf__status">
              {{ t('starrate', 'Schreibe {done} von {total} Dateien …', { done: progress.done, total: progress.total }) }}
            </p>
            <div v-if="phase === 'done'" class="sr-wtf__result">
              <p class="sr-wtf__status">
                {{ n('starrate', '%n Datei aktualisiert', '%n Dateien aktualisiert', result.written) }}<template v-if="result.skipped">, {{ n('starrate', '%n übersprungen', '%n übersprungen', result.skipped) }}</template><template v-if="result.failed">, {{ n('starrate', '%n fehlgeschlagen', '%n fehlgeschlagen', result.failed) }}</template>.
              </p>
              <p class="sr-wtf__hint">
                {{ t('starrate', 'In Lightroom: Bibliotheksfilter → Metadaten → Metadatenstatus „Wurde extern geändert“, alle auswählen, dann Metadaten → Metadaten aus Datei lesen.') }}
              </p>
            </div>
          </template>
        </div>

        <div class="sr-wtf__actions">
          <button class="sr-wtf__btn sr-wtf__btn--secondary" type="button" :disabled="phase === 'writing'" @click="close">
            {{ phase === 'done' ? t('starrate', 'Schließen') : t('starrate', 'Abbrechen') }}
          </button>
          <button
            v-if="phase !== 'done'"
            class="sr-wtf__btn sr-wtf__btn--primary"
            type="button"
            :disabled="phase !== 'ready' || rows.length === 0"
            @click="apply"
          >
            {{ n('starrate', 'In %n Datei schreiben', 'In %n Dateien schreiben', rows.length) }}
          </button>
        </div>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { t, n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const props = defineProps({
  images:      { type: Array,   required: true },
  showPickCol: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

// Häppchengrößen müssen ≤ XmpSyncController::MAX_PREVIEW_IDS / MAX_APPLY_IDS sein
const PREVIEW_CHUNK = 250
const APPLY_CHUNK   = 50
const MAX_ROWS      = 300

const COLOR_LABELS = {
  Red: t('starrate', 'Rot'), Yellow: t('starrate', 'Gelb'), Green: t('starrate', 'Grün'),
  Blue: t('starrate', 'Blau'), Purple: t('starrate', 'Lila'),
}

const phase    = ref('loading')   // loading | ready | writing | done | error
const progress = ref({ done: 0, total: 0 })
const items    = ref([])
const counts   = ref({ missing: {}, different: {} })
const skipped  = ref({ noStore: 0, unreadable: 0, unsupported: 0, notFound: 0 })
const result   = ref({ written: 0, skipped: 0, failed: 0 })
const errorText = ref('')
let alive = true   // nach dem Schließen keine weiteren Häppchen senden

// Pick standardmäßig aus: Lightroom und StarRate setzen Flags oft unabhängig
const fields    = ref({ rating: true, color: true, pick: false })
const overwrite = ref(false)

const locked = computed(() => phase.value === 'writing' || phase.value === 'done')

function selectedFields() {
  return Object.keys(fields.value).filter(f => fields.value[f] && (f !== 'pick' || props.showPickCol))
}

/** Pro Datei die Änderungen, die mit der aktuellen Auswahl geschrieben würden. */
const rows = computed(() => {
  const sel = selectedFields()
  const out = []
  for (const item of items.value) {
    const changes = []
    for (const f of sel) {
      const d = item.diffs[f]
      if (d && (d.kind === 'missing' || (overwrite.value && d.kind === 'different'))) {
        changes.push({ field: f, ...d })
      }
    }
    if (changes.length) out.push({ id: item.id, name: item.name, changes })
  }
  return out
})

function countLabel(field) {
  const miss = counts.value.missing[field] || 0
  const diff = counts.value.different[field] || 0
  if (phase.value === 'loading') return ''
  if (!miss && !diff) return t('starrate', 'aktuell')
  const parts = []
  if (miss) parts.push(t('starrate', '{n} fehlen', { n: miss }))
  if (diff) parts.push(t('starrate', '{n} abweichend', { n: diff }))
  return parts.join(' · ')
}

function formatValue(field, value) {
  if (field === 'rating') return value ? '★'.repeat(value) : '–'
  if (field === 'color')  return value ? (COLOR_LABELS[value] || value) : '–'
  if (value === 'pick')   return t('starrate', 'Pick')
  if (value === 'reject') return t('starrate', 'Reject')
  return '–'
}

const skippedText = computed(() => {
  const parts = []
  if (skipped.value.noStore)    parts.push(n('starrate', '%n Bild ohne XMP-Ziel übersprungen', '%n Bilder ohne XMP-Ziel übersprungen', skipped.value.noStore))
  if (skipped.value.unreadable) parts.push(n('starrate', '%n Datei nicht lesbar', '%n Dateien nicht lesbar', skipped.value.unreadable))
  if (skipped.value.notFound)   parts.push(n('starrate', '%n Datei nicht gefunden', '%n Dateien nicht gefunden', skipped.value.notFound))
  if (skipped.value.unsupported) parts.push(n('starrate', '%n Datei mit Werten, die StarRate nicht kennt, bleibt unangetastet', '%n Dateien mit Werten, die StarRate nicht kennt, bleiben unangetastet', skipped.value.unsupported))
  return parts.join(' · ')
})

function chunks(list, size) {
  const out = []
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size))
  return out
}

function describeError(e) {
  if (e?.response?.data?.error === 'xmp_disabled') {
    return t('starrate', '„XMP in Dateien schreiben“ ist in den StarRate-Einstellungen ausgeschaltet.')
  }
  return t('starrate', 'Fehler beim Vergleichen der Dateien.')
}

async function loadPreview() {
  const ids = props.images.map(img => img.id)
  progress.value = { done: 0, total: ids.length }
  const merged = { missing: {}, different: {} }
  try {
    for (const part of chunks(ids, PREVIEW_CHUNK)) {
      if (!alive) return
      const { data } = await axios.post(generateUrl('/apps/starrate/api/xmp-sync/preview'), { fileIds: part }, { timeout: 120000 })
      items.value.push(...data.items)
      for (const kind of ['missing', 'different']) {
        for (const [f, c] of Object.entries(data.counts[kind] || {})) merged[kind][f] = (merged[kind][f] || 0) + c
      }
      skipped.value.noStore    += data.noStore
      skipped.value.unreadable += data.unreadable
      skipped.value.unsupported += data.skipped || 0
      skipped.value.notFound   += data.notFound || 0
      progress.value.done += part.length
    }
    counts.value = merged
    phase.value = 'ready'
  } catch (e) {
    errorText.value = describeError(e)
    phase.value = 'error'
  }
}

async function apply() {
  const ids = rows.value.map(r => r.id)
  const sel = selectedFields()
  progress.value = { done: 0, total: ids.length }
  result.value = { written: 0, skipped: 0, failed: 0 }
  phase.value = 'writing'
  for (const part of chunks(ids, APPLY_CHUNK)) {
    if (!alive) return
    try {
      const { data } = await axios.post(
        generateUrl('/apps/starrate/api/xmp-sync/apply'),
        { fileIds: part, fields: sel, overwrite: overwrite.value },
        { timeout: 120000 },
      )
      result.value.written += data.written
      result.value.failed  += data.failed
      // Seit der Vorschau geändert / nicht lesbar / kein XMP-Ziel mehr
      result.value.skipped += (data.unchanged || 0) + (data.skipped || 0) + (data.no_store || 0)
    } catch {
      result.value.failed += part.length
    }
    progress.value.done += part.length
  }
  phase.value = 'done'
}

function close() {
  if (phase.value !== 'writing') emit('close')
}
function onKeydown(e) { if (e.key === 'Escape') close() }

onMounted(() => {
  // Capture-Phase: Gallerys Escape-Handler (ebenfalls Capture auf document)
  // stoppt die Propagation, ein Bubble-Listener käme nie an.
  document.addEventListener('keydown', onKeydown, true)
  loadPreview()
})
onUnmounted(() => {
  alive = false
  document.removeEventListener('keydown', onKeydown, true)
})

defineExpose({ rows, phase })
</script>

<style scoped>
.sr-wtf__overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center; z-index: 9100;
}
.sr-wtf {
  background: #16213e; border: 1px solid #2a2a3e; border-radius: 12px;
  width: min(600px, 95vw); max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;
}
.sr-wtf__header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.25rem 1.5rem 1rem; border-bottom: 1px solid #2a2a3e; flex-shrink: 0;
}
.sr-wtf__title { color: #fff; font-size: 1rem; font-weight: 600; margin: 0; }
.sr-wtf__close { background: none; border: none; color: #71717a; cursor: pointer; font-size: 1rem; padding: 0.25rem; line-height: 1; }
.sr-wtf__close:hover { color: #d4d4d8; }
.sr-wtf__body {
  padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.9rem; overflow-y: auto; flex: 1;
}
.sr-wtf__intro { color: #a1a1aa; font-size: 0.82rem; line-height: 1.45; margin: 0; }
.sr-wtf__fields { display: flex; flex-direction: column; gap: 0.45rem; }
.sr-wtf__field, .sr-wtf__mode {
  display: flex; align-items: center; gap: 0.5rem; color: #d4d4d8; font-size: 0.86rem; cursor: pointer;
}
.sr-wtf__counts { color: #71717a; font-size: 0.78rem; margin-left: auto; }
.sr-wtf__modes { display: flex; flex-direction: column; gap: 0.4rem; padding-top: 0.25rem; border-top: 1px solid #2a2a3e; padding-top: 0.75rem; }
.sr-wtf__warn { color: #e0c252; font-size: 0.78rem; margin: 0 0 0 1.6rem; }
.sr-wtf__checkbox { accent-color: #e94560; width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
.sr-wtf__list {
  background: #0f0f1a; border: 1px solid #3f3f5a; border-radius: 6px;
  max-height: 260px; overflow: auto; padding: 0.4rem 0.75rem;
}
.sr-wtf__row {
  display: flex; gap: 1rem; justify-content: space-between; padding: 0.25rem 0;
  border-bottom: 1px solid #1c1c2e; font-size: 0.78rem;
}
.sr-wtf__row:last-child { border-bottom: none; }
.sr-wtf__name { color: #d4d4d8; font-family: monospace; white-space: nowrap; }
.sr-wtf__changes { display: flex; flex-wrap: wrap; gap: 0.3rem 0.9rem; justify-content: flex-end; color: #d4d4d8; }
.sr-wtf__arrow { color: #71717a; }
.sr-wtf__more, .sr-wtf__meta { color: #71717a; font-size: 0.78rem; margin: 0.3rem 0 0; }
.sr-wtf__status { color: #d4d4d8; font-size: 0.86rem; margin: 0; }
.sr-wtf__status--error { color: #e05252; }
.sr-wtf__hint { color: #a1a1aa; font-size: 0.8rem; line-height: 1.45; margin: 0.4rem 0 0; }
.sr-wtf__actions {
  display: flex; justify-content: flex-end; gap: 0.5rem; padding: 1rem 1.5rem;
  border-top: 1px solid #2a2a3e; flex-shrink: 0;
}
.sr-wtf__btn { border: none; border-radius: 6px; cursor: pointer; font-size: 0.875rem; padding: 0.5rem 1.1rem; }
.sr-wtf__btn:disabled { opacity: 0.5; cursor: default; }
.sr-wtf__btn--primary { background: #e94560; color: #fff; }
.sr-wtf__btn--secondary { background: #2a2a3e; color: #a1a1aa; }
.sr-wtf__btn--secondary:hover:not(:disabled) { color: #d4d4d8; }
</style>
