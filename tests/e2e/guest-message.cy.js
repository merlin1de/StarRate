/**
 * StarRate E2E – Nachricht an den Gast (Issue #105).
 *
 * Owner hinterlegt einen Text am Link, der Gast sieht ihn beim ersten Öffnen,
 * bestätigt ihn (ein Log-Eintrag), und sieht ihn erst wieder, wenn sich der
 * Text ändert. Bei Links mit Passwort kommt die Nachricht erst nach dem Passwort.
 * Ändert der Owner den Text, während der Dialog offen ist, zeigt der Dialog
 * sofort die neue Fassung; geloggt wird nur, was bestätigt wurde.
 */
import { NC_URL, NC_USER, NC_PASS, TEST_FOLDER, login, openFolder, createShare, deleteShare } from './helpers'

const API = `${NC_URL}/index.php/apps/starrate/api`
const MSG = 'Nur zur Auswahl.\nBitte nicht veröffentlichen.'
const guestUrl = (token) => `${NC_URL}/index.php/apps/starrate/guest/${token}`
const ownerReq = (method, url, body) => cy.request({
  method, url, body,
  headers: { 'Content-Type': 'application/json', 'OCS-APIREQUEST': 'true' },
  auth: { user: NC_USER, pass: NC_PASS },
})
const updateShare = (token, body) => ownerReq('PUT', `${API}/share/${token}`, body)
const acks = (token) => ownerReq('GET', `${API}/share/${token}/log`)
  .then(r => r.body.log.filter(e => e.event === 'message_ack'))

const guestImages = (token) => cy.request(`${API}/guest/${token}/images`).its('body.images')
const guestRate = (token, fileId, rating) => cy.request({
  method: 'POST',
  url: `${API}/guest/${token}/rate`,
  body: { file_id: fileId, rating, guest_name: 'Msg-Keys' },
  headers: { 'Content-Type': 'application/json' },
})

function openGuest(token) {
  cy.visit(guestUrl(token))
  cy.get('.sr-grid__item:not(.sr-grid__item--skeleton)', { timeout: 15000 })
    .should('have.length.greaterThan', 0)
}

