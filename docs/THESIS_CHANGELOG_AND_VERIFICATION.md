# Thesis Changelog and Verification Report

## Scope of Verification

- Source code modules and route mapping
- Database schema and migrations
- Configuration files
- Existing thesis content extracted from `CHAPTER-3-RESEARCH-2.docx`
- School format reference from `Capstone-format-for-finals.docx`

## Incorrect Statements Removed or Flagged

1. **Deployed microservices architecture claim**
   - Issue: Thesis describes independently deployed services with gateway routing.
   - Code-verified state: Application is a modular monolithic PHP deployment with a single front controller.

2. **Equipment reservation and inventory automation claim**
   - Issue: Thesis repeatedly mentions facility and equipment reservation/tracking.
   - Code-verified state: Core reservation flow is for facilities. No standalone equipment reservation module was verified.

3. **AI chatbot listed as mock/pending**
   - Issue: Thesis marks chatbot as frontend-only and pending backend.
   - Code-verified state: Chatbot endpoint and Gemini integration are implemented with fallback behavior.

4. **Maintenance integration listed only as planned**
   - Issue: Thesis states maintenance integration is only planned.
   - Code-verified state: Outbound CIMM sync is implemented and can update status/blackout data when configured.

5. **Payments described as entirely out of scope**
   - Issue: Thesis says online payments are not part of implementation.
   - Code-verified state: PayMongo integration exists as optional and environment-gated.

6. **Seamless live integration for infrastructure/utilities**
   - Issue: Thesis presents these as fully integrated.
   - Code-verified state: Dashboard preview pages exist with mock/sample data; live API integration is not implemented.

## New Implementation Details Added

- Modular monolith architecture wording
- AI chatbot implementation details with Gemini + fallback behavior
- CIMM outbound synchronization and environment dependency
- Optional payment module status
- Distinction between implemented integrations and preview-only integrations
- Chapter language guidance to preserve methodology while correcting technical claims

## Assumptions Explicitly Avoided

- No claim of measurable impact percentages without evidence (example: workload reduction percentages)
- No claim of independent microservice deployment
- No claim of live infrastructure/utilities external API sync
- No claim of equipment booking workflows without verified module support

## Missing Information Requiring User Verification

1. Final approved thesis title to appear on front matter
2. Whether any equipment reservation scope should remain in narrative as future work only
3. Whether PayMongo should be described as implemented optional feature or excluded by project policy
4. Whether CIMM integration is active in actual deployment or only in development setup
5. Final chapter splitting approach (single consolidated file vs separate chapter files)

## Formatting Corrections Needed in Word Document

1. Add spaces after section numbers (example: `3.1 Roles`)
2. Fix merged words in headings and captions (example: `Chapter 3 illustrates`)
3. Standardize figure captions and numbering style
4. Ensure chapter and subsection numbering follows the school template exactly
5. Confirm body and heading styles in Word (font family, point size, spacing) against template styles

