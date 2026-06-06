# Google Gemini / GCP Suspension — Appeal Guide & Security Checklist

**Project:** Barangay Culiat Public Facilities Reservation System (CPRF / PFRS)  
**Google Cloud project:** PFRS (`gen-lang-client-0971205511`)  
**Last updated:** May 2026

Use this document to **submit a Google appeal**, **secure the API key**, and **explain measures** to advisers or defense panel.

---

## 1. What happened (plain language)

Google suspended the **Gemini API / Google Cloud project** used for CPRF’s AI features (chatbot and optional report summaries). The email cited **“abusive activity consistent with hijacked resources.”**

This is **not** a Cloudflare suspension. Cloudflare protects the **website**; Google protects the **API key and GCP project**.

### Root cause confirmed (May 2026)

**Production `.env` was publicly downloadable** at:

`https://cprf.infragovservices.com/.env`

Anyone who visited that URL could download the full environment file, including `GEMINI_API_KEY`, database credentials, mail/SMS tokens, and other secrets. This is the most likely explanation for unauthorized Gemini API usage — not git history (the key was never committed).

**Treat all secrets in that file as compromised.** Rotate everything before re-enabling Gemini.

Common causes (in addition to the `.env` leak):

- API key was **unrestricted** in Google AI Studio (no IP / API limits)
- **High automated traffic** to Gemini through the app before rate limits existed
- Key shared in development documents or screenshots

---

## 2. Appeal text (copy and paste)

Submit via the link in Google’s email. Adjust names/dates as needed.

---

**Subject:** Appeal — Project PFRS (gen-lang-client-0971205511) — Barangay Culiat Facility Reservation System

**Message:**

Hello Google Cloud Trust & Safety team,

I am writing to appeal the suspension of Google Cloud project **PFRS** (Project ID: **gen-lang-client-0971205511**).

**About the project**  
This project supports the **Barangay Culiat Public Facilities Reservation System (CPRF)**, a capstone web application for a local government unit (LGU) in the Philippines. It allows residents to book barangay facilities and staff to approve reservations.

**Legitimate use of Gemini API**  
We use the Generative Language API (Gemini) only for:

1. **AI Assistant (chatbot)** — logged-in users can ask about booking policies, facility availability, and their reservations.  
2. **Reports AI Summary (optional)** — Admin/Staff can generate a short narrative summary from booking statistics on the Reports page.

We do **not** run bulk scraping, spam generation, crypto mining, or reselling API access. All calls originate from our PHP application server at **https://cprf.infragovservices.com**.

**Possible trigger**  
We confirmed that our production **`.env` file was publicly accessible** via direct URL (`https://cprf.infragovservices.com/.env`), which exposed the Gemini API key and other credentials. We believe unauthorized third parties used the leaked key. Additional factors may include:

- The API key was **created without IP or application restrictions** in Google AI Studio.  
- **Automated or excessive requests** to the chatbot endpoint before we added rate limiting and Cloudflare protection.

**Remedial actions already taken or in progress**

1. **Blocked web access** to `.env`, `config/`, `database/`, and other sensitive paths (updated `.htaccess` + per-directory denies).  
2. **Revoked the old API key** and will use a **new restricted key** only after appeal approval.  
3. **Rotating all secrets** that were in `.env` (database, mail, SMS, payment, Turnstile, etc.).  
4. **Cloudflare** is enabled on the production site (WAF, bot mitigation, Turnstile on auth forms).  
5. **Rate limiting** on AI endpoints in CPRF: chatbot (25 messages/user/hour), AI report summary (12/hour/user).  
6. **Authentication required** for all chatbot API calls (401 if not logged in).  
7. We will **restrict the new API key** to our server IP and Generative Language API only.

**Business purpose**  
This is an educational / LGU public-service system, not a commercial AI product. Gemini improves resident support (FAQ-style assistance) and helps staff interpret booking trends.

**Third-party compromise**  
If Google logs show abuse from unexpected regions or volumes, we believe it was **unauthorized use of our API credentials**, not intentional misuse by our team. We have rotated credentials and tightened controls.

We respectfully request **restoration of the project** so we can continue limited, authenticated use for our capstone and barangay operations. We are happy to provide additional logs or restrict usage further if required.

Thank you for your review.

Sincerely,  
[Your full name]  
[Your role — e.g. Capstone developer / PFRS project owner]  
[Email on the Google account]  
Organization: Barangay Culiat Facilities Reservation System  
Site: https://cprf.infragovservices.com

