import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import WriteToFilesModal from '../../src/components/WriteToFilesModal.vue'

const images = [{ id: 1, name: 'DSC_2195.NEF' }, { id: 2, name: 'DSC_2183.NEF' }, { id: 3, name: 'DSC_2251.NEF' }]

const previewData = {
  items: [
    { id: 1, name: 'DSC_2195.NEF', diffs: { color: { db: 'Blue', file: null, kind: 'missing' } } },
    { id: 2, name: 'DSC_2183.NEF', diffs: { color: { db: 'Yellow', file: 'Green', kind: 'different' } } },
    { id: 3, name: 'DSC_2251.NEF', diffs: { pick: { db: 'reject', file: 'pick', kind: 'different' } } },
  ],
  counts: {
    missing:   { rating: 0, color: 1, pick: 0 },
    different: { rating: 0, color: 1, pick: 1 },
    extra:     { rating: 0, color: 0, pick: 0 },
  },
  checked: 3, noStore: 0, unreadable: 0, notFound: 0,
}

const factory = (props = {}) => mount(WriteToFilesModal, {
  props: { images, showPickCol: true, ...props },
  attachTo: document.body,
})

describe('WriteToFilesModal', () => {
  beforeEach(() => {
    axios.post.mockReset()
    axios.post.mockImplementation((url) => Promise.resolve({
      data: url.includes('/preview')
        ? previewData
        : { written: 1, unchanged: 0, no_store: 0, failed: 0, failedIds: [] },
    }))
  })

  it('lädt die Vorschau und zeigt standardmäßig nur fehlende Sterne/Farben', async () => {
    const w = factory()
    await flushPromises()
    expect(axios.post).toHaveBeenCalledWith(
      '/index.php/apps/starrate/api/xmp-sync/preview',
      { fileIds: [1, 2, 3] },
      expect.anything(),
    )
    expect(w.vm.rows.map(r => r.name)).toEqual(['DSC_2195.NEF'])
    expect(document.body.textContent).toContain('In 1 Datei schreiben')
    w.unmount()
  })

  it('Ersetzen-Modus nimmt abweichende Werte dazu, Pick bleibt ohne Haken außen vor', async () => {
    const w = factory()
    await flushPromises()
    document.querySelectorAll('.sr-wtf__mode input')[1].click()
    await flushPromises()
    expect(w.vm.rows.map(r => r.name)).toEqual(['DSC_2195.NEF', 'DSC_2183.NEF'])
    w.unmount()
  })

  it('schreibt nur die angezeigten Dateien mit den gewählten Feldern', async () => {
    const w = factory()
    await flushPromises()
    document.querySelector('.sr-wtf__btn--primary').click()
    await flushPromises()
    expect(axios.post).toHaveBeenLastCalledWith(
      '/index.php/apps/starrate/api/xmp-sync/apply',
      { fileIds: [1], fields: ['rating', 'color'], overwrite: false },
      expect.anything(),
    )
    expect(w.vm.phase).toBe('done')
    expect(document.body.textContent).toContain('1 Datei aktualisiert')
    w.unmount()
  })

  it('meldet ausgeschaltetes XMP-Schreiben verständlich', async () => {
    axios.post.mockRejectedValueOnce({ response: { data: { error: 'xmp_disabled' } } })
    const w = factory()
    await flushPromises()
    expect(document.body.textContent).toContain('ausgeschaltet')
    w.unmount()
  })

  it('Escape schließt den Dialog auch hinter Gallerys Capture-Handler', async () => {
    const blocker = (e) => { if (e.key === 'Escape') e.stopPropagation() }
    document.addEventListener('keydown', blocker, true)
    const w = factory()
    await flushPromises()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(w.emitted('close')).toBeTruthy()
    document.removeEventListener('keydown', blocker, true)
    w.unmount()
  })

  it('zeigt übersprungene Dateien mit unbekannten Werten an', async () => {
    axios.post.mockResolvedValueOnce({ data: { ...previewData, skipped: 2 } })
    const w = factory()
    await flushPromises()
    expect(document.body.textContent).toContain('2 Dateien mit Werten, die StarRate nicht kennt')
    w.unmount()
  })
})
