/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.php',
    './public/js/**/*.js'
  ],
  // Disable preflight to avoid breaking Bootstrap (navbar, forms, etc.)
  corePlugins: {
    preflight: false,
    // Disable collapse - Bootstrap uses .collapse class; Tailwind's collapse sets visibility: collapse
    collapse: false
  },
  theme: {
    extend: {}
  },
  plugins: []
}
