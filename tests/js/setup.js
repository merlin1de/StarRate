import { vi } from 'vitest'

// Vite define-Konstanten für Tests bereitstellen
globalThis.__APP_VERSION__ = '0.9.0-test'

// localStorage mock
const localStorageMock = (() => {
  let store = {}
  return {
    getItem:   (key) => store[key] ?? null,
    setItem:   (key, val) => { store[key] = String(val) },
    removeItem:(key) => { delete store[key] },
    clear:     () => { store = {} },
  }
})()
Object.defineProperty(window, 'localStorage', { value: localStorageMock })

// matchMedia mock
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// ResizeObserver mock — als Klasse statt vi.fn(), damit vi.resetAllMocks()
// (z.B. in Gallery.spec) die Implementierung nicht wegnimmt.
global.ResizeObserver = class {
  observe()    {}
  unobserve()  {}
  disconnect() {}
}

// IntersectionObserver mock
global.IntersectionObserver = vi.fn().mockImplementation(() => ({
  observe:    vi.fn(),
  unobserve:  vi.fn(),
  disconnect: vi.fn(),
}))

// Image mock: jsdom feuert keinen load-Event automatisch. Wir simulieren einen
// erfolgreich geladenen Cache-Hit mit setTimeout(0). Komponenten, die
// `new Image()` für Preloading nutzen (LoupeView), bekommen so nach
// flushPromises ihren onload-Callback.
global.Image = class {
  constructor() { setTimeout(() => this.onload?.(), 0) }
}
