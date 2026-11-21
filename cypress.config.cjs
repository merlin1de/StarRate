module.exports = {
  e2e: {
    baseUrl: process.env.CYPRESS_NC_URL || process.env.NC_URL || 'http://localhost:8080',

    // api-security laeuft zuletzt: sendet absichtlich falsche Credentials,
    // was NC Brute-Force-Schutz triggert und nachfolgende Login-Tests blockiert.
    specPattern: [
      'tests/e2e/rating-workflow.cy.js',
      'tests/e2e/batch-selection.cy.js',
      'tests/e2e/guest-gallery.cy.js',
      'tests/e2e/guest-sort.cy.js',
      'tests/e2e/guest-message.cy.js',
      'tests/e2e/loupe-navigation.cy.js',
      'tests/e2e/navigation.cy.js',
      'tests/e2e/pick-reject.cy.js',
      'tests/e2e/comments.cy.js',
      'tests/e2e/mobile-virtualization.cy.js',
      'tests/e2e/download.cy.js',
      'tests/e2e/api-security.cy.js',
    ],
    supportFile:        'tests/e2e/support.js',
    screenshotsFolder:  'tests/results/cypress/screenshots',
    videosFolder:       'tests/results/cypress/videos',
    downloadsFolder:    'tests/results/cypress/downloads',

    viewportWidth:  1440,
    viewportHeight: 900,

    defaultCommandTimeout: 10000,
    pageLoadTimeout:       30000,
    requestTimeout:        15000,

    chromeWebSecurity: false,

    env: {
      NC_URL:    process.env.CYPRESS_NC_URL    || process.env.NC_URL    || 'http://localhost:8080',
      NC_USER:   process.env.CYPRESS_NC_USER   || process.env.NC_USER   || 'admin',
      NC_PASS:   process.env.CYPRESS_NC_PASS   || process.env.NC_PASS   || 'admin',
      NC_USER_B: process.env.CYPRESS_NC_USER_B || process.env.NC_USER_B || 'test2',
      NC_PASS_B: process.env.CYPRESS_NC_USER_B || process.env.NC_PASS_B || 'test2',
      // Opt-in: Multi-User-Tests nur ausführen wenn explizit gesetzt
      NC_MULTI_USER: process.env.CYPRESS_NC_MULTI_USER || process.env.NC_MULTI_USER || '',
    },
  },
}