---

## 3. Security checklist (do before and after appeal)

### Google / Gemini

- [ ] Open [Google AI Studio](https://aistudio.google.com/) or Cloud Console → **APIs & Services → Credentials**
- [ ] **Delete or disable** the compromised API key
- [ ] Create a **new** API key after appeal (or use a new project if Google requires it)
- [ ] Restrict new key: **API restrictions** = Generative Language API only
- [ ] Restrict new key: **Application restrictions** = IP addresses (your hosting server IP only)
- [ ] Set **billing alerts** / quota limits in Google Cloud if available
- [ ] Confirm project owner email matches the account submitting the appeal

### CPRF application (server)

- [ ] **Deploy updated `.htaccess`** (blocks `/.env`, `config/`, etc.) — verify **403 Forbidden**, not a download
- [ ] Test these URLs return **403** (not 200): `/.env`, `/config/gemini_config.php`, `/config/database.php`, `/composer.json`
- [ ] **Move `.env` outside web root** if possible (e.g. `/home/user/cprf.env` loaded by PHP, not in `public_html`)
- [ ] Update production `.env`: `GEMINI_API_KEY=<new_key>` after rotation  
- [ ] Or update `config/gemini_config.php` (gitignored) — never commit this file
- [ ] Deploy latest code with **Gemini rate limits** (`config/security.php`)
- [ ] Test chatbot while logged in; confirm 429 message after heavy use (optional stress test)
- [ ] Test Reports → Generate AI Summary as Admin

### Rotate ALL secrets (assume `.env` was stolen)

- [ ] `GEMINI_API_KEY` — revoke in Google AI Studio  
- [ ] Database password — change in MySQL + `.env`  
- [ ] SMTP / mail credentials  
- [ ] `IPROG_API_TOKEN` (SMS)  
- [ ] PayMongo keys (if used)  
- [ ] Cloudflare Turnstile secret keys  
- [ ] Any other values from the downloaded `.env`

### Cloudflare (already in place — verify)

- [ ] Proxy enabled for `cprf.infragovservices.com`
- [ ] Turnstile on login/register (if configured)
- [ ] Bot Fight Mode or equivalent WAF rules enabled
- [ ] SSL/TLS mode: Full (strict) if origin has valid cert

### Git / secrets hygiene

- [ ] Search repo history for leaked keys: `git log -p | findstr AIza` (Windows) or `git log -p | grep AIza`
- [ ] If key was ever committed: rotate key and consider `git filter-repo` or BFG (advanced)
- [ ] Confirm `.gitignore` includes `.env` and `config/gemini_config.php`

### Monitoring

- [ ] Check Google Cloud **API metrics** weekly during capstone demo period
- [ ] Check CPRF `rate_limits` table for spikes on `gemini_chat_user` / `gemini_report`
- [ ] Review server access logs for repeated POSTs to `/dashboard/ai-chatbot`

---

## 4. CPRF rate limits (implemented)

| Action | Limit | Window | Identifier |
|--------|-------|--------|------------|
| AI chatbot message | 25 | 1 hour | Logged-in user ID |
| AI chatbot (fallback) | 40 | 1 hour | Client IP |
| Reports AI summary | 12 | 1 hour | Admin/Staff user ID |

Constants in `config/security.php`. Helpers: `checkGeminiChatbotRateLimit()`, `checkGeminiReportSummaryRateLimit()`.

If Gemini is unavailable (suspended key), chatbot falls back to rule-based responses; Reports falls back to rule-based insights.

---

## 5. While waiting for Google

1. Leave `GEMINI_API_KEY` empty in production **or** use the old key only if Google restores it — do not create unrestricted keys.  
2. Demo the app with **rule-based chatbot** responses (still works).  
3. Demo Reports with **Print Summary** and charts without AI narrative.  
4. Mention in defense: *“Gemini integration is implemented with rate limits and auth; we are awaiting Google project reinstatement after securing the API key.”*

---

## 6. Related files

| File | Purpose |
|------|---------|
| `config/gemini_chatbot.php` | Gemini API calls |
| `config/gemini_config.php` | API key (gitignored) |
| `config/security.php` | Rate limit helpers |
| `resources/views/pages/dashboard/ai_chatbot.php` | Chatbot endpoint |
| `resources/views/pages/dashboard/reports.php` | AI summary endpoint |

---

*Prepared for CPRF capstone / LGU operations — May 2026*
