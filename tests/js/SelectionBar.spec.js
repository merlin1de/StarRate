import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SelectionBar from '../../src/components/SelectionBar.vue'

// Teleport deaktivieren für Tests
const factory = (props = {}) => mount(SelectionBar, {
  props: { count: 3, ...props },
  global: {
    stubs: { Teleport: true },
  },
})

describe('SelectionBar', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('zeigt Anzahl der ausgewählten Bilder', () => {
    const w = factory({ count: 14 })
    expect(w.find('.sr-selbar__count').text()).toContain('14')
  })

  it('rendert 6 Sterne-Buttons (0–5)', () => {
    const w = factory()
    expect(w.findAll('.sr-selbar__btn--star')).toHaveLength(6)
  })

  it('rendert 5 Farb-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-selbar__btn--color')).toHaveLength(5)
  })

  it('rendert einen Farbe-Entfernen-Button', () => {
    const w = factory()
    // Der letzte Button nach den Farb-Buttons (✕)
    const section = w.find('.sr-selbar__section:last-child')
    const buttons = section.findAll('.sr-selbar__btn')
    // 5 Farben + 1 Remove
    expect(buttons).toHaveLength(6)
  })

  it('zeigt Clear-Button', () => {
    const w = factory()
    expect(w.find('.sr-selbar__clear').exists()).toBe(true)
  })

  // ── Bewertung setzen ──────────────────────────────────────────────────────

  it('emittiert rate mit Rating 4 beim Klick auf ★★★★', async () => {
    const w = factory()
    const starBtns = w.findAll('.sr-selbar__btn--star')
    await starBtns[4].trigger('click') // Index 4 = Rating 4
    const emitted = w.emitted('rate')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0]).toBe(4)          // rating
    expect(emitted[0][1]).toBeUndefined()  // color unverändert
  })

  it('emittiert rate mit Rating 0 beim Klick auf ✕-Stern', async () => {
    const w = factory()
    const starBtns = w.findAll('.sr-selbar__btn--star')
    await starBtns[0].trigger('click') // Index 0 = Rating 0
    expect(w.emitted('rate')[0][0]).toBe(0)
  })

  it('markiert Stern aktiv wenn activeRating-Prop gesetzt', () => {
    const w = factory({ activeRating: 3 })
    const starBtns = w.findAll('.sr-selbar__btn--star')
    expect(starBtns[3].classes()).toContain('sr-selbar__btn--active')
    expect(starBtns[4].classes()).not.toContain('sr-selbar__btn--active')
  })

  it('markiert ✕-Stern-Button aktiv wenn activeRating=0', () => {
    const w = factory({ activeRating: 0 })
    const starBtns = w.findAll('.sr-selbar__btn--star')
    expect(starBtns[0].classes()).toContain('sr-selbar__btn--active') // Index 0 = ✕ / Rating 0
    expect(starBtns[1].classes()).not.toContain('sr-selbar__btn--active')
  })

  // ── Farbe setzen ──────────────────────────────────────────────────────────

  it('emittiert rate mit Farbe Green beim Klick auf Grün-Button', async () => {
    const w = factory()
    const colorBtns = w.findAll('.sr-selbar__btn--color')
    await colorBtns[2].trigger('click') // Index 2 = Green
    const emitted = w.emitted('rate')
    expect(emitted[0][0]).toBeUndefined() // rating unverändert
    expect(emitted[0][1]).toBe('Green')   // color
  })

  it('emittiert rate mit null-Farbe beim Klick auf Farbe-Entfernen', async () => {
    const w = factory()
    await w.find('.sr-selbar__btn--color-none').trigger('click')
    const emitted = w.emitted('rate')
    expect(emitted[0][0]).toBeUndefined() // rating unverändert
    expect(emitted[0][1]).toBeNull()      // color = null → Farbe entfernen
  })

  it('markiert Farbe-Entfernen-Button aktiv wenn activeColor=null', () => {
    const w = factory({ activeColor: null })
    expect(w.find('.sr-selbar__btn--color-none').classes()).toContain('sr-selbar__btn--active')
  })

  it('markiert Farb-Button aktiv wenn activeColor-Prop gesetzt', () => {
    const w = factory({ activeColor: 'Green' })
    const colorBtns = w.findAll('.sr-selbar__btn--color')
    expect(colorBtns[2].classes()).toContain('sr-selbar__btn--active') // Index 2 = Green
    expect(colorBtns[0].classes()).not.toContain('sr-selbar__btn--active')
  })

  // ── Auswahl aufheben ──────────────────────────────────────────────────────

  it('emittiert clear beim Klick auf X-Button', async () => {
    const w = factory()
    await w.find('.sr-selbar__clear').trigger('click')
    expect(w.emitted('clear')).toBeTruthy()
  })

  // ── Anzahl-Pluralform ─────────────────────────────────────────────────────

  it('zeigt "1 Bild ausgewählt" für count=1', () => {
    const w = factory({ count: 1 })
    expect(w.find('.sr-selbar__count').text()).toContain('1 Bild ausgewählt')
  })

  it('zeigt "5 Bilder ausgewählt" für count=5', () => {
    const w = factory({ count: 5 })
    expect(w.find('.sr-selbar__count').text()).toContain('5 Bilder ausgewählt')
  })

  // ── Edge Cases ────────────────────────────────────────────────────────────

  it('activeRating-Prop-Wechsel aktualisiert aktive Klasse', async () => {
    const w = factory({ activeRating: 3 })
    expect(w.findAll('.sr-selbar__btn--star')[3].classes()).toContain('sr-selbar__btn--active')

    await w.setProps({ activeRating: 5 })
    expect(w.findAll('.sr-selbar__btn--star')[5].classes()).toContain('sr-selbar__btn--active')
    expect(w.findAll('.sr-selbar__btn--star')[3].classes()).not.toContain('sr-selbar__btn--active')
  })

  it('activeColor-Prop-Wechsel: nur letzte Farbe aktiv', async () => {
    const w = factory({ activeColor: 'Red' })
    expect(w.findAll('.sr-selbar__btn--color')[0].classes()).toContain('sr-selbar__btn--active')

    await w.setProps({ activeColor: 'Green' })
    expect(w.findAll('.sr-selbar__btn--color')[0].classes()).not.toContain('sr-selbar__btn--active')
    expect(w.findAll('.sr-selbar__btn--color')[2].classes()).toContain('sr-selbar__btn--active')
  })

  it('alle 5 Ratings (1–5) emittieren korrekt', async () => {
    const w = factory()
    const stars = w.findAll('.sr-selbar__btn--star')
    for (let i = 1; i <= 5; i++) {
      await stars[i].trigger('click')
      const emitted = w.emitted('rate')
      const last = emitted[emitted.length - 1]
      expect(last[0]).toBe(i)
      expect(last[1]).toBeUndefined() // color unverändert
    }
  })

  it('alle 5 Farben emittieren korrekt', async () => {
    const expectedColors = ['Red', 'Yellow', 'Green', 'Blue', 'Purple']
    const w = factory()
    const colorBtns = w.findAll('.sr-selbar__btn--color')

    for (let i = 0; i < 5; i++) {
      await colorBtns[i].trigger('click')
      const emitted = w.emitted('rate')
      const last = emitted[emitted.length - 1]
      expect(last[0]).toBeUndefined() // rating unverändert
      expect(last[1]).toBe(expectedColors[i])
    }
  })

  it('große Anzahl wird korrekt angezeigt', () => {
    const w = factory({ count: 999 })
    expect(w.find('.sr-selbar__count').text()).toContain('999')
  })

  // ── Pick/Reject ───────────────────────────────────────────────────────────

  it('blendet Sterne- und Farb-Buttons aus wenn deaktiviert', () => {
    const w = factory({ enableRatingUi: false, enableColorUi: false, enablePickUi: true })
    expect(w.find('.sr-selbar__btn--star').exists()).toBe(false)
    expect(w.find('.sr-selbar__btn--color').exists()).toBe(false)
    expect(w.find('.sr-selbar__btn--pick').exists()).toBe(true)
  })

  it('zeigt keine Pick/Reject-Buttons ohne enablePickUi', () => {
    const w = factory()
    expect(w.find('.sr-selbar__btn--pick').exists()).toBe(false)
  })

  it('zeigt Pick/Reject-Buttons wenn enablePickUi=true', () => {
    const w = factory({ enablePickUi: true })
    expect(w.find('.sr-selbar__btn--pick').exists()).toBe(true)
    expect(w.find('.sr-selbar__btn--reject').exists()).toBe(true)
    expect(w.find('.sr-selbar__btn--pick-none').exists()).toBe(true)
  })

  it('emittiert rate mit pick=pick beim Klick auf Pick-Button', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-selbar__btn--pick').trigger('click')
    const emitted = w.emitted('rate')
    expect(emitted[0][0]).toBeUndefined()  // rating unverändert
    expect(emitted[0][1]).toBeUndefined()  // color unverändert
    expect(emitted[0][2]).toBe('pick')
  })

  it('emittiert rate mit pick=reject beim Klick auf Reject-Button', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-selbar__btn--reject').trigger('click')
    expect(w.emitted('rate')[0][2]).toBe('reject')
  })

  it('emittiert rate mit pick=none beim Klick auf Pick-Entfernen', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-selbar__btn--pick-none').trigger('click')
    expect(w.emitted('rate')[0][2]).toBe('none')
  })

  it('markiert Pick-Button aktiv wenn activePick=pick', () => {
    const w = factory({ enablePickUi: true, activePick: 'pick' })
    expect(w.find('.sr-selbar__btn--pick').classes()).toContain('sr-selbar__btn--active')
    expect(w.find('.sr-selbar__btn--reject').classes()).not.toContain('sr-selbar__btn--active')
  })

  it('markiert Reject-Button aktiv wenn activePick=reject', () => {
    const w = factory({ enablePickUi: true, activePick: 'reject' })
    expect(w.find('.sr-selbar__btn--reject').classes()).toContain('sr-selbar__btn--active')
    expect(w.find('.sr-selbar__btn--pick').classes()).not.toContain('sr-selbar__btn--active')
  })

  it('markiert Pick-Entfernen-Button aktiv wenn activePick=none', () => {
    const w = factory({ enablePickUi: true, activePick: 'none' })
    expect(w.find('.sr-selbar__btn--pick-none').classes()).toContain('sr-selbar__btn--active')
  })
})
