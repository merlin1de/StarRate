<template>
  <div class="sr-guest-message__overlay" @mousedown.self.prevent @click.self="dismiss">
    <div
      ref="dialogEl"
      class="sr-guest-message"
      tabindex="-1"
      role="dialog"
      aria-modal="true"
      aria-labelledby="sr-guest-message-title"
      aria-describedby="sr-guest-message-text"
    >
      <h2 id="sr-guest-message-title" class="sr-guest-message__title">
        {{ t('starrate', 'Nachricht zu dieser Galerie') }}
      </h2>
      <!-- Klartext: Interpolation, nie v-html. Umbrüche per white-space: pre-wrap. -->
      <p id="sr-guest-message-text" class="sr-guest-message__text">{{ message }}</p>
      <div class="sr-guest-message__actions">
        <button ref="buttonEl" class="sr-guest-message__btn" type="button" @click="$emit('close')">
          {{ dismissible ? t('starrate', 'Schließen') : t('starrate', 'Verstanden') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'

const props = defineProps({
  message:     { type: String,  required: true },
  // false beim ersten Anzeigen: nur der Button schließt (und bestätigt).
  // true beim erneuten Öffnen: auch Escape und Klick daneben.
  dismissible: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const dialogEl = ref(null)
const buttonEl = ref(null)

function dismiss() {
  if (props.dismissible) emit('close')
}

// Die Galerie dahinter hat Tastenkürzel (Sterne, Farben, Navigation). Ohne diese
// Sperre würde z.B. eine "3" beim Lesen das Bild dahinter bewerten. Capture auf
// document stoppt das Event, bevor es Grid/Loupe erreicht; Enter/Leertaste lösen
// den fokussierten Button trotzdem aus, das ist Browser-Standardverhalten.
function onKeydown(e) {
  e.stopPropagation()
  // Gehaltene Taste: darf nicht ungelesen bestätigen.
  if (e.repeat) {
    e.preventDefault()
    return
  }
  if (e.key === 'Escape') {
    e.preventDefault()
    dismiss()
    return
  }
  // Der Button ist das einzige Bedienelement: Tab führt immer zu ihm.
  if (e.key === 'Tab') {
    e.preventDefault()
    buttonEl.value?.focus()
  } else if (!dialogEl.value?.contains(e.target)) {
    e.preventDefault()
    dialogEl.value?.focus()
  }
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown, true)
  // Fokus auf den Dialog, NICHT auf den Button: sonst bestätigt ein Enter, das noch
  // unterwegs ist (z.B. vom Passwortfeld), die Nachricht, bevor sie gelesen wurde.
  dialogEl.value?.focus()
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown, true)
})
</script>

<style scoped>
.sr-guest-message__overlay {
  align-items: center;
  background: rgba(0, 0, 0, 0.75);
  display: flex;
  inset: 0;
  justify-content: center;
  position: fixed;
  z-index: 10000;
}
.sr-guest-message {
  background: #16213e;
  border: 1px solid #2a2a3e;
  border-radius: 12px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-height: 85vh;
  padding: 1.5rem;
  width: min(480px, 92vw);
}
.sr-guest-message:focus { outline: none; }
.sr-guest-message__title {
  color: #fff;
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0;
}
.sr-guest-message__text {
  color: #d4d4d8;
  font-size: 0.9rem;
  line-height: 1.5;
  margin: 0;
  overflow-wrap: anywhere;
  overflow-y: auto;
  white-space: pre-wrap;
}
.sr-guest-message__actions {
  display: flex;
  flex-shrink: 0;
  justify-content: flex-end;
}
.sr-guest-message__btn {
  background: #e94560;
  border: none;
  border-radius: 6px;
  color: #fff;
  cursor: pointer;
  font-size: 0.9rem;
  padding: 0.5rem 1.25rem;
}
.sr-guest-message__btn:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}
</style>
