import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import ShareModal from '../../src/components/ShareModal.vue'

describe('ShareModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const factory = (props = {}) => mount(ShareModal, {
    props: { ncPath: '/Fotos/Test', ...props },
    global: { stubs: { Teleport: true } },
  })

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert Formular initial', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__form').exists()).toBe(true)
    expect(w.find('.sr-share-modal__success').exists()).toBe(false)
  })

  it('zeigt Ordner-Pfad als readonly Input', () => {
    const w = factory({ ncPath: '/Fotos/Urlaub' })
    const input = w.find('.sr-share-modal__input--readonly')
    expect(input.element.value).toBe('/Fotos/Urlaub')
    expect(input.attributes('readonly')).toBeDefined()
  })

  it('zeigt Pflichtfeld-Stern bei permissions=rate', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__required').exists()).toBe(true)
  })

  it('zeigt "optional" bei permissions=view', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    expect(w.find('.sr-share-modal__optional').exists()).toBe(true)
  })

  it('Header hat Close-Button', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__close').exists()).toBe(true)
  })

  // ── Permission Toggle ─────────────────────────────────────────────────────

  it('startet mit permissions=rate', () => {
    const w = factory()
    const toggles = w.findAll('.sr-share-modal__toggle')
    expect(toggles[1].classes()).toContain('sr-share-modal__toggle--active')
  })

  it('wechselt zu view per Klick', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click')
    expect(w.findAll('.sr-share-modal__toggle')[0].classes()).toContain('sr-share-modal__toggle--active')
  })

  // ── Allow Pick Checkbox ───────────────────────────────────────────────────

  it('zeigt Pick-Checkbox bei permissions=rate', () => {
    const w = factory()
    expect(w.find('[data-testid="allow-pick"]').exists()).toBe(true)
  })

  it('versteckt Pick-Checkbox bei permissions=view', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    expect(w.find('[data-testid="allow-pick"]').exists()).toBe(false)
  })

  it('Sterne- und Farb-Checkbox bei permissions=rate standardmäßig an', () => {
    const w = factory()
    expect(w.find('[data-testid="allow-rating"]').element.checked).toBe(true)
    expect(w.find('[data-testid="allow-color"]').element.checked).toBe(true)
  })

  it('übernimmt eigene Bewertungsarten als Vorbelegung für neue Links', () => {
    const w = factory({ ratingDefault: false })
    expect(w.find('[data-testid="allow-rating"]').element.checked).toBe(false)
    expect(w.find('[data-testid="allow-color"]').element.checked).toBe(true)
  })

  // ── Passwort ──────────────────────────────────────────────────────────────

  it('Passwort-Feld initial als password-Typ', () => {
    const w = factory()
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('password')
  })

  it('Eye-Button toggled Passwort-Sichtbarkeit', async () => {
    const w = factory()
    const eye = w.find('.sr-share-modal__pw-eye')
    await eye.trigger('click')
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('text')
    await eye.trigger('click')
    expect(w.find('.sr-share-modal__input--pw').attributes('type')).toBe('password')
  })

  // ── Validierung ───────────────────────────────────────────────────────────

  it('zeigt Fehler wenn Rate ohne Gast-Name', async () => {
    const w = factory()
    await w.find('.sr-share-modal__form').trigger('submit')
    expect(w.find('.sr-share-modal__error').exists()).toBe(true)
    expect(axios.post).not.toHaveBeenCalled()
  })

  it('kein Fehler bei View ohne Gast-Name', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'abc123' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()
    expect(w.find('.sr-share-modal__error').exists()).toBe(false)
    expect(axios.post).toHaveBeenCalled()
  })

  it('Whitespace-Only Name wird als leer behandelt', async () => {
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('   ')
    await w.find('.sr-share-modal__form').trigger('submit')
    expect(w.find('.sr-share-modal__error').exists()).toBe(true)
  })

  // ── Erfolgreicher Create ──────────────────────────────────────────────────

  it('zeigt Erfolgs-View nach Create', async () => {
    axios.post.mockResolvedValueOnce({
      data: { share: { token: 'test-token-123', nc_path: '/Fotos/Test', permissions: 'rate' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__success').exists()).toBe(true)
    expect(w.find('.sr-share-modal__form').exists()).toBe(false)
  })

  it('emittiert created-Event nach Erfolg', async () => {
    const share = { token: 'abc', nc_path: '/Fotos/Test', permissions: 'rate' }
    axios.post.mockResolvedValueOnce({ data: { share } })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.emitted('created')?.[0]).toEqual([share])
  })

  it('Submit-Button zeigt "Erstelle…" während des Speicherns', async () => {
    let resolvePost
    axios.post.mockReturnValueOnce(new Promise(r => { resolvePost = r }))
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    w.find('.sr-share-modal__form').trigger('submit')
    await w.vm.$nextTick()

    const btn = w.find('.sr-share-modal__btn--primary')
    expect(btn.text()).toContain('Erstelle')
    expect(btn.attributes('disabled')).toBeDefined()

    resolvePost({ data: { share: { token: 'x' } } })
    await flushPromises()
  })

  // ── API-Payload ───────────────────────────────────────────────────────────

  it('sendet korrekten Payload mit allen Feldern', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory({ ncPath: '/Bilder' })

    await w.find('.sr-share-modal__input[type="text"]').setValue('Anna')
    await w.find('[data-testid="allow-pick"]').setValue(true) // allowPick
    await w.find('.sr-share-modal__select').setValue(3) // minRating
    await w.find('.sr-share-modal__input--pw').setValue('geheim')
    await w.find('input[type="date"]').setValue('2026-12-31')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const [url, body] = axios.post.mock.calls[0]
    expect(url).toContain('/apps/starrate/api/share')
    expect(body.nc_path).toBe('/Bilder')
    expect(body.guest_name).toBe('Anna')
    expect(body.permissions).toBe('rate')
    expect(body.allow_pick).toBe(true)
    expect(body.min_rating).toBe(3)
    expect(body.password).toBe('geheim')
    expect(body.expires_at).toBeGreaterThan(0)
    expect(body.allow_download).toBe(false)
  })

  it('sendet allow_download=true wenn aktiviert', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()

    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view → kein Gast-Name nötig
    await w.find('[data-testid="allow-download"]').setValue(true)
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body.allow_download).toBe(true)
  })

  it('sendet allow_pick=false bei permissions=view auch wenn Checkbox war true', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()

    // Erst rate + allowPick, dann zu view wechseln
    await w.find('[data-testid="allow-pick"]').setValue(true)
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body.allow_pick).toBe(false)
  })

  it('sendet allow_rating=false wenn nur Farben und Pick erlaubt sind', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()

    await w.find('.sr-share-modal__input[type="text"]').setValue('Anna')
    await w.find('[data-testid="allow-rating"]').setValue(false)
    await w.find('[data-testid="allow-pick"]').setValue(true)
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body.allow_rating).toBe(false)
    expect(body.allow_color).toBe(true)
    expect(body.allow_pick).toBe(true)
  })

  it('verlangt mindestens eine Bewertungsart bei permissions=rate', async () => {
    const w = factory()

    await w.find('.sr-share-modal__input[type="text"]').setValue('Anna')
    await w.find('[data-testid="allow-rating"]').setValue(false)
    await w.find('[data-testid="allow-color"]').setValue(false)
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(axios.post).not.toHaveBeenCalled()
    expect(w.text()).toContain('Bitte mindestens eine Bewertungsart erlauben.')
  })

  it('sendet allow_rating/allow_color=true bei permissions=view', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()

    await w.find('[data-testid="allow-rating"]').setValue(false)
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body.allow_rating).toBe(true)
    expect(body.allow_color).toBe(true)
  })

  it('sendet kein Passwort wenn leer', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click') // view
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body).not.toHaveProperty('password')
  })

  it('sendet kein expires_at wenn Datum leer', async () => {
    axios.post.mockResolvedValueOnce({ data: { share: { token: 'x' } } })
    const w = factory()
    await w.findAll('.sr-share-modal__toggle')[0].trigger('click')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    const body = axios.post.mock.calls[0][1]
    expect(body).not.toHaveProperty('expires_at')
  })

  // ── API-Fehler ────────────────────────────────────────────────────────────

  it('zeigt Server-Fehlermeldung', async () => {
    axios.post.mockRejectedValueOnce({
      response: { data: { error: 'Ordner nicht gefunden' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__error').text()).toContain('Ordner nicht gefunden')
  })

  it('zeigt generische Fehlermeldung bei unbekanntem Fehler', async () => {
    axios.post.mockRejectedValueOnce(new Error('Network Error'))
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__error').text()).toContain('Fehler')
  })

  it('setzt saving=false nach Fehler', async () => {
    axios.post.mockRejectedValueOnce(new Error())
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Test')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__btn--primary').attributes('disabled')).toBeUndefined()
  })

  // ── Reset / Weiteren Link erstellen ───────────────────────────────────────

  it('"Weiteren Link erstellen" setzt Formular zurück', async () => {
    axios.post.mockResolvedValueOnce({
      data: { share: { token: 'abc', nc_path: '/Test', permissions: 'rate' } },
    })
    const w = factory()
    await w.find('.sr-share-modal__input[type="text"]').setValue('Tester')
    await w.find('.sr-share-modal__form').trigger('submit')
    await flushPromises()

    expect(w.find('.sr-share-modal__success').exists()).toBe(true)
    await w.find('.sr-share-modal__btn--secondary').trigger('click')
    expect(w.find('.sr-share-modal__form').exists()).toBe(true)
    expect(w.find('.sr-share-modal__success').exists()).toBe(false)
  })

  // ── Close Events ──────────────────────────────────────────────────────────

  it('Close-Button emittiert close', async () => {
    const w = factory()
    await w.find('.sr-share-modal__close').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })

  it('Abbrechen-Button emittiert close', async () => {
    const w = factory()
    await w.findAll('.sr-share-modal__btn--secondary')[0].trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })

  it('Overlay-Klick emittiert close', async () => {
    const w = factory()
    await w.find('.sr-share-modal__overlay').trigger('click')
    expect(w.emitted('close')).toBeTruthy()
  })

  // ── Recursive-Felder (V1.3.1) ─────────────────────────────────────────────

  it('Recursive-Felder hidden wenn recursionEnabled=false (Default)', () => {
    const w = factory()
    expect(w.find('[data-testid="recursive"]').exists()).toBe(false)
  })

  it('Recursive-Checkbox sichtbar wenn recursionEnabled=true', () => {
    const w = factory({ recursionEnabled: true })
    expect(w.find('[data-testid="recursive"]').exists()).toBe(true)
  })

  it('Tiefe-Dropdown nur sichtbar wenn recursive UND recursionEnabled', async () => {
    const w = factory({ recursionEnabled: true, recursiveDefault: false })
    // Bei initial recursive=false → Tiefe versteckt
    expect(w.find('.sr-share-modal__select').exists()).toBe(true)  // minRating-Select existiert
    // Tiefe-Select ist nicht der einzige select; check ob "Gruppen-Tiefe"-Label da
    expect(w.text()).not.toContain('Gruppen-Tiefe')

    // Recursive an → Tiefe-Label sichtbar
    await w.find('[data-testid="recursive"]').setValue(true)
    expect(w.text()).toContain('Gruppen-Tiefe')
  })

  it('Recursive-Default (settings) wird beim Anlegen vorbefüllt', () => {
    const w = factory({
      recursionEnabled: true,
      recursiveDefault: true,
      recursiveDefaultDepth: 2,
    })
    expect(w.find('[data-testid="recursive"]').element.checked).toBe(true)
  })

  // ── Edit-Mode (V1.3.1) ────────────────────────────────────────────────────

  const sampleShare = {
    token: 'abc123',
    nc_path: '/Fotos/Wedding',
    permissions: 'rate',
    allow_pick: true,
    allow_export: false,
    allow_comment: false,
    min_rating: 3,
    recursive: true,
    depth: 2,
    has_password: true,
    expires_at: null,
    guest_name: 'Sarah',
    active: true,
  }

  it('Edit-Mode: Titel ändert sich auf "Freigabe bearbeiten"', () => {
    const w = factory({ editShare: sampleShare })
    expect(w.find('.sr-share-modal__title').text()).toContain('bearbeiten')
  })

  it('Edit-Mode: Submit-Button hat Label "Speichern"', () => {
    const w = factory({ editShare: sampleShare })
    expect(w.find('.sr-share-modal__btn--primary').text()).toContain('Speichern')
  })

  it('Edit-Mode: Pfad-Input ist editierbar (nicht readonly)', () => {
    const w = factory({ editShare: sampleShare })
    // Im Edit-Mode kein readonly-Input mehr, sondern editierbarer
    expect(w.find('.sr-share-modal__input--readonly').exists()).toBe(false)
    const inputs = w.findAll('input.sr-share-modal__input[type="text"]')
    // Erstes Text-Input ist der Pfad
    expect(inputs[0].element.value).toBe('/Fotos/Wedding')
  })

  it('Edit-Mode: Felder werden aus Share vorbefüllt', () => {
    const w = factory({
      editShare: sampleShare,
      recursionEnabled: true,
    })
    // Permissions
    const toggles = w.findAll('.sr-share-modal__toggle')
    expect(toggles[1].classes()).toContain('sr-share-modal__toggle--active') // 'rate'
    // Pick-Checkbox
    expect(w.find('[data-testid="allow-pick"]').element.checked).toBe(true)
    // Recursive
    expect(w.find('[data-testid="recursive"]').element.checked).toBe(true)
    // GuestName
    expect(w.findAll('input[type="text"]')[1].element.value).toBe('Sarah')
  })

  it('Edit-Mode: Passwort-Feld leer, Remove-Checkbox bei has_password=true sichtbar', () => {
    const w = factory({ editShare: sampleShare })
    expect(w.find('.sr-share-modal__input--pw').element.value).toBe('')
    expect(w.text()).toContain('Passwort entfernen')
  })

  it('Edit-Mode: Remove-Checkbox bei has_password=false NICHT sichtbar', () => {
    const w = factory({ editShare: { ...sampleShare, has_password: false } })
    expect(w.text()).not.toContain('Passwort entfernen')
  })

  it('Edit-Submit ruft PUT, nicht POST', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: { ...sampleShare } } })
    const w = factory({ editShare: sampleShare })
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(axios.put).toHaveBeenCalled()
    expect(axios.post).not.toHaveBeenCalled()
    // URL enthält den Token
    expect(axios.put.mock.calls[0][0]).toContain(sampleShare.token)
  })

  it('Edit-Submit: leeres Passwort + removePassword=false → kein password-Key im Body', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const w = factory({ editShare: sampleShare })
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = axios.put.mock.calls[0][1]
    expect(body).not.toHaveProperty('password')
  })

  it('Edit-Submit: removePassword=true → password=null im Body', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const w = factory({ editShare: sampleShare })
    // Remove-Checkbox aktivieren
    const checkboxes = w.findAll('.sr-share-modal__checkbox')
    const removeCheckbox = checkboxes[checkboxes.length - 1] // letzte Checkbox = Remove
    await removeCheckbox.setValue(true)
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = axios.put.mock.calls[0][1]
    expect(body.password).toBeNull()
  })

  it('Edit-Submit: geleertes Ablaufdatum → expires_at=null im Body', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const future = Math.floor(Date.now() / 1000) + 86400
    const w = factory({ editShare: { ...sampleShare, expires_at: future } })
    // Datum-Feld ist aus dem Share vorbefüllt → jetzt leeren
    const dateInput = w.find('input[type="date"]')
    expect(dateInput.element.value).not.toBe('')
    await dateInput.setValue('')
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = axios.put.mock.calls[0][1]
    expect(body.expires_at).toBeNull()
  })

  it('Edit-Submit: unverändertes Ablaufdatum bleibt als Timestamp erhalten', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const future = Math.floor(Date.now() / 1000) + 86400
    const w = factory({ editShare: { ...sampleShare, expires_at: future } })
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = axios.put.mock.calls[0][1]
    expect(body.expires_at).toBeGreaterThan(Math.floor(Date.now() / 1000))
  })

  it('Edit-Submit emittiert updated und close', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const w = factory({ editShare: sampleShare })
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.emitted('updated')).toBeTruthy()
    expect(w.emitted('close')).toBeTruthy()
  })

  it('Edit-Submit sendet recursive + depth', async () => {
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    const w = factory({ editShare: sampleShare, recursionEnabled: true })
    await w.find('form').trigger('submit')
    await flushPromises()
    const body = axios.put.mock.calls[0][1]
    expect(body.recursive).toBe(true)
    expect(body.depth).toBe(2)
  })

  // ── Path-Blur Re-Resolve (Sarah-Use-Case) ────────────────────────────────

  it('Edit-Mode: Path-Blur bei recursionEnabled löst Re-Resolve aus Settings aus', async () => {
    // localStorage leer → Settings-Default greift
    localStorage.clear()
    const w = factory({
      editShare: { ...sampleShare, recursive: true, depth: 2 },
      recursionEnabled: true,
      recursiveDefault: false,
      recursiveDefaultDepth: 0,
    })
    // Initial: aus Share, recursive=true, depth=2
    expect(w.find('[data-testid="recursive"]').element.checked).toBe(true)

    // Pfad ändern + blur → Defaults aus Settings übernehmen (false, 0)
    const pathInput = w.findAll('input.sr-share-modal__input[type="text"]')[0]
    await pathInput.setValue('/AnderesFolder')
    await pathInput.trigger('blur')
    expect(w.find('[data-testid="recursive"]').element.checked).toBe(false)
  })

  it('Edit-Mode: Path-Blur ohne recursionEnabled hat keinen Effekt', async () => {
    const w = factory({
      editShare: { ...sampleShare, recursive: true, depth: 2 },
      recursionEnabled: false,
    })
    const pathInput = w.findAll('input.sr-share-modal__input[type="text"]')[0]
    await pathInput.setValue('/Anders')
    await pathInput.trigger('blur')
    // Recursive-Feld ist gar nicht gerendert, aber form.recursive bleibt true
    // (kein Reset) — wird beim Submit weiterhin true sein
    axios.put.mockResolvedValueOnce({ data: { share: sampleShare } })
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(axios.put.mock.calls[0][1].recursive).toBe(true)
  })
})
