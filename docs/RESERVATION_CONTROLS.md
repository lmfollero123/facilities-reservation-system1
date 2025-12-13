## Reservation Controls (Current Defaults)

- Active booking cap: max **3** pending+approved reservations per user within the next **30 days**.
- Advance window: bookings allowed from today through **+60 days** only.
- Per-day cap: max **1** pending/approved booking per user per date.
- Conflict check: blocks same facility/date/slot if pending/approved exists; shows alternatives.
- Holiday/event-aware risk: elevated risk on PH holidays and Barangay Culiat events (Fiesta, Founding Day, Christmas, etc.).
- Approval required: bookings remain pending until staff/admin approves.
- Rate limits: login/register already protected; booking is gated by the above caps and conflict checks.

## Rationale / Benchmark

- Caps of 2–5 active bookings per 30–60 days are common to prevent hogging; we use 3 in 30 days.
- Advance window of 30–90 days is typical; we use 60 days to balance planning vs. fairness.
- Per-day cap avoids double-booking by the same user across facilities on the same date.
- Holidays/events see higher demand; surfacing risk helps staff prioritize or request more lead time.

## How It’s Enforced (Code)

File: `resources/views/pages/dashboard/book_facility.php`

- Reject if date < today or date > today + 60 days.
- Count active (pending+approved) within next 30 days; block if ≥ 3.
- Count user’s pending+approved on the selected date; block if ≥ 1.
- Run `detectBookingConflict` to block same facility/date/slot and suggest alternatives.
- Show UI warnings for conflicts and for high risk (including holidays/events).

## How to Adjust

In `book_facility.php`, tweak:
- `$BOOKING_LIMIT_ACTIVE` (default 3)
- `$BOOKING_LIMIT_WINDOW_DAYS` (default 30)
- `$BOOKING_ADVANCE_MAX_DAYS` (default 60)
- `$BOOKING_PER_DAY` (default 1)

## Suggested Extensions (optional)

- Per-slot cap by user: 1 per time slot across facilities for the same date.
- Facility-specific caps: tighter limits for high-demand facilities.
- No-show/cancel penalties: reduce caps for frequent no-shows.
- Admin override: allow staff to bypass caps for official events.





