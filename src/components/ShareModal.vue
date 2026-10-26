<template>
  <Teleport to="body">
    <div class="sr-share-modal__overlay" @click.self="$emit('close')">
      <div class="sr-share-modal">
        <header class="sr-share-modal__header">
          <h2 class="sr-share-modal__title">
            {{ isEditMode ? t('starrate', 'Freigabe bearbeiten') : t('starrate', 'Neuen Freigabe-Link erstellen') }}
          </h2>
          <button class="sr-share-modal__close" @click="$emit('close')">✕</button>
        </header>

        <!-- Erfolg: Link anzeigen -->
        <div v-if="createdShare" class="sr-share-modal__success">
          <p class="sr-share-modal__success-label">{{ t('starrate', 'Dein Freigabe-Link:') }}</p>
          <div class="sr-share-modal__link-row">
            <input
              class="sr-share-modal__link-input"
              readonly
              :value="shareUrl(createdShare.token)"
              @click="$event.target.select()"
            />
            <button class="sr-share-modal__copy-btn" :class="{ 'sr-share-modal__copy-btn--done': copied }" @click="copyUrl">
              {{ copied ? '✓ ' + t('starrate', 'Kopiert') : t('starrate', 'Kopieren') }}
            </button>
          </div>
          <p class="sr-share-modal__success-hint">
            <strong>Ordner:</strong> {{ createdShare.nc_path }}<br/>
            <strong>{{ t('starrate', 'Berechtigung:') }}</strong> {{ createdShare.permissions === 'rate' ? t('starrate', 'Ansehen + Bewerten') : t('starrate', 'Nur ansehen') }}<br/>
            <span v-if="createdShare.min_rating > 0"><strong>{{ t('starrate', 'Vorfilter:') }}</strong> ≥ {{ createdShare.min_rating }} ★<br/></span>
            <span v-if="createdShare.permissions === 'rate' && !createdShare.allow_rating"><strong>{{ t('starrate', 'Sterne:') }}</strong> {{ t('starrate', 'Ausgeblendet') }}<br/></span>
            <span v-if="createdShare.permissions === 'rate' && !createdShare.allow_color"><strong>{{ t('starrate', 'Farbmarkierungen:') }}</strong> {{ t('starrate', 'Ausgeblendet') }}<br/></span>
            <span v-if="createdShare.allow_pick"><strong>{{ t('starrate', 'Pick/Reject:') }}</strong> {{ t('starrate', 'Aktiviert') }}<br/></span>
            <span v-if="createdShare.allow_export"><strong>{{ t('starrate', 'Liste exportieren:') }}</strong> {{ t('starrate', 'Erlaubt') }}<br/></span>
            <span v-if="createdShare.has_password"><strong>{{ t('starrate', 'Passwortgeschützt') }}</strong><br/></span>
            <span v-if="createdShare.expires_at"><strong>{{ t('starrate', 'Läuft ab:') }}</strong> {{ formatDate(createdShare.expires_at) }}</span>
          </p>
          <button class="sr-share-modal__btn sr-share-modal__btn--secondary" @click="reset">
            {{ t('starrate', 'Weiteren Link erstellen') }}
          </button>
        </div>

        <!-- Formular -->
        <form v-else class="sr-share-modal__form" @submit.prevent="create">

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">{{ t('starrate', 'Ordner') }}</label>
            <!-- Im Edit-Mode editierbar: User kann den Pfad eines Shares ändern.
                 Im Create-Mode readonly: vorbefüllt mit dem aktuellen Gallery-Pfad. -->
            <input
              v-if="isEditMode"
              v-model="form.ncPath"
              class="sr-share-modal__input"
              type="text"
              :placeholder="t('starrate', '/Pfad/zum/Ordner')"
              @blur="onPathBlur"
            />
            <input
              v-else
              class="sr-share-modal__input sr-share-modal__input--readonly"
              readonly
              :value="form.ncPath"
            />
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">
              {{ t('starrate', 'Name des Empfängers') }}
              <span v-if="form.permissions === 'rate'" class="sr-share-modal__required">*</span>
              <span v-else class="sr-share-modal__optional">({{ t('starrate', 'optional') }})</span>
            </label>
            <input
              v-model="form.guestName"
              class="sr-share-modal__input"
              type="text"
              :placeholder="t('starrate', 'z.B. Anna, Model 1, Kunde Müller')"
              maxlength="60"
            />
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">{{ t('starrate', 'Berechtigung') }}</label>
            <div class="sr-share-modal__toggle-group">
              <button
                type="button"
                class="sr-share-modal__toggle"
                :class="{ 'sr-share-modal__toggle--active': form.permissions === 'view' }"
                @click="form.permissions = 'view'"
              >{{ t('starrate', 'Nur ansehen') }}</button>
              <button
                type="button"
                class="sr-share-modal__toggle"
                :class="{ 'sr-share-modal__toggle--active': form.permissions === 'rate' }"
                @click="form.permissions = 'rate'"
              >{{ t('starrate', 'Ansehen + Bewerten') }}</button>
            </div>
          </div>

          <div v-if="form.permissions === 'rate'" class="sr-share-modal__field">
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.allowRating" class="sr-share-modal__checkbox" data-testid="allow-rating" />
              {{ t('starrate', 'Sterne erlauben') }}
            </label>
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.allowColor" class="sr-share-modal__checkbox" data-testid="allow-color" />
              {{ t('starrate', 'Farbmarkierungen erlauben') }}
            </label>
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.allowPick" class="sr-share-modal__checkbox" data-testid="allow-pick" />
              {{ t('starrate', 'Pick/Reject erlauben') }}
            </label>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.allowExport" class="sr-share-modal__checkbox" />
              {{ t('starrate', 'Bewertungsliste exportieren erlauben') }}
            </label>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.allowDownload" class="sr-share-modal__checkbox" data-testid="allow-download" />
              {{ t('starrate', 'Download der Bilder erlauben') }}
            </label>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__checkbox-label" :class="{ 'sr-share-modal__checkbox-label--disabled': !commentsGloballyEnabled }">
              <input
                type="checkbox"
                v-model="form.allowComment"
                class="sr-share-modal__checkbox"
                :disabled="!commentsGloballyEnabled"
              />
              {{ t('starrate', 'Kommentare erlauben') }}
            </label>
            <span v-if="!commentsGloballyEnabled" class="sr-share-modal__hint">
              {{ t('starrate', 'Kommentare in den Einstellungen aktivieren') }}
            </span>
          </div>

          <!-- Rekursive Ansicht: nur sichtbar wenn Master-Schalter in den
               persönlichen Settings aktiv ist. -->
          <div v-if="recursionEnabled" class="sr-share-modal__field">
            <label class="sr-share-modal__checkbox-label">
              <input type="checkbox" v-model="form.recursive" class="sr-share-modal__checkbox" data-testid="recursive" />
              {{ t('starrate', 'Rekursive Ansicht (alle Bilder aus Unterordnern)') }}
            </label>
          </div>

          <div v-if="recursionEnabled && form.recursive" class="sr-share-modal__field">
            <label class="sr-share-modal__label">{{ t('starrate', 'Gruppen-Tiefe') }}</label>
            <select class="sr-share-modal__select" v-model.number="form.depth">
              <option :value="0">{{ t('starrate', 'Flach (keine Gruppierung)') }}</option>
              <option :value="1">1</option>
              <option :value="2">2</option>
              <option :value="3">3</option>
              <option :value="4">4</option>
            </select>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">{{ t('starrate', 'Vorfilter (Mindest-Bewertung)') }}</label>
            <select class="sr-share-modal__select" v-model="form.minRating">
              <option :value="0">{{ t('starrate', 'Alle Bilder') }}</option>
              <option :value="1">≥ 1 ★</option>
              <option :value="2">≥ 2 ★</option>
              <option :value="3">≥ 3 ★</option>
              <option :value="4">≥ 4 ★</option>
              <option :value="5">{{ t('starrate', '5 ★ (nur Top)') }}</option>
            </select>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">
              {{ t('starrate', 'Passwort') }}
              <span v-if="!isEditMode" class="sr-share-modal__optional">({{ t('starrate', 'optional') }})</span>
              <span v-else-if="editShare?.has_password" class="sr-share-modal__optional">({{ t('starrate', 'gesetzt — leer lassen zum Beibehalten') }})</span>
              <span v-else class="sr-share-modal__optional">({{ t('starrate', 'optional, leer lassen = kein Passwort') }})</span>
            </label>
            <div class="sr-share-modal__pw-wrap">
              <input
                v-model="form.password"
                class="sr-share-modal__input sr-share-modal__input--pw"
                :type="showPassword ? 'text' : 'password'"
                :placeholder="isEditMode && editShare?.has_password
                  ? t('starrate', '••••••••  (leer lassen zum Beibehalten)')
                  : t('starrate', 'Leer lassen = kein Passwort')"
                :disabled="form.removePassword"
                autocomplete="new-password"
              />
              <button type="button" class="sr-share-modal__pw-eye" @click="showPassword = !showPassword" :title="showPassword ? t('starrate', 'Passwort verbergen') : t('starrate', 'Passwort anzeigen')">
                <svg v-if="!showPassword" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <!-- Nur sichtbar im Edit-Mode wenn aktuell ein Passwort gesetzt ist -->
            <label v-if="isEditMode && editShare?.has_password" class="sr-share-modal__checkbox-label sr-share-modal__pw-remove">
              <input type="checkbox" v-model="form.removePassword" class="sr-share-modal__checkbox" />
              {{ t('starrate', 'Passwort entfernen (Share öffentlich machen)') }}
            </label>
          </div>

          <div class="sr-share-modal__field">
            <label class="sr-share-modal__label">{{ t('starrate', 'Ablaufdatum') }} <span class="sr-share-modal__optional">({{ t('starrate', 'optional') }})</span></label>
            <input
              v-model="form.expiresDate"
              class="sr-share-modal__input"
              type="date"
              :min="todayStr"
            />
          </div>

          <p v-if="formError" class="sr-share-modal__error">{{ formError }}</p>

          <div class="sr-share-modal__actions">
            <button type="button" class="sr-share-modal__btn sr-share-modal__btn--secondary" @click="$emit('close')">
              {{ t('starrate', 'Abbrechen') }}
            </button>
            <button type="submit" class="sr-share-modal__btn sr-share-modal__btn--primary" :disabled="saving">
              {{ submitLabel }}
            </button>
          </div>
        </form>

      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'