describe('Guest Gallery – Nachricht an den Gast', () => {
  let token

  afterEach(() => {
    deleteShare(token)
    token = undefined
  })

  it('ohne Nachricht: kein Dialog, kein Briefumschlag', () => {
    createShare({ guest_name: 'Msg-Ohne' }).then(s => {
      token = s.token
      expect(s.guest_message ?? null).to.equal(null)
      openGuest(token)
      cy.get('.sr-guest-message').should('not.exist')
      cy.get('[data-testid=guest-message-open]').should('not.exist')
    })
  })

  it('erster Besuch zeigt die Nachricht, Bestätigen loggt einmal, danach nur noch auf Wunsch', () => {
    createShare({ guest_name: 'Msg-Anna', guest_message: MSG }).then(s => {
      token = s.token
      openGuest(token)

      cy.get('.sr-guest-message', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-guest-message__text').should('have.text', MSG)
      // Zeilenumbruch bleibt sichtbar (Klartext, pre-wrap)
      cy.get('.sr-guest-message__text').should('have.css', 'white-space', 'pre-wrap')

      // Erster Besuch: Escape schließt nicht
      cy.get('body').type('{esc}')
      cy.get('.sr-guest-message').should('be.visible')

      cy.intercept('POST', '**/message/ack').as('ack')
      cy.get('.sr-guest-message__btn').click()
      cy.get('.sr-guest-message').should('not.exist')
      cy.wait('@ack').its('response.statusCode').should('equal', 200)
      acks(token).should('have.length', 1).its('0.message').should('equal', MSG)

      // Reload: bestätigt, also kein Dialog — aber der Briefumschlag öffnet ihn wieder
      openGuest(token)
      cy.wait(1000)
      cy.get('.sr-guest-message').should('not.exist')
      cy.get('[data-testid=guest-message-open]').should('be.visible').click()
      cy.get('.sr-guest-message').should('be.visible')
      cy.get('body').type('{esc}')
      cy.get('.sr-guest-message').should('not.exist')
      cy.focused().should('have.attr', 'data-testid', 'guest-message-open')
      acks(token).should('have.length', 1)

      // Owner ändert den Text → Dialog erscheint erneut, zweite Bestätigung im Log
      updateShare(token, { guest_message: 'Neue Fassung' })
      openGuest(token)
      cy.get('.sr-guest-message__text', { timeout: 10000 }).should('have.text', 'Neue Fassung')
      cy.get('.sr-guest-message__btn').click()
      cy.wait('@ack')
      acks(token).should('have.length', 2)

      // Owner entfernt den Text → kein Briefumschlag mehr
      updateShare(token, { guest_message: null })
      openGuest(token)
      cy.wait(1000)
      cy.get('[data-testid=guest-message-open]').should('not.exist')
      cy.get('.sr-guest-message').should('not.exist')
    })
  })

  it('Link mit Passwort: Nachricht erst nach dem Passwort', () => {
    createShare({ guest_name: 'Msg-PW', guest_message: MSG, password: 'geheim123' }).then(s => {
      token = s.token
      cy.clearCookies()

      // Ohne Passwort weder in der API …
      cy.request({ url: `${API}/guest/${token}/images`, failOnStatusCode: false }).then(r => {
        expect(r.status).to.equal(401)
        expect(JSON.stringify(r.body)).not.to.contain('veröffentlichen')
      })
      // … noch in der Seite
      cy.request(guestUrl(token)).its('body').should('not.contain', 'veröffentlichen')

      cy.visit(guestUrl(token))
      cy.get('.sr-guest-pw__dialog', { timeout: 10000 }).should('be.visible')
      cy.get('.sr-guest-message').should('not.exist')
      cy.get('.sr-guest-pw__input').type('geheim123{enter}')

      // Das Enter aus dem Passwortfeld bestätigt die Nachricht nicht
      cy.get('.sr-guest-message', { timeout: 15000 }).should('be.visible')
      cy.get('.sr-guest-message__text').should('have.text', MSG)
      cy.wait(500)
      cy.get('.sr-guest-message').should('be.visible')
      acks(token).should('have.length', 0)
    })
  })

  it('Owner ändert den Text, während der Dialog offen ist: neue Fassung sofort, nur sie wird geloggt', () => {
    createShare({ guest_name: 'Msg-Live', guest_message: MSG }).then(s => {
      token = s.token
      openGuest(token)
      cy.get('.sr-guest-message__text', { timeout: 10000 }).should('have.text', MSG)
      cy.get('.sr-guest-message__notice').should('not.exist')

      // Owner ändert, während der Gast noch liest
      updateShare(token, { guest_message: 'Neue Fassung' })
      cy.intercept('POST', '**/message/ack').as('ack')
      cy.get('.sr-guest-message__btn').click()
      cy.wait('@ack').its('response.statusCode').should('equal', 409)

      // Dialog bleibt bzw. kommt sofort wieder — mit Hinweis und neuem Text
      cy.get('.sr-guest-message__notice').should('be.visible')
      cy.get('.sr-guest-message__text').should('have.text', 'Neue Fassung')
      cy.get('body').type('{esc}')
      cy.get('.sr-guest-message').should('be.visible')
      acks(token).should('have.length', 0)

      cy.get('.sr-guest-message__btn').click()
      cy.wait('@ack').its('response.statusCode').should('equal', 200)
      cy.get('.sr-guest-message').should('not.exist')
      acks(token).should('have.length', 1).its('0.message').should('equal', 'Neue Fassung')

      // Owner entfernt die Nachricht, während der Dialog offen ist
      updateShare(token, { guest_message: 'Dritte Fassung' })
      openGuest(token)
      cy.get('.sr-guest-message__text', { timeout: 10000 }).should('have.text', 'Dritte Fassung')
      updateShare(token, { guest_message: null })
      cy.get('.sr-guest-message__btn').click()
      cy.wait('@ack').its('response.statusCode').should('equal', 404)
      cy.get('.sr-guest-message').should('not.exist')
      cy.get('[data-testid=guest-message-open]').should('not.exist')
      acks(token).should('have.length', 1)
    })
  })

  it('offener Dialog: Bewertungstaste erreicht die Galerie nicht, Escape lässt die Auswahl stehen', () => {
    createShare({ guest_name: 'Msg-Keys', guest_message: MSG, permissions: 'rate' }).then(s => {
      token = s.token
      // Ausgangslage: die ersten beiden Bilder auf 1 Stern
      guestImages(token).then(imgs => {
        const ids = [imgs[0].id, imgs[1].id]
        ids.forEach(id => guestRate(token, id, 1))

        openGuest(token)
        cy.get('.sr-guest-message__btn', { timeout: 10000 }).click()
        cy.get('.sr-guest-message').should('not.exist')

        cy.get('.sr-grid__item[data-index="0"]').click()
        cy.get('.sr-grid__item[data-index="1"]').click({ shiftKey: true })
        cy.get('.sr-grid__item--selected').should('have.length', 2)

        cy.get('[data-testid=guest-message-open]').click()
        cy.get('.sr-guest-message').should('be.visible')

        // „3“ in Richtung Grid: ohne Sperre würde das die Auswahl bewerten
        // (force: das Overlay liegt über dem Grid — das Event soll trotzdem dort ankommen wollen)
        cy.get('.sr-grid').first().trigger('keydown', { key: '3', bubbles: true, force: true })
        cy.get('body').type('{esc}')
        cy.get('.sr-guest-message').should('not.exist')
        cy.get('.sr-grid__item--selected').should('have.length', 2)

        cy.wait(2500) // Batch-Bewertung hat 2 s Debounce
        guestImages(token).then(after => {
          ids.forEach(id => expect(after.find(i => i.id === id).rating, `Bild ${id}`).to.equal(1))
        })

        // Gegenprobe ohne Dialog: dieselbe Taste bewertet die Auswahl
        cy.get('.sr-grid').first().trigger('keydown', { key: '3', bubbles: true })
        cy.wait(2500)
        guestImages(token).then(after => {
          ids.forEach(id => expect(after.find(i => i.id === id).rating, `Bild ${id}`).to.equal(3))
        })
      })
    })
  })

  it('Owner ändert und entfernt die Nachricht im Freigabe-Dialog', () => {
    createShare({ guest_name: 'Msg-Edit', guest_message: MSG }).then(s => {
      token = s.token
      login()
      openFolder()
      cy.get('.sr-filterbar__actions--desktop .sr-filterbar__action').first().click()
      const item = () => cy.contains('.sr-share-list__item', 'Msg-Edit', { timeout: 10000 })
      item().should('contain', '✉')

      cy.intercept('PUT', '**/api/share/*').as('update')
      item().find('.sr-share-list__action-btn').eq(0).click()
      cy.get('[data-testid=guest-message]').should('have.value', MSG).clear().type('Geändert')
      cy.get('.sr-share-modal__btn--primary').click()
      cy.wait('@update').its('response.body.share.guest_message').should('equal', 'Geändert')

      item().find('.sr-share-list__action-btn').eq(0).click()
      cy.get('[data-testid=guest-message]').should('have.value', 'Geändert').clear()
      cy.get('.sr-share-modal__btn--primary').click()
      cy.wait('@update').then(({ request, response }) => {
        expect(request.body.guest_message).to.equal(null)
        expect(response.body.share.guest_message ?? null).to.equal(null)
      })
      item().should('not.contain', '✉')
    })
  })

  it('Owner legt die Nachricht im Freigabe-Dialog an', () => {
    login()
    openFolder()
    cy.get('.sr-filterbar__actions--desktop .sr-filterbar__action').first().click()
    cy.get('.sr-share-list__new-btn', { timeout: 10000 }).click()
    cy.get('.sr-share-modal', { timeout: 8000 }).should('be.visible')
    // Bei „Bewerten“ ist der Name Pflicht
    cy.get('.sr-share-modal input[type=text]').first().clear().type('Msg-Formular')
    cy.get('[data-testid=guest-message]').type('Hallo aus dem Formular')
    cy.intercept('POST', '**/api/share').as('create')
    cy.get('.sr-share-modal__btn--primary').click()
    cy.wait('@create').then(({ response }) => {
      token = response.body.share.token
      expect(response.body.share.guest_message).to.equal('Hallo aus dem Formular')
      expect(response.body.share.nc_path).to.equal(TEST_FOLDER)
    })
  })
})
