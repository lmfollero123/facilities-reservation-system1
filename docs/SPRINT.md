# Scrum Artifacts (Sprint Representation)

## Scrum Board (To Do → In Progress → Done)

**To Do (current sprint focus)**
- Configure production email: move from Gmail to Brevo with domain + SPF/DKIM/DMARC (8 pts)
- Swap app SMTP config to Brevo (remove Gmail secrets) (3 pts)
- AI chatbot: choose provider (e.g., hosted LLM/API), embed chat widget, and apply safety/allow-listing (8 pts)
- AI chatbot: hook to FAQs/system docs for grounded answers (5 pts)
- Final email/OTP smoke test on production-like setup (3 pts)
- Final mobile QA on booking/approvals and public pages (3 pts)

**In Progress**
- (empty)

**Done (this sprint so far)**
- Email OTP on login (Gmail SMTP working)
- Registration enforces Barangay Culiat + ≥1 required document
- Documents stored under `public/uploads/documents/{userId}` and visible in User Management
- Approval email on user approval
- Auto-approval system: 8-condition evaluation for automatic reservation approval
- Flexible time slots: Changed from fixed 4-hour slots to flexible start/end time selection
- Violation tracking: Record user violations with severity levels affecting auto-approval
- Resident reschedule: Allow residents to reschedule their own reservations (constraints enforced)
- Staff modify/postpone/cancel: Admins can modify approved reservations with date validation
- DFD/WFD/BPMN/ERD/FLOWCHART updated to latest flows
- Backlog documented in `docs/BACKLOG.md`

## Burndown (Textual Approximation)
- Sprint length: 1 week (7 days)
- Starting points: 30 (sum of sprint stories below)
- Today: Day 1 of 7
- Remaining: 30 points
- Ideal trend: 30 → 0 line over 7 days
- Actual trend (text, to be updated): Day 1: 30 → Day 2: … → Day 7: 0

## Sprint Backlog (Prioritized Stories)
1. As an operator, I need Brevo SMTP configured with our domain (SPF/DKIM/DMARC) so production email/OTP deliver reliably. (8 pts)
2. As an operator, I need the app to use Brevo SMTP (not Gmail) with secrets rotated out of code so we’re domain-ready. (3 pts)
3. As an end-user, I can chat with an embedded AI assistant that is safe-listed to our docs/FAQs so I get relevant help. (8 pts)
4. As an admin, I can point the chatbot to curated FAQs/system docs so responses stay grounded. (5 pts)
5. As a QA, I can run a smoke test of OTP/approval emails on the production-like setup so we confirm deliverability. (3 pts)
6. As a mobile user, I can book and see approvals without horizontal scrolling across key pages so the app is usable on phones. (3 pts)