import { readFolderState } from '../utils/folderRecursiveState.js'

const props = defineProps({
  ncPath:                  { type: String,  default: '/' },
  commentsGloballyEnabled: { type: Boolean, default: false },
  // Recursive-View Settings — bestimmen ob die zwei neuen Felder gerendert werden,
  // und liefern Defaults für neue Shares (zusammen mit folderRecursiveState).
  recursionEnabled:        { type: Boolean, default: false },
  recursiveDefault:        { type: Boolean, default: false },
  recursiveDefaultDepth:   { type: Number,  default: 0 },
  // Defaults für neue Shares aus den eigenen Einstellungen (enable_rating_ui/enable_color_ui):
  // wer selbst ohne Sterne arbeitet, muss sie nicht bei jedem Link abwählen.
  ratingDefault:           { type: Boolean, default: true },
  colorDefault:            { type: Boolean, default: true },
  // Edit-Modus: wenn gesetzt, prefilled wir das Formular mit den Werten aus
  // diesem Share und schicken PUT statt POST.
  editShare:               { type: Object,  default: null },
})

const emit = defineEmits(['close', 'created', 'updated'])

const isEditMode = computed(() => !!props.editShare)

const submitLabel = computed(() => {
  if (saving.value) return isEditMode.value ? t('starrate', 'Speichere…') : t('starrate', 'Erstelle…')
  return isEditMode.value ? t('starrate', 'Speichern') : t('starrate', 'Link erstellen')
})

