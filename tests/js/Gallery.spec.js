import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { defineComponent } from 'vue'
import axios from '@nextcloud/axios'
import Gallery from '../../src/views/Gallery.vue'

// ── Stubs ──────────────────────────────────────────────────────────────────────

const GridViewStub = defineComponent({
  name: 'GridView',
  props: {
    images: Array,
    loading: Boolean,
    hasActiveFilter: Boolean,
    currentIndex: Number,
    gridColumns: [String, Number],
    showFilename: Boolean,
    showRatingInfo: Boolean,
    showColorInfo: Boolean,
    enablePickUi: Boolean,
    thumbnailUrlFn: Function,
  },
  emits: ['rate', 'batch-rate', 'open-loupe', 'selection-change', 'clear-filter'],
  expose: ['clearSelection', 'selectAll', 'selectedIds'],
  setup(_, { expose }) {
    expose({ clearSelection: vi.fn(), selectAll: vi.fn() })
    return () => null
  },
})

const stubs = {
  GridView: GridViewStub,
  // FilterBar: NOT stubbed — Export/Teilen-Buttons hängen seit Plan "Ordneransicht
  // aufräumen" in der FilterBar, Export-Tests unten prüfen sie via [title="…"]
  LoupeView: { template: '<div />' },
  SelectionBar: {
    name: 'SelectionBar',
    props: { count: Number },
    emits: ['rate', 'clear'],
    template: '<div class="selection-bar-stub" />',
  },
  ShareList:   { template: '<div />' },
  ShareModal:  { template: '<div />' },
  ExportModal: { name: 'ExportModal', props: ['images', 'showPickCol'], emits: ['close'], template: '<div class="export-modal-stub" />' },
  Teleport:    true,
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: {} }],
  })
}

function makeImages() {
  return [
    { id: 1, name: 'A.jpg', rating: 0, color: null, pick: null },
    { id: 2, name: 'B.jpg', rating: 3, color: 'Red', pick: null },
    { id: 3, name: 'C.jpg', rating: 5, color: 'Blue', pick: 'pick' },
  ]
}

function factory(extraProps = {}) {
  const batchRateFn = vi.fn().mockResolvedValue({})
  const loadImagesFn = vi.fn().mockResolvedValue({ images: makeImages(), folders: [] })

  const w = mount(Gallery, {
    props: {
      guestMode: true,  // überspringt Settings-API-Aufruf
      loadImagesFn,
      batchRateFn,
      ...extraProps,
    },
    global: {
      plugins: [makeRouter()],
      stubs,
    },
  })
  return { w, batchRateFn, loadImagesFn }
}

// ── Hilfsfunktionen ────────────────────────────────────────────────────────────

async function selectImages(w, ids) {
  await w.findComponent(GridViewStub).vm.$emit('selection-change', new Set(ids))
}

async function triggerBatchRate(w, rating, color, pick) {
  await w.findComponent(GridViewStub).vm.$emit('batch-rate', rating, color, pick)
}

async function triggerSelectionBarRate(w, rating, color) {
  await w.findComponent({ name: 'SelectionBar' }).vm.$emit('rate', rating, color)
}

// ──────────────────────────────────────────────────────────────────────────────

