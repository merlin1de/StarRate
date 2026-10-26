<template>
  <div class="sr-settings">

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Funktionen') }}</h3>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.enable_pick_ui" @change="autosave" />
          {{ t('starrate', 'Pick / Reject aktivieren') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_raw" @change="autosave" />
          {{ t('starrate', 'Kamera-RAWs anzeigen (NEF, CR2, CR3, ARW, DNG, …)') }}
        </label>
      </div>
      <p v-if="form.show_raw" class="sr-settings__hint">
        {{ t('starrate', 'Vorschau ist das JPEG, das die Kamera in die RAW-Datei einbettet. Liegen RAW und JPEG im selben Ordner, erscheint jedes Foto zweimal.') }}
      </p>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.write_xmp" @change="autosave" />
          {{ t('starrate', 'XMP in JPEG schreiben') }}
        </label>
      </div>

      <div v-if="form.write_xmp" class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Farb-Bewertungen kompatibel mit') }}</label>
        <div class="sr-settings__control">
          <select v-model="form.xmp_label_language" class="sr-settings__select" @change="autosave">
            <option value="de">{{ t('starrate', 'Lightroom (deutsche Lokalisierung)') }}</option>
            <option value="en">{{ t('starrate', 'Bridge / digiKam / englische Tools') }}</option>
          </select>
        </div>
      </div>
      <p v-if="form.write_xmp" class="sr-settings__hint">
        {{ t('starrate', 'Steuert nur den Label-Text (xmp:Label). Der Farbstreifen (photoshop:LabelColor) bleibt sprachneutral und funktioniert in beiden Modi.') }}
      </p>
      <div v-if="form.show_raw && form.write_xmp" class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.write_raw_sidecar" @change="autosave" />
          {{ t('starrate', 'RAW-Bewertungen für Lightroom als XMP-Sidecar speichern') }}
        </label>
      </div>
      <p v-if="form.show_raw && form.write_xmp && form.write_raw_sidecar" class="sr-settings__hint">
        {{ t('starrate', 'Die RAW-Datei bleibt unverändert. Bewertungen landen wie bei Lightroom in DSC_0001.xmp neben DSC_0001.NEF; vorhandene Sidecars werden nur ergänzt. Ausnahme DNG: Lightroom liest dort nur das eingebettete XMP.') }}
      </p>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.comments_enabled" @change="autosave" />
          {{ t('starrate', 'Kommentare aktivieren') }}
        </label>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Anzeige') }}</h3>

      <!-- Spalten -->
      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Spalten') }}</label>
        <div class="sr-settings__control">
          <select v-model="form.grid_columns" class="sr-settings__select" @change="autosave">
            <option value="auto">{{ t('starrate', 'Automatisch') }}</option>
            <option value="2">2</option>
            <option value="3">3</option>
            <option value="4">4</option>
            <option value="5">5</option>
            <option value="6">6</option>
            <option value="8">8</option>
          </select>
        </div>
      </div>

      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_filename" @change="autosave" />
          {{ t('starrate', 'Dateiname anzeigen') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_rating_overlay" @change="autosave" />
          {{ t('starrate', 'Sterne in Info-Leiste') }}
        </label>
      </div>
      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.show_color_overlay" @change="autosave" />
          {{ t('starrate', 'Farbpunkt in Info-Leiste') }}
        </label>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Sortierung') }}</h3>

      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Standard-Sortierung') }}</label>
        <div class="sr-settings__control sr-settings__control--inline">
          <select v-model="form.default_sort" class="sr-settings__select" @change="autosave">
            <option value="name">{{ t('starrate', 'Name') }}</option>
            <option value="mtime">{{ t('starrate', 'Änderungsdatum') }}</option>
            <option value="size">{{ t('starrate', 'Dateigröße') }}</option>
          </select>
          <select v-model="form.default_sort_order" class="sr-settings__select" @change="autosave">
            <option value="asc">{{ t('starrate', 'Aufsteigend') }}</option>
            <option value="desc">{{ t('starrate', 'Absteigend') }}</option>
          </select>
        </div>
      </div>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Rekursive Ansicht') }}</h3>

      <div class="sr-settings__row">
        <label class="sr-settings__label sr-settings__label--check">
          <input type="checkbox" v-model="form.recursion_enabled" @change="autosave" />
          {{ t('starrate', 'Rekursive Ansicht aktivieren') }}
        </label>
      </div>

      <template v-if="form.recursion_enabled">
        <div class="sr-settings__row">
          <label class="sr-settings__label sr-settings__label--check">
            <input type="checkbox" v-model="form.recursive_default" @change="autosave" />
            {{ t('starrate', 'Standardmäßig rekursiv') }}
          </label>
        </div>

        <div class="sr-settings__row">
          <label class="sr-settings__label">{{ t('starrate', 'Gruppen-Tiefe') }}</label>
          <div class="sr-settings__control">
            <select v-model.number="form.recursive_default_depth" class="sr-settings__select" @change="autosave">
              <option :value="0">{{ t('starrate', 'Flach (keine Gruppierung)') }}</option>
              <option :value="1">1</option>
              <option :value="2">2</option>
              <option :value="3">3</option>
              <option :value="4">4</option>
            </select>
          </div>
        </div>

        <p class="sr-settings__hint">
          {{ t('starrate', 'Rekursiv: zeigt Bilder aus allen Unterordnern. Tiefe: sortiert Items mit gleichem Pfad-Präfix nebeneinander, ohne sichtbare Gruppen-Header.') }}
        </p>
      </template>

      <p v-else class="sr-settings__hint">
        {{ t('starrate', 'Wenn aktiviert: zusätzlicher Toggle in der Filterleiste, mit dem ihr alle Bilder aus Unterordnern in einer Ansicht anzeigen lasst.') }}
      </p>
    </div>

    <div class="sr-settings__group">
      <h3 class="sr-settings__heading">{{ t('starrate', 'Diashow') }}</h3>

      <div class="sr-settings__row">
        <label class="sr-settings__label">{{ t('starrate', 'Intervall') }}</label>
        <div class="sr-settings__control">
          <select v-model.number="form.slideshow_interval" class="sr-settings__select" @change="autosave">
            <option v-for="s in SLIDESHOW_INTERVALS" :key="s" :value="s">{{ s }} s</option>
          </select>
        </div>
      </div>

      <p class="sr-settings__hint">
        {{ t('starrate', 'Zeit pro Bild in der Diashow. Start/Pause mit Taste S in der Lupenansicht.') }}
      </p>
    </div>

    <!-- Status -->
    <Transition name="sr-fade">
      <span v-if="status" class="sr-settings__status" :class="`sr-settings__status--${status}`">
        {{ status === 'ok' ? t('starrate', '✓ Gespeichert') : t('starrate', '✗ Fehler beim Speichern') }}
      </span>
    </Transition>

  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { SLIDESHOW_INTERVALS, SLIDESHOW_DEFAULT_SEC } from '../utils/slideshow.js'

const props = defineProps({
  initial: { type: Object, default: () => ({}) },
})

const DEFAULTS = {
  default_sort:             'name',
  default_sort_order:       'asc',
  show_filename:             true,
  show_rating_overlay:       true,
  show_color_overlay:        true,
  grid_columns:             'auto',
  enable_pick_ui:            false,
  write_xmp:                 true,
  show_raw:                  false,
  write_raw_sidecar:         true,
  xmp_label_language:        'en',  // Server-seitig basierend auf User-Locale gesetzt
  comments_enabled:          false,
  recursion_enabled:         false,
  recursive_default:         false,
  recursive_default_depth:   0,
  slideshow_interval:        SLIDESHOW_DEFAULT_SEC,
}

const form   = reactive({ ...DEFAULTS, ...props.initial })
const status = ref('')
let   saveTimer = null

async function autosave() {
  clearTimeout(saveTimer)
  saveTimer = setTimeout(async () => {
    try {
      await axios.post(generateUrl('/apps/starrate/api/settings'), { ...form })
      status.value = 'ok'
    } catch {
      status.value = 'error'
    }
    setTimeout(() => { status.value = '' }, 2500)
  }, 600)
}
</script>

<style scoped>
.sr-settings {
  display: flex;
  flex-direction: column;
  gap: 24px;
  max-width: 480px;
  padding: 4px 0 16px;
}

.sr-settings__group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.sr-settings__heading {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-maxcontrast, #888);
  margin: 0;
}

.sr-settings__row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sr-settings__label {
  min-width: 170px;
  font-size: 14px;
  color: var(--color-main-text, #222);
}

.sr-settings__label--check {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: unset;
  cursor: pointer;
  line-height: 20px;
}

.sr-settings__label--check :deep(input[type="checkbox"]) {
  flex-shrink: 0;
  width: 18px !important;
  height: 18px !important;
  margin: 0 !important;
  position: relative;
  top: 1px;
}

.sr-settings__control {
  display: flex;
  align-items: center;
}

.sr-settings__control--inline {
  gap: 8px;
}

.sr-settings__select {
  padding: 5px 8px;
  border-radius: 5px;
  /* var(--color-border) ist im aktuellen NC-Theme sehr blass — fast unsichtbar.
     --color-border-dark gibt sichtbare Konturen, fällt auf #aaa zurück wenn
     die Custom-Property nicht definiert ist. */
  border: 1px solid var(--color-border-dark, #aaa);
  background: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
  font-size: 13px;
  cursor: pointer;
}

.sr-settings__status {
  font-size: 13px;
  font-weight: 500;
}

.sr-settings__status--ok    { color: var(--color-success, #46ba61); }
.sr-settings__status--error { color: var(--color-error,   #e9322d); }

.sr-settings__hint {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--color-text-maxcontrast, #888);
  line-height: 1.4;
}

.sr-fade-enter-active, .sr-fade-leave-active { transition: opacity 300ms; }
.sr-fade-enter-from, .sr-fade-leave-to       { opacity: 0; }
</style>
