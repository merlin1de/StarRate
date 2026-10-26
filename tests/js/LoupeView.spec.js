import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import LoupeView from '../../src/components/LoupeView.vue'

const images = [
  { id: 1, name: 'a.jpg', rating: 0, color: null, pick: 'none' },
  { id: 2, name: 'b.jpg', rating: 0, color: null, pick: 'none' },
]

function factory(props = {}) {
  return mount(LoupeView, {
    props: {
      images,
      initialIndex: 0,
      previewUrlFn: (id) => `/preview/${id}`,
      ...props,
    },
  })
}

const pt = (x, y) => ({ clientX: x, clientY: y })

describe('LoupeView Download-Button', () => {
  let wrapper

  afterEach(() => wrapper?.unmount())

  it('zeigt den Download-Button wenn canDownload=true', () => {
    wrapper = factory({ canDownload: true })
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(true)
  })

  it('versteckt den Download-Button wenn canDownload=false (Default)', () => {
    wrapper = factory()
    expect(wrapper.find('.sr-loupe__download').exists()).toBe(false)
  })

  it('emittiert "download" mit dem aktuellen Bild beim Klick', async () => {
    wrapper = factory({ canDownload: true })
    await wrapper.find('.sr-loupe__download').trigger('click')
    expect(wrapper.emitted('download')).toBeTruthy()
    expect(wrapper.emitted('download')[0][0]).toMatchObject({ id: 1, name: 'a.jpg' })
  })
})

describe('LoupeView Rating-Interaktivität (View-Only, shumak-Feedback)', () => {
  let wrapper

  afterEach(() => wrapper?.unmount())

  it('Rating-Sterne sind interaktiv wenn canRate=true (Owner/Default)', () => {
    wrapper = factory()
    const stars = wrapper.findAll('.sr-loupe__footer-center .sr-stars__star')
    expect(stars.length).toBeGreaterThan(0)
    expect(stars.every(s => s.attributes('disabled') === undefined)).toBe(true)
  })

  it('Rating-Sterne sind read-only (disabled) wenn canRate=false (View-Only-Gast)', () => {
    wrapper = factory({ canRate: false })
    const stars = wrapper.findAll('.sr-loupe__footer-center .sr-stars__star')
    expect(stars.length).toBeGreaterThan(0)
    expect(stars.every(s => s.attributes('disabled') !== undefined)).toBe(true)
  })

  it('read-only Sterne emittieren kein "rate" beim Klick', async () => {
    wrapper = factory({ canRate: false })
    await wrapper.find('.sr-loupe__footer-center .sr-stars__star').trigger('click')
    expect(wrapper.emitted('rate')).toBeFalsy()
  })
})