describe('Gallery – onBatchRate', () => {

  beforeEach(() => {
    vi.resetAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  // ── Sterne ────────────────────────────────────────────────────────────────

  it('batch-rate: Sterne setzen sendet rating an batchRateFn', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 4, undefined, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ rating: 4 })
    )
  })

  it('batch-rate: Sterne entfernen (0) sendet rating=0', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 0, undefined, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      [1],
      expect.objectContaining({ rating: 0 })
    )
  })

  it('batch-rate: Sterne ohne Auswahl sendet keinen Request', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    // Keine Auswahl gesetzt
    await triggerBatchRate(w, 3, undefined, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).not.toHaveBeenCalled()
  })

  // ── Farben ────────────────────────────────────────────────────────────────

  it('batch-rate: Farbe setzen sendet color an batchRateFn', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 3])
    await triggerBatchRate(w, undefined, 'Green', undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 3]),
      expect.objectContaining({ color: 'Green' })
    )
  })

  it('batch-rate: Farbe entfernen (null) sendet color=null — Bug-Fix', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [2])
    await triggerBatchRate(w, undefined, null, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      [2],
      expect.objectContaining({ color: null })
    )
  })

  it('SelectionBar: Farbe entfernen (X-Button) sendet color=null', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerSelectionBarRate(w, undefined, null)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ color: null })
    )
  })

  it('SelectionBar: Farbe setzen sendet kein rating-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerSelectionBarRate(w, undefined, 'Blue')
    vi.advanceTimersByTime(2100)
    await flushPromises()

    const payload = batchRateFn.mock.calls[0][1]
    expect(payload.color).toBe('Blue')
    expect(payload).not.toHaveProperty('rating')
  })

  it('SelectionBar: Sterne setzen sendet kein color-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerSelectionBarRate(w, 3, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    const payload = batchRateFn.mock.calls[0][1]
    expect(payload.rating).toBe(3)
    expect(payload).not.toHaveProperty('color')
  })

  // ── Pick ──────────────────────────────────────────────────────────────────

  it('batch-rate: Pick setzen sendet pick-Feld', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, undefined, undefined, 'pick')
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ pick: 'pick' })
    )
  })

  it('batch-rate: Reject setzen sendet pick=reject', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [3])
    await triggerBatchRate(w, undefined, undefined, 'reject')
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledWith([3], expect.objectContaining({ pick: 'reject' }))
  })

  // ── Debounce-Merge ────────────────────────────────────────────────────────

  it('batch-rate: schnelle Stern+Farbe Klicks werden zu einem Request zusammengeführt', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 3, undefined, undefined)
    await triggerBatchRate(w, undefined, 'Red', undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2]),
      expect.objectContaining({ rating: 3, color: 'Red' })
    )
  })

  it('batch-rate: Stern+Farbe+Pick werden zu einem Request zusammengeführt', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2, 3])
    await triggerBatchRate(w, 4, undefined, undefined)
    await triggerBatchRate(w, undefined, 'Yellow', undefined)
    await triggerBatchRate(w, undefined, undefined, 'pick')
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      expect.arrayContaining([1, 2, 3]),
      expect.objectContaining({ rating: 4, color: 'Yellow', pick: 'pick' })
    )
  })

  it('batch-rate: zweiter Klick mit neuer Auswahl aktualisiert fileIds', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1, 2])
    await triggerBatchRate(w, 3, undefined, undefined)
    // Auswahl ändert sich vor dem zweiten Klick
    await selectImages(w, [3])
    await triggerBatchRate(w, undefined, 'Blue', undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    // fileIds muss dem letzten Stand entsprechen
    expect(batchRateFn).toHaveBeenCalledTimes(1)
    expect(batchRateFn).toHaveBeenCalledWith(
      [3],
      expect.objectContaining({ rating: 3, color: 'Blue' })
    )
  })

  it('batch-rate: zwei separate Klicks mit >1s Abstand senden zwei Requests', async () => {
    const { w, batchRateFn } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 3, undefined, undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    await triggerBatchRate(w, undefined, 'Red', undefined)
    vi.advanceTimersByTime(2100)
    await flushPromises()

    expect(batchRateFn).toHaveBeenCalledTimes(2)
    expect(batchRateFn).toHaveBeenNthCalledWith(1, [1], expect.objectContaining({ rating: 3 }))
    expect(batchRateFn).toHaveBeenNthCalledWith(2, [1], expect.objectContaining({ color: 'Red' }))
  })

  // ── Optimistisches Update ─────────────────────────────────────────────────

  it('batch-rate: Sterne werden optimistisch im lokalen State gesetzt', async () => {
    const { w } = factory()
    await flushPromises()

    await selectImages(w, [1])
    await triggerBatchRate(w, 5, undefined, undefined)

    // Nach dem optimistischen Update (synchron) ist rating bereits 5
    const grid = w.findComponent(GridViewStub)
    const img = grid.props('images').find(i => i.id === 1)
    expect(img.rating).toBe(5)
  })

  it('batch-rate: Farbe wird optimistisch im lokalen State entfernt', async () => {
    const { w } = factory()
    await flushPromises()

    await selectImages(w, [2]) // Bild 2 hat color='Red'
    await triggerBatchRate(w, undefined, null, undefined)

    const grid = w.findComponent(GridViewStub)
    const img = grid.props('images').find(i => i.id === 2)
    expect(img.color).toBeNull()
  })
})

