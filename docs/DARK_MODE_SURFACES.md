# Dark mode — hardcoded light backgrounds

Scan date: project-wide grep for `#fff`, `#ffffff`, `white`, `#fafb*`, `#f8f*`, etc. in `*.php` and `style.css`.

## Fix strategy (applied)

1. **Theme variables** (`:root` / `html[data-theme="dark"]` in `public/css/style.css`) — `--bg-secondary`, `--bg-tertiary`, `--accent-ai-*`, status colors.
2. **Global dark overrides** — end of `style.css` section *“Dark mode — dashboard cards & hardcoded light surfaces”* remaps common inline `style="background:#…"` patterns.
3. **Book a Facility** — `book_facility.php` page styles use `var(--bg-*)` instead of fixed hex; smart-hints panel uses `--accent-ai-*`.
4. **Utility classes** — `.frs-notice-panel`, `.frs-notice-muted`, `.frs-notice-warn`, `.frs-notice-success` for new markup.

## Highest-traffic files still using inline light backgrounds

These are covered by global `[style*="background:#fff"]` rules in dark mode, but should be migrated to classes over time:

| Area | Files |
|------|--------|
| Modals | `reservations_manage.php`, `reservation_detail.php`, `reservations_hub_mine_tab.php`, `reports.php` |
| Profile | `profile.php` |
| Dashboard home | `index.php` |
| Auth / guest | `register.php`, `guest_layout.php` |
| AI | `ai_chatbot.php` (`.gov-chat-*` — dark rules in `style.css`), floating `.chatbot-panel` (fixed), `ai_scheduling.php` forecast modal |
| Other | `maintenance_integration.php`, `sms_test.php`, `dashboard_layout.php` (session modal) |

## Book a Facility — fixed in this pass

- `.booking-card` / purpose-first card / smart-hints bar
- Hub tabs, calendar facility select, modal shell
- Supporting-document block → `.frs-notice-muted`

## How to verify

1. Enable dark mode in the dashboard.
2. Open **Book a Facility** — purpose card and “Top match” panel should match surrounding dark surfaces.
3. Open **New reservation** modal — consistent inputs and panels.
4. Spot-check **Profile**, **My Reservations** modals, **Reservations manage**.

## Adding new UI

Prefer:

```html
<div class="frs-notice-panel frs-notice-warn">…</div>
```

or `background: var(--bg-tertiary)` in CSS — avoid `background:#fff` / `#fafbff` in inline styles.
