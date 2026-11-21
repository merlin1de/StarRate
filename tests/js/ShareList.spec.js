import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import ShareList from '../../src/components/ShareList.vue'

const TOKEN = 'tok123'

function makeShare(overrides = {}) {
  return { token: TOKEN, nc_path: '/Fotos', permissions: 'view', guest_name: 'Anna', active: true, ...overrides }
}

function mockApi({ shares = [makeShare()], log = [] } = {}) {
  axios.get.mockImplementation(url => Promise.resolve({
    data: url.includes('/log') ? { log } : { shares },
  }))
}

async function factory() {
  const w = mount(ShareList, {
    props: { ncPath: '/Fotos' },
    global: { stubs: { Teleport: true } },
  })
  await flushPromises()
  return w
}

async function openLog(w) {
  await w.find('button[title="Bewertungs-Log anzeigen"]').trigger('click')
  await flushPromises()
}

describe('ShareList', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    axios.delete.mockResolvedValue({ data: { ok: true } })
  })

  // ── Liste ────────────────────────────────────────────────────────────────────

  it('zeigt den Leerzustand ohne Freigaben', async () => {
    mockApi({ shares: [] })
    const w = await factory()
    expect(w.find('.sr-share-list__empty').exists()).toBe(true)
  })

  it('listet Freigaben mit Pfad und Link', async () => {
    mockApi()
    const w = await factory()
    expect(w.text()).toContain('/Fotos')
    expect(w.find('input').element.value).toContain(`/guest/${TOKEN}`)
  })

  it('markiert abgelaufene Freigaben', async () => {
    mockApi({ shares: [makeShare({ expires_at: 1000 })] })
    const w = await factory()
    expect(w.find('.sr-share-list__badge--expired').exists()).toBe(true)
  })

  it('zeigt das Nachricht-Badge nur bei Freigaben mit Nachricht', async () => {
    mockApi({ shares: [makeShare({ guest_message: 'Nur zur Auswahl' })] })
    const withMessage = await factory()
    expect(withMessage.find('[title="Nachricht an den Empfänger"]').exists()).toBe(true)

    mockApi()
    const without = await factory()
    expect(without.find('[title="Nachricht an den Empfänger"]').exists()).toBe(false)
  })

  // ── Aktionen ─────────────────────────────────────────────────────────────────

  it('meldet Schließen, Neu und Bearbeiten an die Galerie', async () => {
    const share = makeShare()
    mockApi({ shares: [share] })
    const w = await factory()

    await w.find('.sr-share-list__close').trigger('click')
    await w.find('.sr-share-list__new-btn').trigger('click')
    await w.find('button[title="Bearbeiten"]').trigger('click')

    expect(w.emitted('close')).toHaveLength(1)
    expect(w.emitted('create')).toHaveLength(1)
    expect(w.emitted('edit')[0][0]).toEqual(share)
  })

  describe('Link kopieren', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('kopiert den Link und setzt die Bestätigung zurück', async () => {
      const writeText = vi.fn().mockResolvedValue()
      vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
      mockApi()
      const w = await factory()

      await w.find('.sr-share-list__copy').trigger('click')
      await flushPromises()
      expect(writeText).toHaveBeenCalledWith(expect.stringContaining(`/guest/${TOKEN}`))
      expect(w.find('.sr-share-list__copy--done').exists()).toBe(true)

      await vi.advanceTimersByTimeAsync(2000)
      expect(w.find('.sr-share-list__copy--done').exists()).toBe(false)
      vi.unstubAllGlobals()
    })
  })

  it('schaltet eine Freigabe um und übernimmt die Serverantwort', async () => {
    mockApi()
    axios.put.mockResolvedValue({ data: { share: makeShare({ active: false }) } })
    const w = await factory()

    await w.find('.sr-share-list__toggle-switch').trigger('click')
    await flushPromises()

    expect(axios.put).toHaveBeenCalledWith(expect.stringContaining(`/share/${TOKEN}`), { active: false })
    expect(w.find('.sr-share-list__toggle-switch--on').exists()).toBe(false)
  })

  it('löscht erst nach Bestätigung', async () => {
    mockApi()
    const w = await factory()

    await w.find('button[title="Link löschen"]').trigger('click')
    expect(axios.delete).not.toHaveBeenCalled()

    await w.find('.sr-share-list__confirm-yes').trigger('click')
    await flushPromises()

    expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining(`/share/${TOKEN}`))
    expect(w.find('.sr-share-list__empty').exists()).toBe(true)
  })

  it('Abbrechen beim Löschen behält die Freigabe', async () => {
    mockApi()
    const w = await factory()

    await w.find('button[title="Link löschen"]').trigger('click')
    await w.find('.sr-share-list__confirm-no').trigger('click')

    expect(axios.delete).not.toHaveBeenCalled()
    expect(w.text()).toContain('/Fotos')
  })

  // ── Log ──────────────────────────────────────────────────────────────────────

  it('lädt das Log beim ersten Öffnen und klappt es wieder zu', async () => {
    mockApi()
    const w = await factory()

    await openLog(w)
    expect(w.find('.sr-share-list__log').exists()).toBe(true)
    expect(w.find('.sr-share-list__log-empty').exists()).toBe(true)

    await openLog(w)
    expect(w.find('.sr-share-list__log').exists()).toBe(false)

    await openLog(w)
    const logCalls = axios.get.mock.calls.filter(([url]) => url.includes('/log'))
    expect(logCalls).toHaveLength(1)
  })

  it('zeigt die Bestätigung der Nachricht als Ereignis, nicht als Bewertung', async () => {
    mockApi({ log: [{ event: 'message_ack', guest_name: 'Anna', message: 'Nur zur Auswahl', timestamp: 1700000000 }] })
    const w = await factory()
    await openLog(w)

    const entry = w.find('.sr-share-list__log-entry--message_ack')
    expect(entry.text()).toContain('Anna')
    expect(entry.text()).not.toContain('☆')
    expect(entry.find('[title="Nur zur Auswahl"]').exists()).toBe(true)
  })

  it('zeigt Login, Fehlversuch und Bewertung jeweils passend', async () => {
    mockApi({
      log: [
        { event: 'login', guest_name: 'Anna', timestamp: 1700000000 },
        { event: 'login_failed', timestamp: 1700000001 },
        { file_id: 7, filename: 'IMG_7.jpg', guest_name: 'Anna', rating: 3, color: null, pick: null, timestamp: 1700000002 },
      ],
    })
    const w = await factory()
    await openLog(w)

    expect(w.find('.sr-share-list__log-entry--login').exists()).toBe(true)
    expect(w.find('.sr-share-list__log-entry--login_failed').exists()).toBe(true)
    expect(w.find('.sr-share-list__log-rating').text()).toBe('★★★☆☆')
    expect(w.find('.sr-share-list__log-file').text()).toBe('IMG_7.jpg')
  })

  it('leert das Log erst nach Bestätigung', async () => {
    mockApi({ log: [{ event: 'login', guest_name: 'Anna', timestamp: 1700000000 }] })
    const w = await factory()
    await openLog(w)

    await w.find('.sr-share-list__log-btn').trigger('click')
    expect(axios.delete).not.toHaveBeenCalled()

    await w.find('.sr-share-list__log .sr-share-list__confirm-yes').trigger('click')
    await flushPromises()

    expect(axios.delete).toHaveBeenCalledWith(expect.stringContaining(`/share/${TOKEN}/log`))
    expect(w.find('.sr-share-list__log-empty').exists()).toBe(true)
  })
})