// ──────────────────────────────────────────────────────────────────────────────

describe('Gallery – Gast-Nachricht im Header', () => {
  it('zeigt den Button nur mit Nachricht und meldet den Klick', async () => {
    const { w } = factory({ hasGuestMessage: true })
    await flushPromises()
    await w.find('[data-testid="guest-message-open"]').trigger('click')
    expect(w.emitted('open-guest-message')).toHaveLength(1)
  })

  it('zeigt ohne Nachricht keinen Button', async () => {
    const { w } = factory()
    await flushPromises()
    expect(w.find('[data-testid="guest-message-open"]').exists()).toBe(false)
  })
})

describe('Gallery – Export Modal', () => {
  it('Export-Button ist sichtbar wenn allowExport=true', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    expect(w.find('[title="Bewertungsliste exportieren"]').exists()).toBe(true)
  })

  it('Export-Button öffnet ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    expect(w.find('.export-modal-stub').exists()).toBe(false)
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    expect(w.find('.export-modal-stub').exists()).toBe(true)
  })

  it('ESC schließt ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    expect(w.find('.export-modal-stub').exists()).toBe(true)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await w.vm.$nextTick()
    expect(w.find('.export-modal-stub').exists()).toBe(false)
  })

  it('@close-Event schließt ExportModal', async () => {
    const { w } = factory({ allowExport: true })
    await flushPromises()
    await w.find('[title="Bewertungsliste exportieren"]').trigger('click')
    await w.findComponent({ name: 'ExportModal' }).vm.$emit('close')
    expect(w.find('.export-modal-stub').exists()).toBe(false)
  })
})

// ──────────────────────────────────────────────────────────────────────────────
// Recursive View
// ──────────────────────────────────────────────────────────────────────────────
//
// Tests für die Recursive-View-Verkabelung: Settings-Master-Schalter,
// URL-State-Verarbeitung, FilterBar-Toggle/Stepper und Hover-getriebener
// Breadcrumb-Tail. Anders als die Tests oben braucht das hier den Non-Guest-
// Modus (recursionAvailable wäre sonst zwangsweise false), darum mockt der
// Helper unten den Settings-API-Call explizit.

async function factoryNonGuest({ settings = {}, query = {}, path = '/' } = {}) {
  const settingsResponse = {
    default_sort: 'name', default_sort_order: 'asc',
    show_filename: true, show_rating_overlay: true, show_color_overlay: true,
    grid_columns: 'auto', enable_pick_ui: false, write_xmp: true, comments_enabled: false,
    recursion_enabled: false, recursive_default: false, recursive_default_depth: 0,
    ...settings,
  }
  axios.get.mockImplementation((url) => {
    if (typeof url === 'string' && url.endsWith('/api/settings')) {
      return Promise.resolve({ data: settingsResponse })
    }
    return Promise.resolve({ data: { images: [], folders: [] } })
  })

  // Spiegelt die Production-Route in src/main.js — wichtig damit
  // route.params.path als String-Pfad ankommt (nicht undefined).
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: {} },
      { path: '/folder/:path(.*)', component: {} },
    ],
  })
  // WICHTIG: push und auf isReady warten — sonst hat das Component beim
  // Mount noch keine korrekten route.params/route.query, und die recursive/
  // depth Computeds würden auf den Default-Werten stehen bleiben.
  await router.push({ path, query })
  await router.isReady()

  const w = mount(Gallery, {
    props: {},
    global: { plugins: [router], stubs },
  })
  return { w, router }
}