// ── Formular-State ────────────────────────────────────────────────────────────
//
// resolveRecursive: Hierarchie für die Recursive-Felder beim Anlegen oder bei
// Pfad-Wechsel im Edit-Mode. Spiegelt die Logik aus Gallery.vue:
//   localStorage[path] ?? settings-default
//
// Im Edit-Mode initial: NICHT diese Funktion verwenden, sondern die im Share
// gespeicherten Werte. Nur bei expliziter Pfad-Änderung (onPathBlur) greift
// sie wie bei einer Anlage.

function resolveRecursive(path) {
  const stored = readFolderState(path)
  if (stored) return { recursive: stored.recursive, depth: stored.depth }
  return {
    recursive: props.recursiveDefault,
    depth: props.recursiveDefaultDepth,
  }
}

function buildInitialForm() {
  const e = props.editShare
  if (e) {
    return {
      ncPath:        e.nc_path || '/',
      guestName:     e.guest_name || '',
      permissions:   e.permissions || 'rate',
      allowRating:   e.allow_rating ?? true,
      allowColor:    e.allow_color ?? true,
      allowPick:     !!e.allow_pick,
      allowExport:   !!e.allow_export,
      allowComment:  !!e.allow_comment,
      allowDownload: !!e.allow_download,
      minRating:     e.min_rating ?? 0,
      recursive:     !!e.recursive,
      depth:         e.depth ?? 0,
      password:      '',           // im Edit-Mode immer leer, Logik unten
      removePassword: false,
      expiresDate:   e.expires_at
        ? new Date(e.expires_at * 1000).toISOString().split('T')[0]
        : '',
    }
  }
  // Anlage: Defaults (Pfad vom aktuellen Folder), recursive aus
  // folderRecursiveState→Settings-Hierarchie
  const rec = resolveRecursive(props.ncPath)
  return {
    ncPath:        props.ncPath,
    guestName:     '',
    permissions:   'rate',
    allowRating:   props.ratingDefault,
    allowColor:    props.colorDefault,
    allowPick:     false,
    allowExport:   false,
    allowComment:  false,
    allowDownload: false,
    minRating:     0,
    recursive:     rec.recursive,
    depth:         rec.depth,
    password:      '',
    removePassword: false,
    expiresDate:   '',
  }
}

