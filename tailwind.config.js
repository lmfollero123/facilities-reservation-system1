/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.php',
    './public/js/**/*.js'
  ],
  // preflight/collapse stay OFF while Bootstrap is still loaded (guest_layout.php).
  // TODO(bootstrap-removal): once Bootstrap CSS/JS is removed, re-enable preflight
  // (Tailwind's base reset) and delete the collapse override, then run a visual pass.
  corePlugins: {
    preflight: false,
    // Bootstrap owns .collapse; Tailwind's collapse sets visibility: collapse
    collapse: false
  },
  theme: {
    extend: {
      colors: {
        // Brand palette — mirrors the CSS variables in public/css/style.css (:root).
        // Kept as hex so Tailwind opacity modifiers (e.g. bg-primary/80) work.
        primary: {
          DEFAULT: '#0047ab', // --primary-color
          hover: '#003580',   // --primary-hover
          light: '#6384d2'    // --primary-light
        },
        'gov-blue': {
          DEFAULT: '#6384d2',    // --gov-blue
          dark: '#285ccd',       // --gov-blue-dark
          light: '#6e84b7',      // --gov-blue-light
          'dark-alt': '#40598f'  // --gov-blue-dark-alt
        },
        success: '#2e7d32', // --success-text
        warning: '#f57c00', // --warning-text
        error: '#c62828',   // --error-text
        info: '#1976d2'     // --info-text
      },
      fontFamily: {
        sans: ['"Merriweather Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        serif: ['Merriweather', 'ui-serif', 'Georgia', 'serif']
      }
    }
  },
  plugins: []
}