describe('LoupeView Touch-Gesten', () => {
  let wrapper

  afterEach(() => {
    wrapper?.unmount()
    vi.useRealTimers()
  })

  it('Pinch mit zwei Fingern zoomt rein (committet auf Pinch-Modus)', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')

    // Zwei Finger 100px auseinander → auf 140px spreizen (ratio 1.4)
    await el.trigger('touchstart', { touches: [pt(100, 100), pt(200, 100)] })
    await el.trigger('touchmove', { touches: [pt(80, 100), pt(220, 100)] })

    expect(wrapper.find('.sr-loupe__zoom-level').text()).toMatch(/%/)
  })

  it('zweiter Finger nach Wandern des ersten würgt den Pinch NICHT mehr ab', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')

    // Erst ein Finger, der deutlich wandert (>30px — alter Abbruch-Fall) ...
    await el.trigger('touchstart', { touches: [pt(100, 100)] })
    await el.trigger('touchmove', { touches: [pt(150, 100)] })
    // ... dann kommt der zweite Finger dazu → muss in Pinch-Modus wechseln
    await el.trigger('touchstart', { touches: [pt(150, 100), pt(250, 100)] })
    await el.trigger('touchmove', { touches: [pt(120, 100), pt(280, 100)] })

    expect(wrapper.find('.sr-loupe__zoom-level').text()).toMatch(/%/)
  })

  it('Pinch zoomt auf den Finger-Mittelpunkt (Pivot), nicht um die Bildmitte', async () => {
    wrapper = factory()
    const loupeEl = wrapper.find('.sr-loupe').element
    const imgEl   = wrapper.find('.sr-loupe__img').element
    // jsdom hat keine Layout-Maße → für die Pivot-Rechnung mocken.
    loupeEl.getBoundingClientRect = () => ({ left: 0, top: 0, width: 800, height: 600, right: 800, bottom: 600 })
    Object.defineProperty(loupeEl, 'offsetWidth',  { value: 800, configurable: true })
    Object.defineProperty(loupeEl, 'offsetHeight', { value: 600, configurable: true })
    Object.defineProperty(imgEl,   'naturalWidth',  { value: 4000, configurable: true })
    Object.defineProperty(imgEl,   'naturalHeight', { value: 3000, configurable: true })

    // Pinch um Mittelpunkt (400,200) — horizontal mittig, oberhalb der Mitte (400,300).
    const el = wrapper.find('.sr-loupe')
    await el.trigger('touchstart', { touches: [pt(300, 200), pt(500, 200)] })
    await el.trigger('touchmove',  { touches: [pt(200, 200), pt(600, 200)] })

    const style = wrapper.find('.sr-loupe__img').attributes('style') || ''
    const m = style.match(/translate\(calc\(-50% \+ ([-\d.]+)px\), calc\(-50% \+ ([-\d.]+)px\)/)
    expect(m).toBeTruthy()
    // Pivot oberhalb der Mitte → Bild rückt nach unten → panY > 0, panX ≈ 0
    expect(parseFloat(m[2])).toBeGreaterThan(0)
    expect(Math.abs(parseFloat(m[1]))).toBeLessThan(1)
  })

  it('nach Pinch mit verbleibendem Finger weiter pannen (kein Freeze)', async () => {
    wrapper = factory()
    const loupeEl = wrapper.find('.sr-loupe').element
    const imgEl   = wrapper.find('.sr-loupe__img').element
    loupeEl.getBoundingClientRect = () => ({ left: 0, top: 0, width: 800, height: 600, right: 800, bottom: 600 })
    Object.defineProperty(loupeEl, 'offsetWidth',  { value: 800, configurable: true })
    Object.defineProperty(loupeEl, 'offsetHeight', { value: 600, configurable: true })
    Object.defineProperty(imgEl,   'naturalWidth',  { value: 4000, configurable: true })
    Object.defineProperty(imgEl,   'naturalHeight', { value: 3000, configurable: true })

    const panX = () => {
      const m = (wrapper.find('.sr-loupe__img').attributes('style') || '')
        .match(/translate\(calc\(-50% \+ ([-\d.]+)px\)/)
      return m ? parseFloat(m[1]) : NaN
    }

    const el = wrapper.find('.sr-loupe')
    // Pinch reinzoomen
    await el.trigger('touchstart', { touches: [pt(300, 200), pt(500, 200)] })
    await el.trigger('touchmove',  { touches: [pt(200, 200), pt(600, 200)] })
    // Einen Finger heben → ein Finger bleibt
    await el.trigger('touchend', { touches: [pt(400, 300)], changedTouches: [pt(600, 200)] })
    const before = panX()
    // Verbleibenden Finger ziehen → muss pannen (vorher: eingefroren)
    await el.trigger('touchmove', { touches: [pt(460, 300)] })
    expect(panX()).not.toBe(before)
  })

  it('Ein-Finger-Swipe im Fit navigiert weiter', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')

    await el.trigger('touchstart', { touches: [pt(300, 100)] })
    await el.trigger('touchend', { touches: [], changedTouches: [pt(100, 110)] })

    expect(wrapper.emitted('index-change')).toBeTruthy()
    expect(wrapper.emitted('index-change')[0][0]).toBe(1)
  })

  // v-show spiegelt sich als inline `display: none` — robuster als isVisible()
  // (jsdom liefert keinen Stylesheet-Computed-Style → isVisible() false-negativ).
  const footerHidden = () =>
    (wrapper.find('.sr-loupe__footer').attributes('style') || '').includes('display: none')

  it('Auto-Hide: Mausrad-Zoom blendet die Leiste nach 3s aus, Fit nicht', async () => {
    vi.useFakeTimers()
    wrapper = factory()

    // Im Fit bleibt die Leiste sichtbar — auch nach Ablauf (kein Hide-Timer)
    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(false)

    // Reines Mausrad-Zoom (ohne mousemove) armiert jetzt den Hide-Timer
    await wrapper.find('.sr-loupe').trigger('wheel', { deltaY: -100 })
    expect(footerHidden()).toBe(false)

    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(true)
  })

  it('Touch holt die ausgeblendete Leiste zurück', async () => {
    vi.useFakeTimers()
    wrapper = factory()

    await wrapper.find('.sr-loupe').trigger('wheel', { deltaY: -100 })
    vi.advanceTimersByTime(3500)
    await nextTick()
    expect(footerHidden()).toBe(true)

    await wrapper.find('.sr-loupe').trigger('touchstart', { touches: [pt(100, 100)] })
    await nextTick()
    expect(footerHidden()).toBe(false)
  })
})

