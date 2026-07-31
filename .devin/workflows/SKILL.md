---
name: code-reviewer
description: Performs thorough code review on changed files or specified code. Use when the user asks for a code review, review of a PR, review of a diff, or wants feedback on code quality, security, or best practices. Checks for bugs, security issues, performance problems, and style.
_agensi: "ae3ad8cb-72ec-4b4e-9fee-8f2f7c10e50a"
---

# Code Reviewer

Perform a thorough, actionable code review that a senior engineer would give. Focus on things that matter. Skip the noise.

## Workflow

1. **Identify what to review**:
   - If the user points to specific files: review those files
   - If in a git repo with uncommitted changes: run `git diff` and review the changes
   - If on a feature branch: run `git diff main...HEAD` (or appropriate base branch) to review all branch changes
   - If the user pastes code: review that code directly
   - For large diffs, prioritize: new files > modified logic > config changes > formatting

2. **Analyze the code** across five dimensions:

   **Bugs & Correctness**
   - Off-by-one errors, null/undefined access, race conditions
   - Unhandled error cases, missing return statements
   - Incorrect logic (wrong operator, inverted condition, missing edge case)
   - Type mismatches or unsafe type coercions

   **Security**
   - SQL injection, XSS, command injection, path traversal
   - Hardcoded secrets, API keys, or credentials
   - Missing input validation or sanitization
   - Insecure defaults (permissive CORS, disabled CSRF, weak hashing)
   - Sensitive data in logs or error messages

   **Performance**
   - N+1 queries, missing database indexes
   - Unnecessary re-renders in React components
   - Unbounded loops or recursive calls
   - Large synchronous operations that should be async
   - Missing pagination on list endpoints

   **Maintainability**
   - Functions doing too many things (>30 lines is a smell, not a rule)
   - Duplicated logic that should be extracted
   - Unclear naming that requires reading the implementation to understand
   - Missing error context (catching and swallowing errors)
   - Dead code or unused imports

   **Architecture**
   - Violations of the patterns already established in the codebase
   - Tight coupling between modules that should be independent
   - Business logic in the wrong layer (controller, view, utility)
   - Missing abstractions or premature abstractions

3. **Format the review**:

## Review Summary

Start with a 2-3 sentence overall assessment. Be direct. State whether this looks good, needs minor fixes, or has serious issues.

### Critical (must fix before merge)
Only issues that would cause bugs, security vulnerabilities, or data loss in production. If there are none, omit this section entirely.

### Suggestions (should fix)
Issues that affect maintainability, performance, or developer experience but would not cause production incidents.

### Nitpicks (optional)
Style preferences, minor naming improvements, or small refactors. Keep to 3 max. If you have more, pick the most impactful.

### What looks good
Briefly call out 1-2 things done well. Specific, not generic. "Good use of early returns to reduce nesting in validateUser" not "Nice code!"

## Rules

- Never suggest changes purely for style if the codebase already has an established pattern. Follow the existing style.
- Never suggest adding comments to self-explanatory code. Only suggest comments for non-obvious business logic or workarounds.
- Always provide the specific file and line context for each issue. Do not make the user hunt for what you are referring to.
- For every issue, briefly explain *why* it matters, not just *what* to change.
- If you suggest a fix, show the corrected code in a fenced code block.
- Do not pad the review with filler. A review with 2 real issues is better than one with 15 trivial ones.
- If the code looks solid, say so. A short "This looks good, ship it" review is valid.
- Calibrate severity honestly. Not every issue is critical. Most are suggestions.

## Example

```
## Review Summary

Solid implementation of the webhook handler. One security issue to fix
before merging, and two suggestions for resilience.

### Critical

**webhook-handler.ts:42** — The request body is passed directly to
`JSON.parse()` without a try-catch. A malformed payload will crash the
worker process.

```typescript
// Before
const payload = JSON.parse(req.body);

// After
let payload;
try {
  payload = JSON.parse(req.body);
} catch {
  return res.status(400).json({ error: 'Invalid JSON' });
}
```

### Suggestions

**webhook-handler.ts:67** — The retry logic uses a fixed 1-second
delay. Consider exponential backoff to avoid hammering the downstream
service during outages.

**webhook-handler.ts:89** — The `processEvent` function handles both
validation and processing. Extracting validation into a separate
function would make unit testing easier.

### What looks good

The idempotency key check at line 35 is well implemented and will
prevent duplicate processing during retries.
```
