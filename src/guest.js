/**
 * StarRate – Guest Gallery entry point
 *
 * Loaded by templates/guest.php (standalone, no Nextcloud layout).
 * Reads data-* attributes from #starrate-guest-root and mounts GuestGallery.
 *
 * Memory-Router wird installiert damit Gallery.vue useRoute()/useRouter()
 * unverändert nutzen kann — der Router selbst wird von Gallery.vue nur für
 * die Pfad-Navigation benutzt (kein RouterView).
 */

import { createApp } from 'vue'
import { createRouter, createMemoryHistory } from 'vue-router'
import GuestGallery from './views/GuestGallery.vue'
import '../css/starrate.css'

const el = document.getElementById('starrate-guest-root')

if (el) {
  const token        = el.dataset.token        ?? ''
  const canRate      = el.dataset.canRate      === 'true'
  const allowRating  = el.dataset.allowRating  !== 'false'
  const allowColor   = el.dataset.allowColor   !== 'false'
  const allowPick    = el.dataset.allowPick    === 'true'
  const allowExport  = el.dataset.allowExport  === 'true'
  const allowComment = el.dataset.allowComment === 'true'
  const allowDownload = el.dataset.allowDownload === 'true'
  const guestName    = el.dataset.guestName    ?? ''

  // Memory-Router: dieselben Routen wie main.js, aber ohne URL-Seiteneffekte
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: {} },
      { path: '/folder/:path(.*)', component: {} },
    ],
  })

  const app = createApp(GuestGallery, { token, canRate, allowRating, allowColor, allowPick, allowExport, allowComment, allowDownload, guestName })
  app.use(router)
  router.isReady().then(() => app.mount(el))
}
