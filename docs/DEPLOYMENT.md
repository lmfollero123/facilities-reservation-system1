# Deployment Guide

## Tailwind CSS Build

This project uses Tailwind CSS via the CLI (not the CDN) for production-ready styling.

### Before deploying:

1. **Install dependencies** (if not already done):
   ```bash
   npm install
   ```

2. **Build Tailwind CSS**:
   ```bash
   npm run build:css
   ```

This generates `public/css/tailwind.css`. Ensure this file is committed and deployed with your application.

### For development (optional):

Run the watcher to rebuild CSS on file changes:
```bash
npm run watch:css
```

### Production deployment:

- Run `npm run build:css` as part of your deployment pipeline (e.g., in CI/CD or your build script)
- Or ensure `public/css/tailwind.css` exists from a previous build before deploying
