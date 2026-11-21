import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import axios from '@nextcloud/axios'
import GuestGallery from '../../src/views/GuestGallery.vue'
import GuestMessageDialog from '../../src/components/GuestMessageDialog.vue'

const TOKEN = 'tok123'
const STORAGE_KEY = `sr_guest_message_${TOKEN}`

const GalleryStub = defineComponent({
  name: 'Gallery',
  props: {
    loadImagesFn:    { type: Function, default: null },
    hasGuestMessage: { type: Boolean, default: false },
  },
  emits: ['open-guest-message'],
  template: '<div class="gallery-stub" />',
})

function factory() {
  return mount(GuestGallery, {
    props: { token: TOKEN, guestName: 'Anna' },
    global: { stubs: { Gallery: GalleryStub } },
    attachTo: document.body,
  })
}

async function load(w, message) {
  axios.get.mockResolvedValue({ data: { images: [], folders: [], guest_message: message } })
  await w.findComponent(GalleryStub).props().loadImagesFn('')
  await flushPromises()
}

const dialog = w => w.findComponent(GuestMessageDialog)

describe('GuestGallery – Nachricht an den Gast', () => {
  let wrapper

  beforeEach(() => {
    vi.resetAllMocks()
    localStorage.clear()
    axios.post.mockResolvedValue({ data: { ok: true } })
    wrapper = factory()
  })

  afterEach(() => wrapper.unmount())

  it('zeigt vor dem Laden der Bilder keinen Dialog', () => {
    expect(dialog(wrapper).exists()).toBe(false)
    expect(wrapper.findComponent(GalleryStub).props().hasGuestMessage).toBe(false)
  })

  it('öffnet den Dialog, wenn die Nachricht noch nicht gesehen wurde', async () => {
    await load(wrapper, 'Nur zur Auswahl')
    expect(dialog(wrapper).props().message).toBe('Nur zur Auswahl')
    expect(wrapper.findComponent(GalleryStub).props().hasGuestMessage).toBe(true)
  })

  it('ohne Nachricht: kein Dialog und kein Button im Header', async () => {
    await load(wrapper, null)
    expect(dialog(wrapper).exists()).toBe(false)
    expect(wrapper.findComponent(GalleryStub).props().hasGuestMessage).toBe(false)
  })

  it('Bestätigen schickt das Ack und merkt den Text im localStorage', async () => {
    await load(wrapper, 'Nur zur Auswahl')
    await wrapper.find('.sr-guest-message__btn').trigger('click')
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining(`/guest/${TOKEN}/message/ack`),
      {},
      expect.any(Object),
    )
    expect(localStorage.getItem(STORAGE_KEY)).toBe('Nur zur Auswahl')
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('bleibt beim nächsten Besuch geschlossen, wenn der Text unverändert ist', async () => {
    localStorage.setItem(STORAGE_KEY, 'Nur zur Auswahl')
    const second = factory()
    await load(second, 'Nur zur Auswahl')
    expect(dialog(second).exists()).toBe(false)
    expect(second.findComponent(GalleryStub).props().hasGuestMessage).toBe(true)
    second.unmount()
  })

  it('erscheint erneut, wenn der Owner den Text ändert', async () => {
    localStorage.setItem(STORAGE_KEY, 'Alter Text')
    const second = factory()
    await load(second, 'Neuer Text')
    expect(dialog(second).props().message).toBe('Neuer Text')
    expect(dialog(second).props().dismissible).toBe(false)
    second.unmount()
  })

  it('erneutes Öffnen über den Header schickt kein weiteres Ack', async () => {
    await load(wrapper, 'Nur zur Auswahl')
    await wrapper.find('.sr-guest-message__btn').trigger('click')
    await flushPromises()
    axios.post.mockClear()

    wrapper.findComponent(GalleryStub).vm.$emit('open-guest-message')
    await flushPromises()
    expect(dialog(wrapper).props().dismissible).toBe(true)

    await wrapper.find('.sr-guest-message__btn').trigger('click')
    await flushPromises()
    expect(axios.post).not.toHaveBeenCalled()
    expect(dialog(wrapper).exists()).toBe(false)
  })

  it('fehlgeschlagenes Ack: kein Wiederöffnen beim Ordnerwechsel, aber nicht dauerhaft gemerkt', async () => {
    axios.post.mockRejectedValue(new Error('network'))
    await load(wrapper, 'Nur zur Auswahl')
    await wrapper.find('.sr-guest-message__btn').trigger('click')
    await flushPromises()

    await load(wrapper, 'Nur zur Auswahl')
    expect(dialog(wrapper).exists()).toBe(false)
    expect(localStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('entfernte Nachricht: gleicher Text später gilt wieder als neu', async () => {
    localStorage.setItem(STORAGE_KEY, 'Nur zur Auswahl')
    await load(wrapper, null)
    expect(localStorage.getItem(STORAGE_KEY)).toBeNull()

    await load(wrapper, 'Nur zur Auswahl')
    expect(dialog(wrapper).exists()).toBe(true)
  })

  it('401 beim Laden: Nachricht verschwindet hinter dem Passwort-Dialog', async () => {
    await load(wrapper, 'Geheim')
    axios.get.mockRejectedValue({ response: { status: 401 } })
    await expect(wrapper.findComponent(GalleryStub).props().loadImagesFn('')).rejects.toBeDefined()
    await flushPromises()

    expect(wrapper.find('.sr-guest-pw__overlay').exists()).toBe(true)
    expect(dialog(wrapper).exists()).toBe(false)
    expect(wrapper.html()).not.toContain('Geheim')
  })
})

describe('GuestMessageDialog', () => {
  const mountDialog = (props = {}) => mount(GuestMessageDialog, {
    props: { message: 'Text', ...props },
    attachTo: document.body,
  })

  it('rendert HTML als reinen Text', () => {
    const w = mountDialog({ message: '<img src=x onerror=alert(1)><b>fett</b>' })
    const text = w.find('.sr-guest-message__text')
    expect(text.element.children).toHaveLength(0)
    expect(text.text()).toBe('<img src=x onerror=alert(1)><b>fett</b>')
    w.unmount()
  })

  it('fokussiert den Dialog statt des Buttons, damit ein verirrtes Enter nicht bestätigt', () => {
    const w = mountDialog()
    expect(document.activeElement).toBe(w.find('.sr-guest-message').element)
    w.unmount()
  })

  it('Tab führt zum Button', () => {
    const w = mountDialog()
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))
    expect(document.activeElement).toBe(w.find('.sr-guest-message__btn').element)
    w.unmount()
  })

  it('gehaltene Enter-Taste löst den Button nicht aus', () => {
    const w = mountDialog()
    const e = new KeyboardEvent('keydown', { key: 'Enter', repeat: true, bubbles: true, cancelable: true })
    w.find('.sr-guest-message__btn').element.dispatchEvent(e)
    expect(e.defaultPrevented).toBe(true)
    w.unmount()
  })

  it('Tastenkürzel erreichen die Galerie nicht, solange der Dialog offen ist', () => {
    const shortcut = vi.fn()
    document.addEventListener('keydown', shortcut)
    const w = mountDialog()

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '3', bubbles: true }))
    w.find('.sr-guest-message__btn').element.dispatchEvent(new KeyboardEvent('keydown', { key: '3', bubbles: true }))
    expect(shortcut).not.toHaveBeenCalled()

    w.unmount()
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '3', bubbles: true }))
    expect(shortcut).toHaveBeenCalledOnce()
    document.removeEventListener('keydown', shortcut)
  })

  it('erstes Anzeigen: Escape und Klick daneben schließen nicht', async () => {
    const w = mountDialog({ dismissible: false })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await w.find('.sr-guest-message__overlay').trigger('click')
    expect(w.emitted('close')).toBeUndefined()
    w.unmount()
  })

  it('erneutes Öffnen: Escape und Klick daneben schließen', async () => {
    const w = mountDialog({ dismissible: true })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await w.find('.sr-guest-message__overlay').trigger('click')
    expect(w.emitted('close')).toHaveLength(2)
    w.unmount()
  })
})