describe('LoupeView Doppeltipp (Touch)', () => {
  let wrapper
  afterEach(() => { wrapper?.unmount(); vi.useRealTimers() })

  const label = () => wrapper.find('.sr-loupe__zoom-level').text()

  // Ein stehender Tap an (x,y): touchstart + touchend ohne Bewegung.
  async function tap(el, x = 400, y = 300) {
    await el.trigger('touchstart', { touches: [pt(x, y)] })
    await el.trigger('touchend', { touches: [], changedTouches: [pt(x, y)] })
  }

  it('zwei stehende Taps over-zoomen wie Doppelklick (Fit → 300%)', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    expect(label()).toBe('Eingepasst')
    await tap(el, 400, 300)
    await tap(el, 402, 301)   // gleiche Stelle, sofort
    expect(label()).toBe('300%')
  })

  it('Doppeltipp bei Zoom kehrt zu Fit zurück', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    await tap(el); await tap(el)            // rein → 300%
    expect(label()).toBe('300%')
    await tap(el); await tap(el)            // raus → Fit
    expect(label()).toBe('Eingepasst')
  })

  it('zwei schnelle Swipes lösen KEINEN Doppeltipp-Zoom aus', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    for (let i = 0; i < 2; i++) {
      await el.trigger('touchstart', { touches: [pt(300, 300)] })
      await el.trigger('touchend', { touches: [], changedTouches: [pt(210, 300)] }) // dx=-90 → Swipe
    }
    expect(label()).toBe('Eingepasst')   // bleibt Fit, kein Zoom
  })

  it('zwei Taps mit >300ms Abstand zoomen NICHT (nur Einzeltaps)', async () => {
    vi.useFakeTimers()
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    await tap(el)
    vi.advanceTimersByTime(400)
    await tap(el)
    expect(label()).toBe('Eingepasst')
  })

  it('Doppeltipp auf den Nav-Pfeil navigiert nur, zoomt NICHT', async () => {
    wrapper = factory()
    const next = wrapper.find('.sr-loupe__nav--next')
    for (let i = 0; i < 2; i++) {
      await next.trigger('touchstart', { touches: [pt(700, 300)] })
      await next.trigger('touchend', { touches: [], changedTouches: [pt(700, 300)] })
    }
    expect(label()).toBe('Eingepasst')   // kein versehentlicher Zoom
  })
})

describe('LoupeView Doppelklick-Zoom', () => {
  let wrapper
  afterEach(() => wrapper?.unmount())

  it('over-zoomt auf einen festen Faktor (formatunabhängig) und toggelt zurück auf Fit', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')

    // Fester Over-Zoom — unabhängig von Bildmaßen (jsdom hat keine), also gleicher
    // Wert für Hoch- wie Querformat. Vorher rechnete naturalWidth/cw → formatabhängig.
    await el.trigger('dblclick')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('300%')

    // Zweiter Doppelklick → zurück auf Fit
    await el.trigger('dblclick')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')
  })

  it('Doppelklick auf den Nav-Pfeil zoomt NICHT (nur Navigation)', async () => {
    wrapper = factory()
    await wrapper.find('.sr-loupe__nav--next').trigger('dblclick')
    expect(wrapper.find('.sr-loupe__zoom-level').text()).toBe('Eingepasst')
  })

  it('Fit ist die Untergrenze: Rauszoomen unter Fit schnappt auf Eingepasst zurück', async () => {
    wrapper = factory()
    const el = wrapper.find('.sr-loupe')
    const label = () => wrapper.find('.sr-loupe__zoom-level').text()

    // Aus dem Fit heraus rauszoomen (deltaY > 0) bleibt Fit
    await el.trigger('wheel', { deltaY: 100 })
    expect(label()).toBe('Eingepasst')

    // Reinzoomen funktioniert
    await el.trigger('wheel', { deltaY: -100 })
    expect(label()).toMatch(/%/)

    // Mehrfach rauszoomen → schnappt auf Fit, nicht unter Fit
    for (let i = 0; i < 12; i++) await el.trigger('wheel', { deltaY: 100 })
    expect(label()).toBe('Eingepasst')
  })
})

describe('LoupeView Bewertungsarten abschaltbar', () => {
  let wrapper

  afterEach(() => wrapper?.unmount())

  it('blendet Sterne und Farben aus wenn enableRatingUi/enableColorUi=false', () => {
    wrapper = factory({ enableRatingUi: false, enableColorUi: false })
    expect(wrapper.find('.sr-loupe__footer-center .sr-stars').exists()).toBe(false)
    expect(wrapper.find('.sr-loupe__footer-center .sr-color-label').exists()).toBe(false)
  })

  it('Tasten für ausgeblendete Bewertungsarten emittieren kein "rate"', () => {
    wrapper = factory({ enableRatingUi: false, enableColorUi: false })
    for (const key of ['4', '6', 'v']) {
      document.dispatchEvent(new KeyboardEvent('keydown', { key }))
    }
    expect(wrapper.emitted('rate')).toBeFalsy()
  })

  it('Taste 4 bewertet weiterhin wenn nur Farben ausgeblendet sind', () => {
    wrapper = factory({ enableColorUi: false })
    document.dispatchEvent(new KeyboardEvent('keydown', { key: '4' }))
    expect(wrapper.emitted('rate')?.[0][1]).toBe(4)
  })
})
