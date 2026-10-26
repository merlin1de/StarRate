import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { defineComponent } from 'vue'
import axios from '@nextcloud/axios'
import GuestGallery from '../../src/views/GuestGallery.vue'

// Gallery stub — verhindert Mount der kompletten Gallery-Komponente
const GalleryStub = defineComponent({
  name: 'Gallery',
  props: {
    guestMode:       { type: Boolean, default: false },
    guestLabel:      { type: String,  default: '' },
    enableRatingOverride: { type: Boolean, default: true },
    enableColorOverride:  { type: Boolean, default: true },
    enablePickOverride: { type: Boolean, default: false },
    allowExport:     { type: Boolean, default: false },
    allowComment:    { type: Boolean, default: false },
    commentApi:      { type: Object, default: null },
    loadImagesFn:    { type: Function, default: null },
    rateFn:          { type: Function, default: null },
    batchRateFn:     { type: Function, default: null },
    thumbnailUrlFn:  { type: Function, default: null },
    previewUrlFn:    { type: Function, default: null },
  },
  template: '<div class="gallery-stub" />',
})

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: {} },
      { path: '/folder/:path(.*)', component: {} },
    ],
  })
}

function factory(props = {}) {
  return mount(GuestGallery, {
    props: { token: 'tok123', canRate: false, guestName: 'Testgast', ...props },
    global: {
      plugins: [makeRouter()],
      stubs: { Gallery: GalleryStub },
    },
  })
}

// ── Hilfsfunktion: Gallery-Stub-Props auslesen ─────────────────────────────────
function galleryProps(w) {
  return w.findComponent(GalleryStub).props()
}

