# Local Optimizations (XAMPP) — What to Apply Now

This checklist is for your **current XAMPP/local setup** (before subdomain deployment).

## 1) Database optimization (required)

Run:
- `database/migration_add_document_archival.sql`

This adds:
- `data_exports` and `document_retention_policy` tables
- archival columns/indexes for `user_documents`
- additional performance indexes

## 2) Scheduled tasks (Windows Task Scheduler)

We will use:
- PHP CLI: `C:\PHP\php.exe`

Create scheduled tasks by running:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
cd E:\Capstone_project\facilities_reservation_system
.\scripts\windows_task_scheduler_setup.ps1
```

Tasks created:
- **FRS - Archive Documents (Daily)** @ 02:00
- **FRS - Auto-Decline Expired Reservations (Daily)** @ 03:00
- **FRS - Cleanup Old Data (Weekly)** (Sun) @ 04:00
- **FRS - Optimize Database (Weekly)** (Sun) @ 06:00

Logs:
- `storage/task_logs/*.log`

Remove tasks (if needed):

```powershell
.\scripts\windows_task_scheduler_setup.ps1 -Remove
```

## 3) Storage folders

Ensure these exist (created by the system already):
- `storage/archive/documents/`
- `storage/exports/`
- `storage/task_logs/` (created by scheduler setup script)

## 4) Manual testing

Archival dry-run:

```powershell
cd E:\Capstone_project\facilities_reservation_system
C:\PHP\php.exe scripts\archive_documents.php --dry-run --verbose
```

Export test:
- Dashboard → Profile → Data Export → Generate export
- Click **View** → **Print / Save as PDF** (sidebar hidden by print CSS)