const form = ref(buildInitialForm())

const saving       = ref(false)
const formError    = ref('')
const createdShare = ref(null)
const copied       = ref(false)
const showPassword = ref(false)

const todayStr = computed(() => new Date().toISOString().split('T')[0])

// Pfad-Blur: nur Edit-Mode + falls Recursion aktiviert. Pfad-Wechsel löst
// die "wie bei Anlage"-Logik aus — User-Wunsch: "wenn ich Sarahs Share auf
// einen anderen Folder umschreibe, soll der Default für den neuen Folder
// greifen". Werte werden überschrieben, User kann danach manuell anpassen.
function onPathBlur() {
  if (!isEditMode.value || !props.recursionEnabled) return
  const rec = resolveRecursive(form.value.ncPath)
  form.value.recursive = rec.recursive
  form.value.depth     = rec.depth
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function shareUrl(token) {
  return window.location.origin + generateUrl(`/apps/starrate/guest/${token}`)
}

function formatDate(ts) {
  return new Date(ts * 1000).toLocaleDateString('de-DE')
}

async function copyUrl() {
  try {
    await navigator.clipboard.writeText(shareUrl(createdShare.value.token))
    copied.value = true
    setTimeout(() => { copied.value = false }, 2500)
  } catch {
    // Fallback: select input
  }
}

function reset() {
  createdShare.value = null
  formError.value    = ''
  form.value         = buildInitialForm()
}

// ── Submit (Create oder Update) ──────────────────────────────────────────────

async function create() {
  formError.value = ''
  saving.value    = true

  // Pflichtfeld-Check: bei rate muss ein Name angegeben sein
  if (form.value.permissions === 'rate' && !form.value.guestName.trim()) {
    formError.value = t('starrate', 'Bitte einen Namen für den Empfänger eingeben.')
    saving.value    = false
    return
  }
  if (form.value.permissions === 'rate'
    && !form.value.allowRating && !form.value.allowColor && !form.value.allowPick) {
    formError.value = t('starrate', 'Bitte mindestens eine Bewertungsart erlauben.')
    saving.value    = false
    return
  }

  const body = {
    nc_path:      form.value.ncPath,
    permissions:  form.value.permissions,
    // Sterne/Farben nur bei rate abschaltbar — Nur-ansehen-Links zeigen sie wie bisher
    allow_rating:  form.value.permissions !== 'rate' || form.value.allowRating,
    allow_color:   form.value.permissions !== 'rate' || form.value.allowColor,
    allow_pick:    form.value.permissions === 'rate' && form.value.allowPick,
    allow_export:  form.value.allowExport,
    allow_comment: form.value.allowComment && props.commentsGloballyEnabled,
    allow_download: form.value.allowDownload,
    min_rating:   form.value.minRating,
    recursive:    !!form.value.recursive,
    depth:        Number(form.value.depth) || 0,
  }

  if (form.value.guestName.trim()) {
    body.guest_name = form.value.guestName.trim()
  }

  // Passwort-Logik:
  // - Create: gefülltes Feld → Passwort setzen, leer → kein Passwort
  // - Edit: removePassword=true → password=null senden (entfernt es), sonst
  //   gefülltes Feld → ändern, leeres Feld + removePassword=false → key NICHT
  //   senden (Backend lässt bestehenden Hash unangetastet via array_key_exists-
  //   Check in updateShare).
  if (isEditMode.value) {
    if (form.value.removePassword) {
      body.password = null
    } else if (form.value.password) {
      body.password = form.value.password
    }
    // sonst: kein password-Key im Body
  } else if (form.value.password) {
    body.password = form.value.password
  }

  if (form.value.expiresDate) {
    // Datum in Unix-Timestamp (End des Tages, lokale Zeit)
    const d = new Date(form.value.expiresDate + 'T23:59:59')
    body.expires_at = Math.floor(d.getTime() / 1000)
  } else if (isEditMode.value) {
    // Edit-Mode + Feld geleert → explizit null senden, damit das Backend
    // (array_key_exists) das Ablaufdatum entfernt. Ohne den Key bliebe der
    // alte Wert stehen. Bei Anlage ohne Datum sendet man den Key gar nicht
    // (Default = kein Ablauf).
    body.expires_at = null
  }

  try {
    if (isEditMode.value) {
      const url = generateUrl(`/apps/starrate/api/share/${props.editShare.token}`)
      const { data } = await axios.put(url, body)
      emit('updated', data.share ?? data)
      emit('close')
    } else {
      const url = generateUrl('/apps/starrate/api/share')
      const { data } = await axios.post(url, body)
      createdShare.value = data.share
      emit('created', data.share)
    }
  } catch (e) {
    formError.value = e?.response?.data?.error
      ?? t('starrate', isEditMode.value ? 'Fehler beim Speichern' : 'Fehler beim Erstellen des Links')
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.sr-share-modal__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9000;
}

.sr-share-modal {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  width: min(480px, 95vw);
  max-height: 90vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.sr-share-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem 1rem;
  border-bottom: 1px solid #2a2a3e;
  flex-shrink: 0;
}
.sr-share-modal__title {
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}
.sr-share-modal__close {
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
  line-height: 1;
}
.sr-share-modal__close:hover { color: #d4d4d8; }

.sr-share-modal__form,
.sr-share-modal__success {
  padding: 1.25rem 1.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.sr-share-modal__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.sr-share-modal__label {
  font-size: 0.8rem;
  color: #a1a1aa;
  font-weight: 500;
}
.sr-share-modal__optional {
  color: #52525b;
  font-weight: 400;
}
.sr-share-modal__required {
  color: #e94560;
  font-weight: 400;
}

.sr-share-modal__input,
.sr-share-modal__select {
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.875rem;
  padding: 0.45rem 0.75rem;
  width: 100%;
  box-sizing: border-box;
}
.sr-share-modal__input:focus,
.sr-share-modal__select:focus {
  outline: none;
  border-color: #e94560;
}
.sr-share-modal__input--readonly {
  color: #a1a1aa;
  cursor: default;
}
.sr-share-modal__pw-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.sr-share-modal__input--pw {
  padding-right: 2.25rem;
}
.sr-share-modal__pw-eye {
  position: absolute;
  right: 0.5rem;
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  padding: 0;
  display: flex;
  align-items: center;
}
.sr-share-modal__pw-eye:hover { color: #d4d4d8; }
.sr-share-modal__select option { background: #16213e; }

.sr-share-modal__toggle-group {
  display: flex;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  overflow: hidden;
}
.sr-share-modal__toggle {
  flex: 1;
  background: none;
  border: none;
  color: #71717a;
  cursor: pointer;
  font-size: 0.875rem;
  padding: 0.45rem 0.5rem;
  transition: background 0.15s, color 0.15s;
}
.sr-share-modal__toggle:not(:last-child) { border-right: 1px solid #3f3f5a; }

/* NC setzt globale button:focus/:active-Styles (Hintergrund + box-shadow, teils
   mit !important). Ohne Gegenwehr „wechselte" der Toggle die Farbe, sobald er
   nach dem Klick den Fokus behielt und man danach weitere Felder im Dialog
   bediente. Interaktionszustände hier explizit festnageln — aktiv=rot,
   inaktiv=transparent, stabil in jedem Zustand. */
/* NCs box-shadow-Artefakt unterdrücken, aber den Tastatur-Fokus SICHTBAR halten
   (a11y): outline nur bei Maus/Touch (:focus/:active) weg, per :focus-visible
   ein klarer Ring. */
.sr-share-modal__toggle:focus,
.sr-share-modal__toggle:active {
  box-shadow: none !important;
  outline: none;
}
.sr-share-modal__toggle:focus-visible {
  box-shadow: none !important;
  outline: 2px solid #fff;
  outline-offset: -2px;
}
/* Inaktiv: Ruhezustand transparent, Hover = light grey (Affordanz). Focus/active
   dürfen NICHT ausgrauen, nur Hover. */
.sr-share-modal__toggle:not(.sr-share-modal__toggle--active) {
  background: none !important;
}
.sr-share-modal__toggle:not(.sr-share-modal__toggle--active):hover {
  background: rgba(255,255,255,0.08) !important;
  color: #d4d4d8;
}
/* Aktiv: bleibt IMMER rot (auch mit Fokus nach Klick), Hover nur ein Ticken
   heller — kein Umschlagen auf NCs Grau. */
.sr-share-modal__toggle--active,
.sr-share-modal__toggle--active:focus,
.sr-share-modal__toggle--active:active {
  background: #e94560 !important;
  color: #fff !important;
}
.sr-share-modal__toggle--active:hover {
  background: #f0637c !important;
  color: #fff !important;
}

.sr-share-modal__checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: #a1a1aa;
  font-size: 0.85rem;
  cursor: pointer;
}
.sr-share-modal__checkbox {
  accent-color: #4caf50;
  width: 16px;
  height: 16px;
  cursor: pointer;
}

.sr-share-modal__error {
  color: #e94560;
  font-size: 0.8rem;
  margin: 0;
}

.sr-share-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

.sr-share-modal__btn {
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.875rem;
  padding: 0.5rem 1.25rem;
}
.sr-share-modal__btn--primary {
  background: #e94560;
  color: #fff;
}
.sr-share-modal__btn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
.sr-share-modal__btn--secondary {
  background: #2a2a3e;
  color: #a1a1aa;
}
.sr-share-modal__btn--secondary:hover { color: #d4d4d8; }

/* Success view */
.sr-share-modal__success-label {
  color: #a1a1aa;
  font-size: 0.8rem;
  margin: 0;
}
.sr-share-modal__link-row {
  display: flex;
  gap: 0.5rem;
}
.sr-share-modal__link-input {
  flex: 1;
  background: #0f0f1a;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #d4d4d8;
  font-size: 0.8rem;
  padding: 0.45rem 0.75rem;
  min-width: 0;
}
.sr-share-modal__copy-btn {
  background: #2a2a3e;
  border: 1px solid #3f3f5a;
  border-radius: 6px;
  color: #a1a1aa;
  cursor: pointer;
  font-size: 0.8rem;
  padding: 0.45rem 0.75rem;
  white-space: nowrap;
  flex-shrink: 0;
}
.sr-share-modal__copy-btn--done { color: #4caf50; border-color: #4caf50; }
.sr-share-modal__success-hint {
  background: #0f0f1a;
  border: 1px solid #2a2a3e;
  border-radius: 6px;
  color: #a1a1aa;
  font-size: 0.8rem;
  line-height: 1.6;
  margin: 0;
  padding: 0.75rem;
}
</style>
