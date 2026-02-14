# Deployment Guide: IndevFinite cPanel Hosting

**Barangay Culiat Public Facilities Reservation System**

This guide walks you through deploying updates to your IndevFinite cPanel hosting when pushing changes from GitHub. Since you already have an existing deployment, this focuses on **pulling updates** and handling **gitignored files** that won't come from the repo.

---

## Table of Contents

1. [Pre-Deployment Checklist](#1-pre-deployment-checklist)
2. [Git-Ignored Files (What Won't Pull)](#2-git-ignored-files-what-wont-pull)
3. [Deployment Steps](#3-deployment-steps)
4. [Post-Deployment Verification](#4-post-deployment-verification)
5. [Troubleshooting](#5-troubleshooting)

---

## 1. Pre-Deployment Checklist

Before pulling from GitHub:

- [ ] **Backup your database** (cPanel → phpMyAdmin → Export)
- [ ] **Backup these files** (download via File Manager or SFTP):
  - `config/database.php`
  - `config/gemini_config.php` (if you use the AI chatbot)
  - `config/geocoding_config.php` (if you use geocoding)
- [ ] **Note your Gemini API key** (if you have one) – you'll need it to recreate `gemini_config.php` if it's overwritten or missing
- [ ] **Ensure `public/uploads/`** contains `Main Bg.jpg` (used for home, login, register backgrounds) – this folder is gitignored

---

## 2. Git-Ignored Files (What Won't Pull)

These files/folders are in `.gitignore` and **will not be updated** when you pull. They must exist on the server and will be **preserved** during a normal `git pull` (Git doesn't delete untracked files). However, if they don't exist yet, you must create them manually.

| File / Folder | Purpose | Action |
|---------------|---------|--------|
| `config/database.php` | Database credentials | **Keep your existing copy.** Do NOT overwrite. If missing, create from your local backup. |
| `config/gemini_config.php` | Gemini AI API key for chatbot | **Create from example** if missing. See [Setup Gemini Config](#setup-gemini-config) below. |
| `config/geocoding_config.php` | Geocoding API (optional) | Only needed if using address geocoding. Copy from `geocoding_config.example.php` if required. |
| `public/uploads/` | User uploads, facility images, Main Bg | **Ensure directory exists** with write permissions. Upload `Main Bg.jpg` if missing. |
| `public/img/announcements/` | Announcement images | Recreated by app when admins upload. Ensure writable. |
| `public/img/facilities/` | Facility images | Same as above. |
| `logs/` | Application logs | Auto-created. Ensure writable. |
| `storage/` | Exports, task logs, etc. | Auto-created. Ensure writable. |

---

## 3. Deployment Steps

### Step 1: Connect to Your Server

**Option A – cPanel File Manager + Terminal**

1. Log in to cPanel.
2. Open **Terminal** (or **SSH Access** if enabled).
3. Navigate to your app directory, e.g.:
   ```bash
   cd ~/public_html
   # or, if your app is in a subfolder:
   cd ~/public_html/facilities_reservation_system
   ```

**Option B – SSH (if available)**

```bash
ssh your_username@yourdomain.com
cd public_html/facilities_reservation_system   # or your app path
```

---

### Step 2: Backup Git-Ignored Config Files (Optional but Recommended)

Before pulling, copy your configs to a safe location:

```bash
cp config/database.php config/database.php.bak
cp config/gemini_config.php config/gemini_config.php.bak 2>/dev/null || true
```

---

### Step 3: Pull from GitHub

```bash
git pull origin main
```

*(Use `master` instead of `main` if that's your default branch.)*

If you see conflicts, resolve them before continuing. Do **not** let Git overwrite `config/database.php` or `config/gemini_config.php` with an empty or example file.

---

### Step 4: Restore / Create Git-Ignored Files

**A. `config/database.php`**

- If it still exists and is correct, no action needed.
- If it was overwritten or missing, restore from your backup:
  ```bash
  cp config/database.php.bak config/database.php
  ```

**B. `config/gemini_config.php` (AI Chatbot)**

See [Setup Gemini Config](#setup-gemini-config) below.

**C. `config/geocoding_config.php`**

- Only needed if you use geocoding.
- Copy from `config/geocoding_config.example.php` and add your API key.

---

### Step 5: Ensure Upload Directories Exist and Are Writable

```bash
mkdir -p public/uploads
mkdir -p public/img/announcements
mkdir -p public/img/facilities
mkdir -p public/uploads/profile_pictures
mkdir -p logs
mkdir -p storage/exports storage/task_logs storage/private 2>/dev/null || true

chmod -R 755 public/uploads public/img/announcements public/img/facilities
chmod -R 755 logs storage
```

**Main background image**

- The home, login, and register pages use `public/uploads/Main Bg.jpg`.
- If it's missing after deployment, upload it manually via File Manager or SFTP to `public/uploads/`.
- Exact filename: `Main Bg.jpg` (with space).

---

### Step 6: Run Database Migrations (If Any)

If new migrations were added:

1. Check the `database/` folder for new `.sql` migration files.
2. Run them via cPanel **phpMyAdmin** (Import) or command line:
   ```bash
   php -r "
   require 'config/database.php';
   // Or run: mysql -u USER -p DATABASE < database/migration_xxx.sql
   "
   ```
   Or import each migration file manually in phpMyAdmin.

---

### Step 7: Clear Caches (If Applicable)

If you use opcache or a cache layer:

- cPanel → **Select PHP Version** → **Options** → reset opcache, or
- Restart PHP-FPM if you have access.

---

## Setup Gemini Config

The Gemini API config is gitignored. To enable the AI chatbot:

1. **Create the config file:**
   ```bash
   cp config/gemini_config.example.php config/gemini_config.php
   ```

2. **Edit and add your API key:**
   ```bash
   nano config/gemini_config.php
   ```
   Or use cPanel File Manager → Edit.

3. **Replace the placeholder:**
   ```php
   define('GEMINI_API_KEY', 'YOUR_ACTUAL_API_KEY_HERE');
   ```

4. **Get an API key** (if you don't have one):  
   https://aistudio.google.com/app/apikey

5. **Restrict file permissions** (recommended):
   ```bash
   chmod 640 config/gemini_config.php
   ```

Without `gemini_config.php`, the chatbot will fall back to mock/rule-based responses.

---

## 4. Post-Deployment Verification

- [ ] **Homepage** loads and shows Main Bg
- [ ] **Login / Register** pages show the Main Bg and work
- [ ] **Dashboard** loads for logged-in users
- [ ] **AI Chatbot** opens and responds (if Gemini is configured)
- [ ] **Book Facility** works and shows facilities
- [ ] **File uploads** work (e.g. facility images, profile pictures)

---

## 5. Troubleshooting

### "Database connection failed"

- Restore `config/database.php` from backup.
- Confirm DB host, username, password, and database name match your cPanel MySQL setup.

### "Main Bg" or blank background on home/login/register

- Ensure `public/uploads/Main Bg.jpg` exists.
- Check file permissions: `chmod 644 public/uploads/Main Bg.jpg`.

### AI Chatbot not using Gemini / still using mock responses

- Confirm `config/gemini_config.php` exists and defines `GEMINI_API_KEY`.
- Check PHP error logs for API errors.
- Test the key locally first.

### 500 Internal Server Error

- Check `.htaccess` is present and readable.
- Check Apache error logs (cPanel → Errors).
- Verify `config/database.php` and `config/gemini_config.php` have no PHP syntax errors.

### CSS / JS not loading

- Clear browser cache.
- Confirm `public/css/` and `public/js/` were updated by the pull.
- Check `base_path()` and asset URLs if using a subdirectory.

### "Permission denied" on uploads or logs

```bash
chmod -R 755 public/uploads public/img logs storage
```

If your server uses a specific web user (e.g. `nobody`, `apache`), you may need to adjust ownership; contact IndevFinite support if unsure.

---

## Quick Reference: Files to Manually Handle After Pull

| File | If Missing |
|------|------------|
| `config/database.php` | Restore from backup; contains DB credentials |
| `config/gemini_config.php` | Copy from `gemini_config.example.php`, add API key |
| `public/uploads/Main Bg.jpg` | Upload manually |
| `public/uploads/` (writable) | `mkdir -p public/uploads && chmod -R 755 public/uploads` |

---

*Document version: 1.0 | Last updated: January 2025*
