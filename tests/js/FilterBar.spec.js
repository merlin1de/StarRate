import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FilterBar from '../../src/components/FilterBar.vue'

const defaultFilter = () => ({
  minRating:   0,
  exactRating: null,
  maxRating:   null,
  color:       null,
  pick:        null,
})

const factory = (props = {}) => mount(FilterBar, {
  props: {
    filter:        defaultFilter(),
    total:         100,
    filteredCount: 100,
    mode:          'grid',
    ...props,
  },
})

describe('FilterBar', () => {

  // ── Rendering ─────────────────────────────────────────────────────────────

  it('rendert alle Sterne-Optionen', () => {
    const w = factory()
    const pills = w.findAll('.sr-filterbar__pill:not(.sr-filterbar__colordot)')
    expect(pills.length).toBeGreaterThanOrEqual(4) // ★5, ★4, ≥3★, Unbewertet
  })

  it('rendert 5 Farb-Kreise', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__colordot')).toHaveLength(5)
  })

  it('Status-Bereich zeigt Gesamtzahl wenn kein Filter aktiv', async () => {
    const w = factory({ total: 42 })
    await w.vm.$nextTick()
    const status = w.find('.sr-filterbar__status')
    expect(status.exists()).toBe(true)
    expect(status.text()).toContain('42')
    expect(status.find('.sr-filterbar__reset').exists()).toBe(false)
  })

  it('Status-Bereich zeigt "X von Y" und Reset wenn Filter aktiv', () => {
    const w = factory({ filter: { ...defaultFilter(), minRating: 3 }, total: 42, filteredCount: 10 })
    const status = w.find('.sr-filterbar__status')
    expect(status.text()).toContain('10')
    expect(status.text()).toContain('42')
    expect(status.find('.sr-filterbar__reset').exists()).toBe(true)
  })

  it('zeigt Grid/Loupe Modus-Buttons', () => {
    const w = factory()
    expect(w.findAll('.sr-filterbar__mode-btn')).toHaveLength(2)
  })

  // ── Sterne-Filter ─────────────────────────────────────────────────────────

  it('setzt minRating=5 beim Klick auf ★★★★★ (default op=≥)', async () => {
    const w = factory()
    // Stern-Pills: ★★★★★ ★★★★ ★★★ ★★ ★ ○ → index 0 = ★★★★★
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0].minRating).toBe(5)
    expect(emitted[0][0].exactRating).toBeNull()
  })

  it('setzt minRating=5 beim Klick op=≥, dann ★★★★★', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__op')[0].trigger('click') // ≥ Operator
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click') // ★★★★★ = index 0
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].minRating).toBe(5)
    expect(emitted[0][0].exactRating).toBeNull()
  })

  it('setzt minRating=3 beim Klick auf ★★★ (default op=≥)', async () => {
    const w = factory()
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[2].trigger('click') // ★★★ = index 2
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].minRating).toBe(3)
    expect(emitted[0][0].exactRating).toBeNull()
  })

  it('deaktiviert Sterne-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    // watch setzt selectedOp='=' → ★★★★★ (index 0) ist aktiv → Klick deaktiviert
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[0].trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBeNull()
    expect(emitted[0][0].minRating).toBe(0)
  })

  it('hat --active Klasse für aktive Sterne-Pill (exactRating=5)', () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 5 } })
    // selectedOp wird durch watch auf '=' gesetzt → ★★★★★ (index 0) ist aktiv
    const stars = w.findAll('.sr-filterbar__pill--star')
    expect(stars[0].classes()).toContain('sr-filterbar__pill--active')
  })

  it('setzt maxRating=3 beim Klick op=≤, dann ★★★', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__op')[2].trigger('click') // ≤ Operator
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[2].trigger('click') // ★★★ = index 2
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].maxRating).toBe(3)
    expect(emitted[0][0].exactRating).toBeNull()
    expect(emitted[0][0].minRating).toBe(0)
  })

  // ── Farb-Filter ───────────────────────────────────────────────────────────

  it('setzt Farb-Filter beim Klick auf Farb-Pill', async () => {
    const w = factory()
    await w.findAll('.sr-filterbar__colordot')[0].trigger('click') // Rot
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].color).toBe('Red')
  })

  it('entfernt Farb-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), color: 'Red' } })
    await w.findAll('.sr-filterbar__colordot')[0].trigger('click')
    expect(w.emitted('update:filter')[0][0].color).toBeNull()
  })

  it('kann Sterne- und Farb-Filter kombinieren', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 4 } })
    await w.findAll('.sr-filterbar__colordot')[2].trigger('click') // Grün
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(4)
    expect(emitted[0][0].color).toBe('Green')
  })

  // ── Pick/Reject-Filter ────────────────────────────────────────────────────

  it('blendet Sterne-Filter aus wenn enableRatingUi=false', () => {
    const w = factory({ enableRatingUi: false })
    expect(w.find('.sr-filterbar__pill--star').exists()).toBe(false)
    expect(w.findAll('.sr-filterbar__colordot')).toHaveLength(5)
  })

  it('blendet Farb-Filter aus wenn enableColorUi=false', () => {
    const w = factory({ enableColorUi: false })
    expect(w.find('.sr-filterbar__colordot').exists()).toBe(false)
    expect(w.find('.sr-filterbar__pill--star').exists()).toBe(true)
  })

  it('Pick/Reject-Buttons nicht sichtbar wenn enablePickUi=false (default)', () => {
    const w = factory()
    expect(w.find('.sr-filterbar__pill--pick').exists()).toBe(false)
    expect(w.find('.sr-filterbar__pill--reject').exists()).toBe(false)
  })

  it('Pick/Reject-Buttons sichtbar wenn enablePickUi=true', () => {
    const w = factory({ enablePickUi: true })
    expect(w.find('.sr-filterbar__pill--pick').exists()).toBe(true)
    expect(w.find('.sr-filterbar__pill--reject').exists()).toBe(true)
  })

  it('aktiviert Pick-Filter', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-filterbar__pill--pick').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('pick')
  })

  it('deaktiviert Pick-Filter bei nochmaligem Klick', async () => {
    const w = factory({ filter: { ...defaultFilter(), pick: 'pick' }, enablePickUi: true })
    await w.find('.sr-filterbar__pill--pick').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBeNull()
  })

  it('aktiviert Reject-Filter', async () => {
    const w = factory({ enablePickUi: true })
    await w.find('.sr-filterbar__pill--reject').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('reject')
  })

  // ── Reset ─────────────────────────────────────────────────────────────────

  it('"Alle anzeigen" setzt alle Filter zurück', async () => {
    const w = factory({
      filter: { minRating: 3, exactRating: null, color: 'Red', pick: 'pick' },
    })
    await w.find('.sr-filterbar__reset').trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0]).toEqual({ minRating: 0, exactRating: null, maxRating: null, color: null, pick: null })
  })

  // ── Modus-Toggle ─────────────────────────────────────────────────────────

  it('emittiert toggle-mode beim Klick auf Loupe-Button', async () => {
    const w = factory({ mode: 'grid' })
    const loupBtn = w.findAll('.sr-filterbar__mode-btn')[1]
    await loupBtn.trigger('click')
    expect(w.emitted('toggle-mode')).toBeTruthy()
  })

  it('Grid-Button hat --active Klasse im Grid-Modus', () => {
    const w = factory({ mode: 'grid' })
    expect(w.findAll('.sr-filterbar__mode-btn')[0].classes())
      .toContain('sr-filterbar__mode-btn--active')
  })

  it('Loupe-Button hat --active Klasse im Loupe-Modus', () => {
    const w = factory({ mode: 'loupe' })
    expect(w.findAll('.sr-filterbar__mode-btn')[1].classes())
      .toContain('sr-filterbar__mode-btn--active')
  })

  // ── Edge Cases ────────────────────────────────────────────────────────────

  it('Unbewertet-Filter (★0 / exactRating=0)', async () => {
    const w = factory()
    const stars = w.findAll('.sr-filterbar__pill--star')
    const unrated = stars[stars.length - 1] // letzter = ○ (unbewertet)
    await unrated.trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(0)
  })

  it('wechselt Operator von = auf ≥ bei aktivem exactRating', async () => {
    const w = factory({ filter: { ...defaultFilter(), exactRating: 4 } })
    // Operator-Wechsel zu ≥
    await w.findAll('.sr-filterbar__op')[0].trigger('click') // ≥
    // Jetzt den gleichen Stern klicken
    const stars = w.findAll('.sr-filterbar__pill--star')
    await stars[1].trigger('click') // ★★★★ = index 1
    const emitted = w.emitted('update:filter')
    const last = emitted[emitted.length - 1][0]
    expect(last.minRating).toBe(4)
    expect(last.exactRating).toBeNull()
  })

  it('Pick-Wechsel: pick → reject direkt', async () => {
    const w = factory({ filter: { ...defaultFilter(), pick: 'pick' }, enablePickUi: true })
    await w.find('.sr-filterbar__pill--reject').trigger('click')
    expect(w.emitted('update:filter')[0][0].pick).toBe('reject')
  })

  it('Status zeigt Anzahl gefiltert / total', () => {
    const w = factory({
      filter: { ...defaultFilter(), minRating: 3 },
      total: 100,
      filteredCount: 25,
    })
    const status = w.find('.sr-filterbar__status')
    expect(status.text()).toContain('25')
    expect(status.text()).toContain('100')
  })

  it('Mobile Reset-Button nur bei aktiven Filtern', () => {
    const w = factory()
    expect(w.find('.sr-filterbar__reset--mobile').exists()).toBe(false)

    const w2 = factory({ filter: { ...defaultFilter(), color: 'Blue' } })
    expect(w2.find('.sr-filterbar__reset--mobile').exists()).toBe(true)
  })

  it('mehrere Filter gleichzeitig: Sterne + Farbe + Pick', async () => {
    const w = factory({
      filter: { ...defaultFilter(), exactRating: 5, color: 'Green' },
      enablePickUi: true,
    })
    await w.find('.sr-filterbar__pill--pick').trigger('click')
    const emitted = w.emitted('update:filter')
    expect(emitted[0][0].exactRating).toBe(5)
    expect(emitted[0][0].color).toBe('Green')
    expect(emitted[0][0].pick).toBe('pick')
  })

  // ── Actions: Teilen / Export ──────────────────────────────────────────────

  it('zeigt keine Actions wenn weder allowShare noch allowExport', () => {
    const w = factory()
    expect(w.find('[title="Freigabe-Links verwalten"]').exists()).toBe(false)
    expect(w.find('[title="Bewertungsliste exportieren"]').exists()).toBe(false)
  })

  it('zeigt Teilen-Button wenn allowShare=true', () => {
    const w = factory({ allowShare: true })
    expect(w.find('[title="Freigabe-Links verwalten"]').exists()).toBe(true)
  })

  it('blendet Teilen-Button aus wenn allowShare=false, zeigt aber Export', () => {
    const w = factory({ allowShare: false, allowExport: true, canExport: true })
    expect(w.find('[title="Freigabe-Links verwalten"]').exists()).toBe(false)
    expect(w.find('[title="Bewertungsliste exportieren"]').exists()).toBe(true)
  })

  it('Export-Button disabled wenn canExport=false', () => {
    const w = factory({ allowExport: true, canExport: false })
    const btn = w.find('[title="Bewertungsliste exportieren"]')
    expect(btn.exists()).toBe(true)
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('Export-Button aktiv wenn canExport=true', () => {
    const w = factory({ allowExport: true, canExport: true })
    const btn = w.find('[title="Bewertungsliste exportieren"]')
    expect(btn.attributes('disabled')).toBeUndefined()
  })

  it('Klick Teilen emittiert open-share-list', async () => {
    const w = factory({ allowShare: true })
    await w.find('[title="Freigabe-Links verwalten"]').trigger('click')
    expect(w.emitted('open-share-list')).toBeTruthy()
  })

  it('Klick Export emittiert open-export-modal', async () => {
    const w = factory({ allowExport: true, canExport: true })
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    expect(w.emitted('open-export-modal')).toBeTruthy()
  })

  // ── Recursive-View-Controls ───────────────────────────────────────────────

  it('Recursive-Block ist nicht sichtbar wenn allowRecursive=false (default)', () => {
    const w = factory()
    expect(w.find('.sr-filterbar__recursive').exists()).toBe(false)
  })

  it('Recursive-Block ist sichtbar wenn allowRecursive=true', () => {
    const w = factory({ allowRecursive: true })
    expect(w.find('.sr-filterbar__recursive').exists()).toBe(true)
  })

  it('Tiefe-Dropdown ist nur sichtbar wenn recursive=true', () => {
    const off = factory({ allowRecursive: true, recursive: false })
    expect(off.find('.sr-filterbar__depth').exists()).toBe(false)

    const on = factory({ allowRecursive: true, recursive: true })
    expect(on.find('.sr-filterbar__depth').exists()).toBe(true)
  })

  it('Recursive-Toggle hat --active Klasse wenn recursive=true', () => {
    const w = factory({ allowRecursive: true, recursive: true })
    const btn = w.find('.sr-filterbar__recursive .sr-filterbar__mode-btn')
    expect(btn.classes()).toContain('sr-filterbar__mode-btn--active')
  })

  it('Klick auf Recursive-Toggle (off → on) emittiert update:recursive=true', async () => {
    const w = factory({ allowRecursive: true, recursive: false })
    await w.find('.sr-filterbar__recursive .sr-filterbar__mode-btn').trigger('click')
    expect(w.emitted('update:recursive')).toEqual([[true]])
  })

  it('Klick auf Recursive-Toggle (on → off) emittiert update:recursive=false', async () => {
    const w = factory({ allowRecursive: true, recursive: true })
    await w.find('.sr-filterbar__recursive .sr-filterbar__mode-btn').trigger('click')
    expect(w.emitted('update:recursive')).toEqual([[false]])
  })

  it('Tiefe-Dropdown emittiert update:depth als Number (nicht String)', async () => {
    const w = factory({ allowRecursive: true, recursive: true, depth: 0 })
    const select = w.find('.sr-filterbar__depth')
    await select.setValue('3')
    const emitted = w.emitted('update:depth')
    expect(emitted).toBeTruthy()
    expect(emitted[0][0]).toBe(3)             // Number
    expect(typeof emitted[0][0]).toBe('number')
  })

  it('Tiefe-Dropdown reflektiert prop-Wert über :value', () => {
    const w = factory({ allowRecursive: true, recursive: true, depth: 2 })
    const select = w.find('.sr-filterbar__depth')
    expect(select.element.value).toBe('2')
  })

  // ── Download-Button (Auswahl als ZIP) ──────────────────────────────────────

  it('zeigt keinen Download-Button ohne Auswahl', () => {
    const w = factory({ canDownload: true, selectedCount: 0 })
    expect(w.find('.sr-filterbar__action--download').exists()).toBe(false)
  })

  it('zeigt keinen Download-Button wenn canDownload=false', () => {
    const w = factory({ canDownload: false, selectedCount: 5 })
    expect(w.find('.sr-filterbar__action--download').exists()).toBe(false)
  })

  it('zeigt Download-Button mit Anzahl bei Auswahl + canDownload', () => {
    const w = factory({ canDownload: true, selectedCount: 7 })
    const btn = w.find('.sr-filterbar__action--download')
    expect(btn.exists()).toBe(true)
    expect(btn.text()).toContain('7')
  })

  it('emittiert download-zip beim Klick', async () => {
    const w = factory({ canDownload: true, selectedCount: 3 })
    await w.find('.sr-filterbar__action--download').trigger('click')
    expect(w.emitted('download-zip')).toBeTruthy()
  })
})
