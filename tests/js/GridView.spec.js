import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GridView from '../../src/components/GridView.vue'

// Globales Image-Mock für Thumbnail-Loading
global.Image = class {
  constructor() { setTimeout(() => this.onload?.(), 0) }
}

const makeImages = (count = 5) => Array.from({ length: count }, (_, i) => ({
  id:          i + 1,
  name:        `IMG_000${i + 1}.jpg`,
  rating:      i % 6,
  color:       i % 2 === 0 ? null : 'Red',
  pick:        'none',
  thumbLoaded: false,
  thumbUrl:    null,
}))

const factory = (props = {}) => mount(GridView, {
  props: {
    images:          makeImages(),
    loading:         false,
    hasActiveFilter: false,
    currentIndex:    -1,
    ...props,
  },
  attachTo: document.body,
})

describe('GridView', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert alle Bilder', () => {
    const w = factory()
    expect(w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')).toHaveLength(5)
  })

  it('zeigt Skeleton-Loader beim Laden', () => {
    const w = factory({ images: [], loading: true })
    expect(w.findAll('.sr-grid__item--skeleton').length).toBeGreaterThan(0)
  })

  it('zeigt Leer-Zustand wenn keine Bilder', () => {
    const w = factory({ images: [], loading: false })
    expect(w.find('.sr-grid__empty').exists()).toBe(true)
  })

  it('zeigt CTA-Button wenn aktiver Filter und keine Bilder', () => {
    const w = factory({ images: [], loading: false, hasActiveFilter: true })
    expect(w.find('.sr-grid__empty-cta').exists()).toBe(true)
  })

  it('CTA-Button emittiert clear-filter', async () => {
    const w = factory({ images: [], loading: false, hasActiveFilter: true })
    await w.find('.sr-grid__empty-cta').trigger('click')
    expect(w.emitted('clear-filter')).toBeTruthy()
  })

  it('zeigt Info-Leiste mit Dateinamen', () => {
    const w = factory()
    const names = w.findAll('.sr-grid__info-name')
    expect(names[0].text()).toBe('IMG_0001.jpg')
  })

  it('zeigt Farbpunkt wenn Farbe gesetzt', () => {
    const w = factory()
    // Bilder 1,3,5 haben color=null, 2,4 haben color='Red'
    expect(w.findAll('.sr-grid__info-color').length).toBeGreaterThan(0)
  })

  // ── Auswahl: Normaler Klick ───────────────────────────────────────────────

  it('normaler Klick setzt focusedIndex ohne Auswahl', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[2].trigger('click')
    // kein Shift/Ctrl → nur Focus, kein select
    expect(w.emitted('selection-change')).toBeFalsy()
  })

  it('normaler Klick hebt Auswahl auf wenn Auswahl vorhanden', async () => {
    const w = factory()
    // Erst Strg+Klick
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    // Dann normaler Klick
    await w.findAll('.sr-grid__item')[1].trigger('click')
    const lastEmit = w.emitted('selection-change')
    const lastSet  = lastEmit[lastEmit.length - 1][0]
    expect(lastSet.size).toBe(0)
  })

  // ── Auswahl: Strg+Klick ───────────────────────────────────────────────────

  it('Strg+Klick fügt Bild zur Auswahl hinzu', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    const emitted = w.emitted('selection-change')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0].has(1)).toBe(true)
  })

  it('Strg+Klick auf ausgewähltes Bild entfernt es aus der Auswahl', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    const lastEmit = w.emitted('selection-change')
    const lastSet  = lastEmit[lastEmit.length - 1][0]
    expect(lastSet.has(1)).toBe(false)
  })

  it('mehrere Strg+Klicks sammeln mehrere Bilder', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[2].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[4].trigger('click', { ctrlKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(3)
  })

  it('Klick + Cmd+Klick selektiert beide Bilder (Bug #13)', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')          // Anker fokussieren
    await w.findAll('.sr-grid__item')[2].trigger('click', { ctrlKey: true }) // Cmd+Klick
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(2)
    expect(lastSet.has(1)).toBe(true) // Anker-Bild (Index 0, ID 1)
    expect(lastSet.has(3)).toBe(true) // Cmd-geklicktes Bild (Index 2, ID 3)
  })

  // ── Auswahl: Shift+Klick ──────────────────────────────────────────────────

  it('Shift+Klick markiert Bereich zwischen zwei Klicks', async () => {
    const w = factory({ images: makeImages(8) })
    await w.findAll('.sr-grid__item')[1].trigger('click') // Anker = Index 1
    await w.findAll('.sr-grid__item')[4].trigger('click', { shiftKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(4) // Indizes 1,2,3,4
  })

  // ── Tastatur ──────────────────────────────────────────────────────────────

  it('ArrowRight ohne vorherigen Focus bewegt zu Index 1 (startet bei 0)', async () => {
    const w = factory()
    await w.trigger('keydown', { key: 'ArrowRight' })
    expect(w.findAll('.sr-grid__item')[1].classes()).toContain('sr-grid__item--focused')
  })

  it('ArrowRight mit currentIndex=2 bewegt zu Index 3', async () => {
    const w = factory({ currentIndex: 2 })
    await w.trigger('keydown', { key: 'ArrowRight' })
    expect(w.findAll('.sr-grid__item')[3].classes()).toContain('sr-grid__item--focused')
  })

  it('ArrowRight nach Klick bewegt Focus', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[1].trigger('click')
    await w.trigger('keydown', { key: 'ArrowRight' })
    expect(w.findAll('.sr-grid__item')[2].classes()).toContain('sr-grid__item--focused')
  })

  it('Taste 0–5 emittiert rate für fokussiertes Bild', async () => {
    const w = factory()
    // Erst Bild fokussieren
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '4' })
    const emitted = w.emitted('rate')
    expect(emitted).toBeTruthy()
    expect(emitted[0][1]).toBe(4)
  })

  it('Taste 6 emittiert rate mit Farbe Red', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '6' })
    const emitted = w.emitted('rate')
    expect(emitted[0][2]).toBe('Red')
  })

  it('Shift+ArrowRight selektiert mehrere Bilder', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[1].trigger('click') // Anker = 1
    await w.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
    await w.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(3) // Indizes 1, 2, 3
  })

  it('Shift+ArrowLeft verkleinert Selektion wieder', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[1].trigger('click')
    await w.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
    await w.trigger('keydown', { key: 'ArrowRight', shiftKey: true })
    await w.trigger('keydown', { key: 'ArrowLeft', shiftKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(2) // Indizes 1, 2
  })

  it('normaler Klick zum Auswahl aufheben setzt fokussiertes Bild', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[2].trigger('click') // hebt Auswahl auf
    // Focus sollte jetzt auf Index 2 sein
    await w.trigger('keydown', { key: 'ArrowRight' })
    expect(w.findAll('.sr-grid__item')[3].classes()).toContain('sr-grid__item--focused')
  })

  it('Strg+A wählt alle Bilder aus', async () => {
    const w = factory()
    await w.trigger('keydown', { key: 'a', ctrlKey: true })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(5)
  })

  it('Escape hebt Auswahl auf', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'Escape' })
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(0)
  })

  it('Enter öffnet Lupenansicht für fokussiertes Bild', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'Enter' })
    expect(w.emitted('open-loupe')).toBeTruthy()
  })

  // ── Doppelklick öffnet Lupe ───────────────────────────────────────────────

  it('Doppelklick auf Bild emittiert open-loupe', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[1].trigger('dblclick')
    const emitted = w.emitted('open-loupe')
    expect(emitted).toBeTruthy()
    expect(emitted[0][1]).toBe(1) // index
  })

  // ── Auswahl-Markierung ────────────────────────────────────────────────────

  it('ausgewählte Bilder haben --selected Klasse', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    expect(w.findAll('.sr-grid__item')[0].classes()).toContain('sr-grid__item--selected')
  })

  it('nicht ausgewählte Bilder haben keine --selected Klasse', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    expect(w.findAll('.sr-grid__item')[1].classes()).not.toContain('sr-grid__item--selected')
  })

  // ── Pick / Reject ─────────────────────────────────────────────────────────

  // ── Bewertungsarten abschaltbar ───────────────────────────────────────────

  it('blendet Sterne und Farbpunkt aus wenn enableRatingUi/enableColorUi=false', () => {
    const w = factory({ enableRatingUi: false, enableColorUi: false })
    expect(w.find('.sr-grid__info-stars').exists()).toBe(false)
    expect(w.find('.sr-grid__info-color').exists()).toBe(false)
  })

  it('Tasten 0–5 tun nichts wenn enableRatingUi=false', async () => {
    const w = factory({ enableRatingUi: false })
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '3' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('Tasten 6–9/V tun nichts wenn enableColorUi=false', async () => {
    const w = factory({ enableColorUi: false })
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: '6' })
    await w.trigger('keydown', { key: 'v' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('Reject-Overlay nicht sichtbar wenn enablePickUi=false (default)', () => {
    const images = makeImages()
    images[0].pick = 'reject'
    const w = factory({ images })
    expect(w.find('.sr-grid__reject-overlay').exists()).toBe(false)
  })

  it('Reject-Overlay sichtbar wenn enablePickUi=true und pick=reject', () => {
    const images = makeImages()
    images[0].pick = 'reject'
    const w = factory({ images, enablePickUi: true })
    expect(w.find('.sr-grid__reject-overlay').exists()).toBe(true)
  })

  it('sr-grid__item--reject CSS-Klasse nur wenn enablePickUi=true', () => {
    const images = makeImages()
    images[0].pick = 'reject'
    const wOff = factory({ images })
    expect(wOff.findAll('.sr-grid__item')[0].classes()).not.toContain('sr-grid__item--reject')
    const wOn  = factory({ images, enablePickUi: true })
    expect(wOn.findAll('.sr-grid__item')[0].classes()).toContain('sr-grid__item--reject')
  })

  it('sr-grid__item--pick CSS-Klasse nur wenn enablePickUi=true', () => {
    const images = makeImages()
    images[0].pick = 'pick'
    const wOff = factory({ images })
    expect(wOff.findAll('.sr-grid__item')[0].classes()).not.toContain('sr-grid__item--pick')
    const wOn  = factory({ images, enablePickUi: true })
    expect(wOn.findAll('.sr-grid__item')[0].classes()).toContain('sr-grid__item--pick')
  })

  it('P-Taste ohne enablePickUi emittiert kein rate', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'p' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('P-Taste mit enablePickUi emittiert rate mit pick=pick', async () => {
    const w = factory({ enablePickUi: true })
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'p' })
    expect(w.emitted('rate')?.[0][3]).toBe('pick')
  })

  it('X-Taste ohne enablePickUi emittiert kein rate', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'x' })
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('X-Taste mit enablePickUi emittiert rate mit pick=reject', async () => {
    const w = factory({ enablePickUi: true })
    await w.findAll('.sr-grid__item')[0].trigger('click')
    await w.trigger('keydown', { key: 'x' })
    expect(w.emitted('rate')?.[0][3]).toBe('reject')
  })

  // ── Expose ────────────────────────────────────────────────────────────────

  it('clearSelection leert die Auswahl', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    w.vm.clearSelection()
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(0)
  })

  it('selectAll wählt alle Bilder aus', async () => {
    const w = factory()
    w.vm.selectAll()
    const lastSet = w.emitted('selection-change').at(-1)[0]
    expect(lastSet.size).toBe(5)
  })

  // ── Batch-Bewertung (Mehrfachauswahl + Tastatur) ───────────────────────────

  it('Taste 4 mit Auswahl → emittiert batch-rate (kein rate)', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[1].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: '4' })
    expect(w.emitted('batch-rate')).toBeTruthy()
    expect(w.emitted('batch-rate')[0]).toEqual([4, undefined, undefined])
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('Taste 0 mit Auswahl → emittiert batch-rate mit rating=0 (Sterne entfernen)', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: '0' })
    expect(w.emitted('batch-rate')?.[0][0]).toBe(0)
  })

  it('Taste 6 mit Auswahl → emittiert batch-rate mit color=Red', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: '6' })
    expect(w.emitted('batch-rate')).toBeTruthy()
    expect(w.emitted('batch-rate')[0]).toEqual([undefined, 'Red', undefined])
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('Taste 7/8/9 mit Auswahl → emittiert batch-rate mit korrekter Farbe', async () => {
    const colorMap = { '7': 'Yellow', '8': 'Green', '9': 'Blue' }
    for (const [key, color] of Object.entries(colorMap)) {
      const w = factory()
      await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
      await w.trigger('keydown', { key })
      expect(w.emitted('batch-rate')?.[0][1]).toBe(color)
    }
  })

  it('V-Taste mit Auswahl → emittiert batch-rate mit color=Purple', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'v' })
    expect(w.emitted('batch-rate')?.[0][1]).toBe('Purple')
  })

  it('P-Taste mit enablePickUi + Auswahl → emittiert batch-rate mit pick=pick', async () => {
    const w = factory({ enablePickUi: true })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'p' })
    expect(w.emitted('batch-rate')).toBeTruthy()
    expect(w.emitted('batch-rate')[0]).toEqual([undefined, undefined, 'pick'])
    expect(w.emitted('rate')).toBeFalsy()
  })

  it('X-Taste mit enablePickUi + Auswahl → emittiert batch-rate mit pick=reject', async () => {
    const w = factory({ enablePickUi: true })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'x' })
    expect(w.emitted('batch-rate')?.[0][2]).toBe('reject')
  })

  it('P-Taste mit Auswahl aber ohne enablePickUi → kein batch-rate', async () => {
    const w = factory()
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'p' })
    expect(w.emitted('batch-rate')).toBeFalsy()
  })

  it('Taste 4 ohne Auswahl aber ohne fokussiertes Bild → kein rate', async () => {
    const w = factory()
    // Kein Klick → focusedIndex = -1, keine Auswahl → nichts passiert
    await w.trigger('keydown', { key: '4' })
    expect(w.emitted('rate')).toBeFalsy()
    expect(w.emitted('batch-rate')).toBeFalsy()
  })

  // ── Farb-Toggle im Batch ───────────────────────────────────────────────────

  it('Taste 6: alle selektierten haben Red → Toggle auf null (entfernen)', async () => {
    const images = makeImages()
    images[0].color = 'Red'
    images[1].color = 'Red'
    const w = factory({ images })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[1].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: '6' })
    expect(w.emitted('batch-rate')[0]).toEqual([undefined, null, undefined])
  })

  it('Taste 6: gemischte Farben → setzt Red (kein Toggle)', async () => {
    const images = makeImages()
    images[0].color = 'Red'
    images[1].color = null
    const w = factory({ images })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[1].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: '6' })
    expect(w.emitted('batch-rate')[0]).toEqual([undefined, 'Red', undefined])
  })

  it('V-Taste: alle selektierten haben Purple → Toggle auf null', async () => {
    const images = makeImages()
    images[0].color = 'Purple'
    images[1].color = 'Purple'
    const w = factory({ images })
    await w.findAll('.sr-grid__item')[0].trigger('click', { ctrlKey: true })
    await w.findAll('.sr-grid__item')[1].trigger('click', { ctrlKey: true })
    await w.trigger('keydown', { key: 'v' })
    expect(w.emitted('batch-rate')[0]).toEqual([undefined, null, undefined])
  })

  // ── Virtualisierung ──────────────────────────────────────────────────────
  //
  // jsdom liefert keine echten Layout-Werte (clientWidth/clientHeight = 0),
  // d.h. virtualEnabled bleibt false und der Fallback-Pfad rendert alles. Echte
  // Virtualisierung ist im Browser bewiesen (manuelle 25k-Bild-Verifikation),
  // hier sichern wir den Fallback + den absoluten data-index Pfad.

  it('rendert ohne Layout-Messung alle Items (Fallback für jsdom-Tests)', () => {
    // Default-Verhalten ohne clientWidth-Mock: virtualEnabled=false, alles rendert.
    const w = factory({ images: makeImages(50) })
    expect(w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')).toHaveLength(50)
    expect(w.findAll('.sr-grid__spacer')).toHaveLength(0)
  })

  it('data-index bleibt absolut, auch wenn nur ein Slice gerendert wird', () => {
    // Auch im Fallback-Modus (alle gerendert) prüfen wir, dass data-index 0..N-1 läuft.
    const w = factory({ images: makeImages(20) })
    const items = w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')
    expect(items[0].attributes('data-index')).toBe('0')
    expect(items[19].attributes('data-index')).toBe('19')
  })

  // ── Tastatur-Navigation: columnsEstimate ─────────────────────────────────
  //
  // ↑/↓ steppen um eine Reihe = `columnsCount` Items. Vor dem Fix nutzte
  // columnsEstimate() eine eigene offsetWidth/THUMB_SIZE-Berechnung, die in
  // jsdom 0 lieferte und auf Mobile (390px / 280) immer 1 ergab — auf
  // Mobile-2-Spalten navigierte ↓ also nur 1 Item statt 1 Reihe. Mit dem
  // Fix nutzt columnsEstimate columnsCount.value, das via gridColumns-Prop
  // deterministisch ohne Layout setzbar ist.

  it('ArrowDown steppt um columnsCount Items (gridColumns=2)', async () => {
    const w = factory({ images: makeImages(20), gridColumns: '2' })
    const items = w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')
    await items[0].trigger('click')
    await w.trigger('keydown', { key: 'ArrowDown' })
    // Bei 2 Spalten: Reihe-runter = +2 Items
    expect(w.find('.sr-grid__item--focused').attributes('data-index')).toBe('2')
  })

  it('ArrowDown steppt um columnsCount Items (gridColumns=4)', async () => {
    const w = factory({ images: makeImages(20), gridColumns: '4' })
    const items = w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')
    await items[0].trigger('click')
    await w.trigger('keydown', { key: 'ArrowDown' })
    expect(w.find('.sr-grid__item--focused').attributes('data-index')).toBe('4')
  })

  it('ArrowUp steppt rückwärts um columnsCount Items', async () => {
    const w = factory({ images: makeImages(20), gridColumns: '3' })
    const items = w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')
    await items[6].trigger('click')  // Reihe 2 (Items 6, 7, 8)
    await w.trigger('keydown', { key: 'ArrowUp' })
    expect(w.find('.sr-grid__item--focused').attributes('data-index')).toBe('3')
  })

  it('ArrowDown am unteren Rand clampt auf letztes Item', async () => {
    // 20 Items bei 3 Spalten: letzte volle Reihe ist Items 18,19 in Reihe 6.
    // Von Item 19 aus ↓: würde Index 22 ergeben, geclampt auf 19.
    const w = factory({ images: makeImages(20), gridColumns: '3' })
    const items = w.findAll('.sr-grid__item:not(.sr-grid__item--skeleton)')
    await items[19].trigger('click')
    await w.trigger('keydown', { key: 'ArrowDown' })
    expect(w.find('.sr-grid__item--focused').attributes('data-index')).toBe('19')
  })
})