describe('GuestGallery', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  // ── Initiales Rendering ──────────────────────────────────────────────────────

  it('rendert Gallery (kein Passwort-Dialog) beim ersten Mount', () => {
    const w = factory()
    expect(w.find('.gallery-stub').exists()).toBe(true)
    expect(w.find('.sr-guest-pw__overlay').exists()).toBe(false)
  })

  it('übergibt guestMode=true und guestLabel an Gallery', () => {
    const w = factory({ guestName: 'Max' })
    const p = galleryProps(w)
    expect(p.guestMode).toBe(true)
    expect(p.guestLabel).toBe('Max')
  })

  it('übergibt die Bewertungsarten des Shares als Overrides an Gallery', () => {
    const p = galleryProps(factory({ allowRating: false, allowColor: true, allowPick: true }))
    expect(p.enableRatingOverride).toBe(false)
    expect(p.enableColorOverride).toBe(true)
    expect(p.enablePickOverride).toBe(true)
  })

  it('übergibt alle Callback-Props an Gallery', () => {
    const w = factory()
    const p = galleryProps(w)
    expect(typeof p.loadImagesFn).toBe('function')
    expect(typeof p.rateFn).toBe('function')
    expect(typeof p.batchRateFn).toBe('function')
    expect(typeof p.thumbnailUrlFn).toBe('function')
    expect(typeof p.previewUrlFn).toBe('function')
  })

  // ── loadImagesFn ─────────────────────────────────────────────────────────────

  it('loadImagesFn ruft korrekte API-URL auf', async () => {
    axios.get.mockResolvedValue({ data: { images: [], folders: [] } })
    const w = factory()
    const { loadImagesFn } = galleryProps(w)

    await loadImagesFn('/Fotos')
    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/images'),
      expect.objectContaining({ params: { path: '/Fotos' } })
    )
  })

  it('loadImagesFn zeigt Passwort-Dialog bei 401', async () => {
    axios.get.mockRejectedValue({ response: { status: 401 } })
    const w = factory()
    const { loadImagesFn } = galleryProps(w)

    await expect(loadImagesFn('/')).rejects.toBeDefined()
    await flushPromises()
    expect(w.find('.sr-guest-pw__overlay').exists()).toBe(true)
    expect(w.find('.gallery-stub').exists()).toBe(false)
  })

  it('loadImagesFn gibt Daten zurück bei Erfolg', async () => {
    const data = { images: [{ id: 1, name: 'foto.jpg' }], folders: [] }
    axios.get.mockResolvedValue({ data })
    const w = factory()
    const { loadImagesFn } = galleryProps(w)

    const result = await loadImagesFn('/')
    expect(result).toEqual(data)
  })

  // ── Passwort-Dialog ──────────────────────────────────────────────────────────

  it('verifyPassword sendet Passwort an korrekten Endpunkt', async () => {
    // 401 triggert Dialog
    axios.get.mockRejectedValue({ response: { status: 401 } })
    axios.post.mockResolvedValue({ data: {} })

    const w = factory()
    await expect(galleryProps(w).loadImagesFn('/')).rejects.toBeDefined()
    await flushPromises()

    await w.find('.sr-guest-pw__input').setValue('geheim')
    await w.find('.sr-guest-pw__btn').trigger('click')
    await flushPromises()

    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/verify'),
      { password: 'geheim' }
    )
  })

  it('schließt Passwort-Dialog nach korrektem Passwort', async () => {
    axios.get.mockRejectedValue({ response: { status: 401 } })
    axios.post.mockResolvedValue({ data: {} })

    const w = factory()
    await expect(galleryProps(w).loadImagesFn('/')).rejects.toBeDefined()
    await flushPromises()

    await w.find('.sr-guest-pw__input').setValue('richtig')
    await w.find('.sr-guest-pw__btn').trigger('click')
    await flushPromises()

    expect(w.find('.sr-guest-pw__overlay').exists()).toBe(false)
    expect(w.find('.gallery-stub').exists()).toBe(true)
  })

  it('zeigt Fehlermeldung bei falschem Passwort', async () => {
    axios.get.mockRejectedValue({ response: { status: 401 } })
    axios.post.mockRejectedValue(new Error('Forbidden'))

    const w = factory()
    await expect(galleryProps(w).loadImagesFn('/')).rejects.toBeDefined()
    await flushPromises()

    await w.find('.sr-guest-pw__input').setValue('falsch')
    await w.find('.sr-guest-pw__btn').trigger('click')
    await flushPromises()

    expect(w.find('.sr-guest-pw__error').text()).toBeTruthy()
    expect(w.find('.sr-guest-pw__overlay').exists()).toBe(true)
  })

  it('Bestätigen-Button ist disabled solange Eingabe leer', async () => {
    axios.get.mockRejectedValue({ response: { status: 401 } })
    const w = factory()
    await expect(galleryProps(w).loadImagesFn('/')).rejects.toBeDefined()
    await flushPromises()

    expect(w.find('.sr-guest-pw__btn').attributes('disabled')).toBeDefined()
  })

  // ── rateFn ───────────────────────────────────────────────────────────────────

  it('rateFn sendet Request wenn canRate=true', async () => {
    axios.post.mockResolvedValue({ data: {} })
    const w = factory({ canRate: true })
    const { rateFn } = galleryProps(w)

    await rateFn(99, { rating: 4 })
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/rate'),
      expect.objectContaining({ file_id: 99, rating: 4, guest_name: 'Testgast' }),
      expect.any(Object)
    )
  })

  it('rateFn sendet keinen Request wenn canRate=false', async () => {
    const w = factory({ canRate: false })
    const { rateFn } = galleryProps(w)

    await rateFn(99, { rating: 4 })
    expect(axios.post).not.toHaveBeenCalled()
  })

  // ── batchRateFn ──────────────────────────────────────────────────────────────

  it('batchRateFn sendet Request für jede ID', async () => {
    axios.post.mockResolvedValue({ data: {} })
    const w = factory({ canRate: true })
    const { batchRateFn } = galleryProps(w)

    await batchRateFn([1, 2, 3], { rating: 5 })
    expect(axios.post).toHaveBeenCalledTimes(3)
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/rate'),
      expect.objectContaining({ file_id: 1, rating: 5 }),
      expect.any(Object)
    )
  })

  it('batchRateFn sendet keinen Request wenn canRate=false', async () => {
    const w = factory({ canRate: false })
    const { batchRateFn } = galleryProps(w)

    await batchRateFn([1, 2, 3], { rating: 5 })
    expect(axios.post).not.toHaveBeenCalled()
  })

  // ── URL-Funktionen ────────────────────────────────────────────────────────────

  it('thumbnailUrlFn gibt korrekte URL zurück', () => {
    const w = factory()
    const { thumbnailUrlFn } = galleryProps(w)

    const url = thumbnailUrlFn(42, 320)
    expect(url).toContain('/guest/tok123/thumbnail/42')
    expect(url).toContain('width=320')
    expect(url).toContain('height=320')
  })

  it('thumbnailUrlFn verwendet 280px als Standard-Größe', () => {
    const w = factory()
    const url = galleryProps(w).thumbnailUrlFn(7)
    expect(url).toContain('width=280')
  })

  it('previewUrlFn gibt korrekte URL zurück', () => {
    const w = factory()
    const url = galleryProps(w).previewUrlFn(55)
    expect(url).toContain('/guest/tok123/preview/55')
  })

  // ── commentApi ──────────────────────────────────────────────────────────────

  it('übergibt allowComment=true und commentApi wenn allowComment-Prop gesetzt', () => {
    const w = factory({ allowComment: true })
    const p = galleryProps(w)
    expect(p.allowComment).toBe(true)
    expect(p.commentApi).toBeTruthy()
    expect(typeof p.commentApi.save).toBe('function')
    expect(typeof p.commentApi.load).toBe('function')
    expect(typeof p.commentApi.remove).toBe('function')
  })

  it('übergibt allowComment=false wenn nicht gesetzt', () => {
    const w = factory()
    const p = galleryProps(w)
    expect(p.allowComment).toBe(false)
  })

  it('commentApi.save sendet korrekte Daten', async () => {
    axios.post.mockResolvedValue({ data: { comment: 'Hallo', author_name: 'Gast', updated_at: 1713000000 } })
    const w = factory({ allowComment: true })
    const { commentApi } = galleryProps(w)

    const result = await commentApi.save(42, 'Hallo', 'Testgast')
    expect(axios.post).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/comment'),
      expect.objectContaining({ file_id: 42, comment: 'Hallo', guest_name: 'Testgast' }),
      expect.any(Object)
    )
    expect(result.comment).toBe('Hallo')
  })

  it('commentApi.load ruft GET-Endpunkt auf', async () => {
    axios.get.mockResolvedValue({ data: { comment: 'Toll', author_name: 'Anna', updated_at: 1713000000 } })
    const w = factory({ allowComment: true })
    const { commentApi } = galleryProps(w)

    const result = await commentApi.load(42)
    expect(axios.get).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/comment/42'),
      expect.any(Object)
    )
    expect(result.comment).toBe('Toll')
    expect(result.author_name).toBe('Anna')
    expect(result.updated_at).toBe(1713000000)
  })

  it('commentApi.load gibt null zurück bei Fehler', async () => {
    axios.get.mockRejectedValue(new Error('Network error'))
    const w = factory({ allowComment: true })
    const { commentApi } = galleryProps(w)

    const result = await commentApi.load(42)
    expect(result).toBeNull()
  })

  it('commentApi.remove ruft DELETE-Endpunkt auf', async () => {
    axios.delete.mockResolvedValue({ data: {} })
    const w = factory({ allowComment: true })
    const { commentApi } = galleryProps(w)

    await commentApi.remove(42)
    expect(axios.delete).toHaveBeenCalledWith(
      expect.stringContaining('/guest/tok123/comment/42'),
      expect.any(Object)
    )
  })
})
