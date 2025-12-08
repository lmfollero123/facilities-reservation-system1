# Payment Module (Front-End Blueprint)

## Page
- `resources/views/pages/dashboard/payments.php`
- Sidebar entry: “Payments”
- Route hint: `/dashboard/payments`

## Purpose
- Allow staff to record payments for facility reservations.
- Track Official Receipt (OR) numbers and verification status.

## Layout
1. **Record Payment Form (left)**
   - Fields:
     - Reservation Reference
     - Official Receipt (OR) Number
     - Amount Paid
     - Payment Date
     - Payment Channel (Cash, Check, Online Transfer)
   - “Save Payment” button (UI only, no backend yet).

2. **Recent Payments (right)**
   - Table columns:
     - OR Number
     - Payer
     - Facility
     - Amount
     - Status (Verified vs Pending using `status-badge` styles).

## Integration Points
- **Reservation Module:** Link each payment to a reservation record.
- **Reports & Analytics:** Feed payment data into revenue and utilization reports.
- **Audit Trail:** Log payment creation and verification changes.
- **Notifications:** Trigger confirmations when payments are recorded or verified.

## Next Steps
1. Connect the form to backend endpoints for storing payment records.
2. Add search and filtering by OR number, payer, and date range.
3. Support uploading payment proof documents if required by LGU policy.