describe('Gallery – Recursive View', () => {

  beforeEach(() => {
    vi.resetAllMocks()
  })

  // ── Master-Schalter / recursionAvailable ─────────────────────────────────

  it('FilterBar bekommt allow-recursive=false wenn Master-Schalter aus', async () => {
    const { w } = await factoryNonGuest({ settings: { recursion_enabled: false } })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('allowRecursive')).toBe(false)
  })

  it('FilterBar bekommt allow-recursive=true wenn Master-Schalter an', async () => {
    const { w } = await factoryNonGuest({ settings: { recursion_enabled: true } })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('allowRecursive')).toBe(true)
  })

  it('Im Gast-Modus ist Recursive immer unterdrückt', async () => {
    const { w } = factory()  // guestMode=true
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('allowRecursive')).toBe(false)
    expect(w.findComponent({ name: 'FilterBar' }).props('recursive')).toBe(false)
  })

  // ── recursive Computed: URL überschreibt Settings ────────────────────────

  it('recursive aus Settings-Default wenn keine URL-Query', async () => {
    const { w } = await factoryNonGuest({ settings: { recursion_enabled: true, recursive_default: true } })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('recursive')).toBe(true)
  })

  it('?recursive=1 in URL aktiviert auch ohne Settings-Default', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: false },
      query: { recursive: '1' },
    })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('recursive')).toBe(true)
  })

  it('?recursive=0 in URL überschreibt aktivierten Settings-Default', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: true },
      query: { recursive: '0' },
    })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('recursive')).toBe(false)
  })

  it('Master-Schalter aus überschreibt URL-Query', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: false },
      query: { recursive: '1', depth: '3' },
    })
    await flushPromises()
    const fb = w.findComponent({ name: 'FilterBar' })
    expect(fb.props('recursive')).toBe(false)
    expect(fb.props('depth')).toBe(0)
  })

  // ── depth Computed ────────────────────────────────────────────────────────

  it('depth aus URL-Query (gültiger Bereich)', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true },
      query: { depth: '2' },
    })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('depth')).toBe(2)
  })

  it('depth außerhalb 0–4 fällt auf Settings-Default zurück', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default_depth: 1 },
      query: { depth: '99' },
    })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('depth')).toBe(1)
  })

  it('depth Default 0 wenn weder URL noch Settings setzen', async () => {
    const { w } = await factoryNonGuest({ settings: { recursion_enabled: true } })
    await flushPromises()
    expect(w.findComponent({ name: 'FilterBar' }).props('depth')).toBe(0)
  })

  // ── FilterBar-Toggle/Stepper schreiben URL ────────────────────────────────

  it('update:recursive Event schreibt recursive=1 in die URL', async () => {
    const { w, router } = await factoryNonGuest({ settings: { recursion_enabled: true } })
    await flushPromises()
    await w.findComponent({ name: 'FilterBar' }).vm.$emit('update:recursive', true)
    await flushPromises()
    expect(router.currentRoute.value.query.recursive).toBe('1')
  })

  it('update:depth Event schreibt depth in die URL', async () => {
    const { w, router } = await factoryNonGuest({ settings: { recursion_enabled: true } })
    await flushPromises()
    await w.findComponent({ name: 'FilterBar' }).vm.$emit('update:depth', 3)
    await flushPromises()
    expect(router.currentRoute.value.query.depth).toBe('3')
  })

  // ── API-Call übergibt recursive/depth ─────────────────────────────────────

  it('loadImages schickt recursive+depth aus URL an /api/images', async () => {
    factoryNonGuest({
      settings: { recursion_enabled: true },
      query: { recursive: '1', depth: '2' },
      path: '/folder/Photos',
    })
    await flushPromises()
    const imageCall = axios.get.mock.calls.find(c => /\/api\/images/.test(c[0]))
    expect(imageCall).toBeDefined()
    expect(imageCall[1].params).toMatchObject({ recursive: 1, depth: 2 })
  })

  // ── Dynamischer Breadcrumb-Tail ───────────────────────────────────────────

  it('Hover-Event aus GridView aktualisiert hoveredImage', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: true },
    })
    await flushPromises()
    const grid = w.findComponent(GridViewStub)
    await grid.vm.$emit('focus-preview', {
      id: 42, name: 'IMG.jpg', relPath: '2025/Wedding/IMG.jpg',
    })
    await flushPromises()
    // Erwartet: zwei dynamische Segmente (2025, Wedding) im Breadcrumb
    const dynSegs = w.findAll('.sr-breadcrumb__seg--dynamic')
    expect(dynSegs).toHaveLength(2)
    expect(dynSegs[0].text()).toBe('2025')
    expect(dynSegs[1].text()).toBe('Wedding')
  })

  it('Dynamischer Tail bleibt leer wenn recursive=false', async () => {
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: false },
    })
    await flushPromises()
    const grid = w.findComponent(GridViewStub)
    await grid.vm.$emit('focus-preview', {
      id: 1, name: 'A.jpg', relPath: '2025/Wedding/A.jpg',
    })
    await flushPromises()
    expect(w.findAll('.sr-breadcrumb__seg--dynamic')).toHaveLength(0)
  })

  it('Klick auf dynamischen Segment navigiert in den Subfolder ohne Recursion', async () => {
    const { w, router } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: true },
      path: '/folder/Photos',
    })
    await flushPromises()
    await w.findComponent(GridViewStub).vm.$emit('focus-preview', {
      id: 42, name: 'IMG.jpg', relPath: '2025/Wedding/IMG.jpg',
    })
    await flushPromises()
    // Klick auf erstes dynamisches Segment '2025' → /folder/Photos/2025?recursive=0
    await w.findAll('.sr-breadcrumb__seg--dynamic')[0].trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/folder/Photos/2025')
    expect(router.currentRoute.value.query.recursive).toBe('0')
  })

  it('Folder-Wechsel resettet hoveredImage (kein stale Tail)', async () => {
    const { w, router } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: true },
      path: '/folder/Photos',
    })
    await flushPromises()
    // Hover in Folder A → setzt segments
    await w.findComponent(GridViewStub).vm.$emit('focus-preview', {
      id: 1, name: 'A.jpg', relPath: '2025/Wedding/A.jpg',
    })
    await flushPromises()
    expect(w.findAll('.sr-breadcrumb__seg--dynamic')).toHaveLength(2)

    // Navigation in Folder B → Tail muss leer sein (kein stale '2025/Wedding')
    await router.push('/folder/Vacation')
    await flushPromises()
    expect(w.findAll('.sr-breadcrumb__seg--dynamic')).toHaveLength(0)
  })

  // ── Per-Folder-Memory (localStorage) ──────────────────────────────────────

  it('FilterBar-Toggle persistiert Recursive-State pro Folder im localStorage', async () => {
    localStorage.clear()
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true },
      path: '/folder/Photos',
    })
    await flushPromises()

    await w.findComponent({ name: 'FilterBar' }).vm.$emit('update:recursive', true)
    await flushPromises()

    // localStorage hat den Eintrag — anders als der reine URL-Pfad
    const raw = localStorage.getItem('starrate_folder_recursive_v1')
    expect(raw).toBeTruthy()
    const map = JSON.parse(raw)
    expect(map['/Photos']).toEqual({ recursive: true, depth: 0 })
  })

  it('Tiefe-Update persistiert Recursive+Depth zusammen', async () => {
    localStorage.clear()
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: true },
      path: '/folder/Photos',
    })
    await flushPromises()

    await w.findComponent({ name: 'FilterBar' }).vm.$emit('update:depth', 3)
    await flushPromises()

    const map = JSON.parse(localStorage.getItem('starrate_folder_recursive_v1'))
    expect(map['/Photos']).toEqual({ recursive: true, depth: 3 })
  })

  it('localStorage-State wird beim Folder-Open gelesen (vor Settings-Default)', async () => {
    localStorage.clear()
    localStorage.setItem('starrate_folder_recursive_v1', JSON.stringify({
      '/Photos': { recursive: true, depth: 2 },
    }))

    // Settings-Default ist false/0, aber localStorage gewinnt
    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true, recursive_default: false, recursive_default_depth: 0 },
      path: '/folder/Photos',
    })
    await flushPromises()

    const fb = w.findComponent({ name: 'FilterBar' })
    expect(fb.props('recursive')).toBe(true)
    expect(fb.props('depth')).toBe(2)
  })

  it('URL-Query gewinnt vor localStorage', async () => {
    localStorage.setItem('starrate_folder_recursive_v1', JSON.stringify({
      '/Photos': { recursive: true, depth: 4 },
    }))

    const { w } = await factoryNonGuest({
      settings: { recursion_enabled: true },
      path: '/folder/Photos',
      query: { recursive: '0', depth: '1' },  // URL überschreibt
    })
    await flushPromises()

    const fb = w.findComponent({ name: 'FilterBar' })
    expect(fb.props('recursive')).toBe(false)
    expect(fb.props('depth')).toBe(1)
  })
})
