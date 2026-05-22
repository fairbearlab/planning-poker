import { defineConfig } from 'vitest/config';

// Dev-only. Drives the vanilla-JS frontend (app.js) in jsdom — no build step,
// no bundler. Tests load the real app.js into the real app.html DOM and exercise
// it through DOM events with a mocked fetch (see tests/js/harness.js).
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.js'],
    globals: false,
    restoreMocks: true,
  },
});
