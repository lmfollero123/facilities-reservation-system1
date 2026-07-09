# AI Rules for Coding Assistants

## Purpose

This document provides guidelines and rules for AI coding assistants working on the Facilities Reservation System. It ensures consistency, security, and maintainability when AI agents contribute to the codebase.

## Core Principles

1. **Do Not Modify Without Permission**: Never modify, refactor, rename, or generate application code unless explicitly asked by the user.
2. **Read Before Edit**: Always read a file before editing it to understand the context.
3. **Minimal Changes**: Make the smallest possible change to achieve the goal.
4. **Follow Existing Patterns**: Match the existing code style, naming conventions, and architectural patterns.
5. **Security First**: Never compromise security for convenience.
6. **Test Your Changes**: Ensure code is immediately runnable and doesn't break existing functionality.

## Code Style Guidelines

### PHP

#### Naming Conventions

- **Functions**: `snake_case` with `frs_` prefix for core functions
  ```php
  function frs_has_permission($role, $module, $action) {}
  function sendEmail($to, $name, $subject, $body) {}
  ```

- **Variables**: `snake_case`
  ```php
  $user_id = 1;
  $facility_name = 'Convention Hall';
  ```

- **Constants**: `UPPER_SNAKE_CASE`
  ```php
  define('CSRF_TOKEN_NAME', 'csrf_token');
  define('SESSION_TIMEOUT', 300);
  ```

- **Classes**: `PascalCase` (rarely used in this codebase)
  ```php
  class PaymentGateway {}
  ```

- **Files**: `snake_case.php`
  ```
  book_facility.php
  reservation_helpers.php
  ```

#### Code Organization

- **Config files**: Place business logic in `config/*.php`
- **Views**: Place presentation logic in `resources/views/pages/*.php`
- **Helpers**: Use helper functions rather than classes for simple operations
- **Database**: Use PDO with prepared statements only

#### Security Requirements

- **SQL**: Always use prepared statements, never concatenate user input
  ```php
  // GOOD
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
  $stmt->execute(['id' => $user_id]);
  
  // BAD
  $result = $pdo->query("SELECT * FROM users WHERE id = $user_id");
  ```

- **Output**: Always escape output with `htmlspecialchars()` or `e()` helper
  ```php
  echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
  // or
  echo e($user_name);
  ```

- **CSRF**: Include CSRF token in all forms
  ```php
  <?= csrf_field(); ?>
  ```

- **Input**: Sanitize input with `sanitizeInput()` for non-SQL contexts
  ```php
  $email = sanitizeInput($_POST['email'], 'email');
  ```

#### Error Handling

- Use try-catch for database operations
- Log errors with `error_log()`
- Return user-friendly error messages
- Never expose stack traces to users

```php
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    return null;
}
```

### JavaScript

#### Naming Conventions

- **Functions**: `camelCase`
  ```javascript
  function initDashboardNav() {}
  function focusFirstInvalid() {}
  ```

- **Variables**: `camelCase`
  ```javascript
  const dashboardNavProgress = document.getElementById('dashboardNavProgress');
  let isBusy = false;
  ```

- **Constants**: `UPPER_SNAKE_CASE`
  ```javascript
  const MAIN_SEL = '.dashboard-content';
  const FADE_MS = 300;
  ```

- **Files**: `kebab-case.js`
  ```
  dashboard-navigation.js
  frs-form-validation.js
  ```

#### Code Organization

- Use IIFE pattern for module encapsulation
  ```javascript
  (function () {
      'use strict';
      // Module code here
  })();
  ```

- Use event delegation for dynamic elements
  ```javascript
  document.addEventListener('click', function (event) {
      const link = event.target.closest('a');
      if (!link) return;
      // Handle click
  });
  ```

- Use async/await for asynchronous operations
  ```javascript
  async function navigate(url) {
      const response = await fetch(url);
      const html = await response.text();
      // Process response
  }
  ```

#### Security Requirements

- Never trust client-side data
- Validate on server-side
- Use CSRF tokens for AJAX requests
- Sanitize DOM input before insertion

```javascript
// GOOD
const div = document.createElement('div');
div.textContent = userInput; // Escapes HTML
document.body.appendChild(div);

// BAD
document.body.innerHTML = userInput; // XSS vulnerability
```

### CSS

#### Naming Conventions

- **Classes**: `kebab-case`
  ```css
  .dashboard-content {}
  .sidebar-link {}
  ```

- **BEM-like**: `block__element--modifier`
  ```css
  .sidebar-link {}
  .sidebar-link--active {}
  .sidebar__user-info {}
  ```

- **Files**: `kebab-case.css`
  ```
  dashboard-pages.css
  public-pages.css
  ```

#### Code Organization

- Use TailwindCSS utilities where possible
- Add custom classes for complex components
- Keep specificity low
- Use CSS variables for theme values

## Architecture Rules

### File Placement

- **New config helpers**: Place in `config/*.php`
- **New dashboard pages**: Place in `resources/views/pages/dashboard/*.php`
- **New public pages**: Place in `resources/views/pages/public/*.php`
- **New components**: Place in `resources/views/components/*.php`
- **New scripts**: Place in `scripts/*.php`
- **New migrations**: Place in `database/migration_*.sql`

### Include Order

When including files, follow this order:

1. `config/app.php` (always first)
2. `config/database.php` (if database needed)
3. `config/security.php` (if security needed)
4. Other config files as needed
5. View-specific includes

```php
require_once __DIR__ . '/../../../../config/app.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/security.php';
require_once __DIR__ . '/../../../../config/permissions.php';
```

### Database Operations

- **Use PDO only**: No other database libraries
- **Prepared statements**: Always use prepared statements
- **Transactions**: Use transactions for multi-step operations
  ```php
  $pdo->beginTransaction();
  try {
      // Operations
      $pdo->commit();
  } catch (Exception $e) {
      $pdo->rollBack();
      throw $e;
  }
  ```

- **Connection**: Use the `db()` function to get PDO instance
  ```php
  $pdo = db();
  ```

### Routing

- **Do not modify index.php**: Unless adding a new route pattern
- **Route mapping**: Add new routes to the route mapping in `index.php`
- **Clean URLs**: Use clean URLs, not file paths
  ```php
  // GOOD
  '/dashboard/book-facility' => 'resources/views/pages/dashboard/book_facility.php'
  
  // BAD
  '/resources/views/pages/dashboard/book_facility.php'
  ```

## Security Rules

### CSRF Protection

- **All forms**: Must include CSRF token
  ```php
  <?= csrf_field(); ?>
  ```

- **All AJAX POST**: Must include CSRF token
  ```javascript
  formData.append('csrf_token', window.CSRF_TOKEN);
  ```

- **Token verification**: Verify token on server
  ```php
  if (!verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
      $error = 'Invalid security token';
  }
  ```

### Input Validation

- **Never trust user input**: Always validate and sanitize
- **Type validation**: Check data types before use
- **Range validation**: Check numeric ranges
- **Enum validation**: Check against allowed values

```php
// Validate integer
$facility_id = (int)($_POST['facility_id'] ?? 0);
if ($facility_id <= 0) {
    $error = 'Invalid facility';
}

// Validate enum
$status = $_POST['status'] ?? '';
$allowed = ['pending', 'approved', 'denied'];
if (!in_array($status, $allowed, true)) {
    $error = 'Invalid status';
}
```

### Output Escaping

- **HTML output**: Always escape with `htmlspecialchars()`
- **JavaScript output**: Use `json_encode()` with proper flags
- **SQL output**: Use prepared statements

```php
// HTML
echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');

// JavaScript
echo json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
```

### File Uploads

- **Validate MIME type**: Not just extension
- **Validate file size**: Enforce size limits
- **Generate secure filename**: Use random + original name
- **Store outside web root**: When possible
- **Path validation**: Prevent directory traversal

```php
$allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowedTypes)) {
    $error = 'Invalid file type';
}

if ($file['size'] > $maxSize) {
    $error = 'File too large';
}
```

### Session Security

- **Regenerate ID**: Regenerate session ID on login
- **Secure cookies**: Use HttpOnly, Secure, SameSite
- **Timeout**: Implement session timeout
- **Destroy on logout**: Destroy session completely

## Database Rules

### Schema Changes

- **Use migrations**: All schema changes must be in migration files
- **Naming**: Use descriptive table and column names
- **Foreign keys**: Always define foreign keys with proper CASCADE rules
- **Indexes**: Add indexes for frequently queried columns
- **Timestamps**: Include `created_at` and `updated_at` on most tables

### Migration Format

```sql
-- Migration: [Description]
-- Purpose: [What this migration does]
-- Date: YYYY-MM-DD

USE facilities_reservation;

-- Add column
ALTER TABLE table_name
ADD COLUMN column_name VARCHAR(255) NOT NULL DEFAULT '';

-- Update existing data
UPDATE table_name SET column_name = 'default_value' WHERE column_name IS NULL;
```

### Query Optimization

- **Use indexes**: Ensure queries use indexes
- **Avoid SELECT ***: Select only needed columns
- **Use LIMIT**: Limit results when possible
- **Avoid N+1 queries**: Use JOINs instead of multiple queries

## Testing Rules

### Before Submitting Changes

1. **Read the file**: Understand the context
2. **Check syntax**: Ensure no syntax errors
3. **Follow patterns**: Match existing code style
4. **Security check**: Verify no security vulnerabilities
5. **Test mentally**: Walk through the code logic

### What Not to Do

- **Do not refactor**: Unless explicitly asked
- **Do not rename**: Unless explicitly asked
- **Do not reorganize**: Unless explicitly asked
- **Do not add features**: Unless explicitly asked
- **Do not remove code**: Unless explicitly asked
- **Do not modify .env**: Never modify environment files
- **Do not commit secrets**: Never commit API keys or passwords

## Common Patterns

### Permission Check

```php
if (!frs_can_read($role, 'module_name')) {
    header('Location: ' . base_path() . '/dashboard');
    exit;
}
```

### Database Query

```php
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM table WHERE id = :id');
$stmt->execute(['id' => $id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Form Handling

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST[CSRF_TOKEN_NAME])) {
        $error = 'Invalid security token';
    } else {
        // Process form
    }
}
```

### AJAX Response

```php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $data
]);
```

### Notification Creation

```php
createNotification(
    $userId,
    'booking',
    'Title',
    'Message',
    base_path() . '/dashboard/some-page'
);
```

### Audit Logging

```php
logAudit(
    'Action description',
    'Module',
    'Details',
    $actorId
);
```

## When to Ask for Clarification

Ask the user for clarification when:

1. **Requirements are ambiguous**: The task description is unclear
2. **Multiple solutions exist**: There are multiple valid approaches
3. **Security concerns**: A change might introduce security issues
4. **Breaking changes**: A change might break existing functionality
5. **Missing context**: You don't have enough information to proceed
6. **Complex changes**: The change is large and complex

## Documentation Updates

When making changes, consider updating:

1. **DATABASE.md**: If schema changes are made
2. **API_REFERENCE.md**: If API endpoints are added/modified
3. **ARCHITECTURE.md**: If architectural changes are made
4. **CODE_INDEX.md**: If new files are added
5. **CHANGELOG_BASELINE.md**: After completing a feature or fix

## Specific Module Guidelines

### Authentication

- Never store plain text passwords
- Always use `password_hash()` and `password_verify()`
- Implement rate limiting for login attempts
- Lock accounts after failed attempts
- Require 2FA for Admin/Staff

### Reservations

- Check availability before creating reservation
- Use transactions for reservation creation
- Log all status changes in `reservation_history`
- Send notifications on status changes
- Validate all booking constraints

### Payments

- Never store full payment details
- Use webhook for payment status updates
- Implement idempotency for webhooks
- Log all payment events
- Sync payment status with reservation status

### AI Features

- Provide fallback when AI is unavailable
- Rate limit AI API calls
- Cache AI responses when appropriate
- Validate AI responses before use
- Log AI API errors

### File Uploads

- Validate file type and size
- Generate secure filenames
- Store in appropriate directory
- Implement archival for old files
- Provide download with access control

## Performance Guidelines

### Database

- Use indexes for frequently queried columns
- Avoid N+1 queries
- Use LIMIT when possible
- Consider caching for expensive queries

### PHP

- Avoid deep nesting
- Use early returns
- Minimize database queries
- Use lazy loading when appropriate

### JavaScript

- Use event delegation
- Debounce/throttle expensive operations
- Use requestAnimationFrame for animations
- Avoid memory leaks (remove event listeners)

### CSS

- Minimize selector specificity
- Use CSS transforms for animations
- Avoid expensive properties (box-shadow, filter)
- Use will-change sparingly

## Accessibility Guidelines

- Use semantic HTML
- Provide alt text for images
- Use ARIA labels for interactive elements
- Ensure keyboard navigation works
- Provide sufficient color contrast
- Use focus indicators

## Browser Compatibility

- Target modern browsers (Chrome, Firefox, Safari, Edge)
- Test on mobile devices
- Provide graceful degradation
- Use feature detection, not browser detection

## Final Checklist

Before considering a task complete:

- [ ] Code follows existing patterns
- [ ] Security best practices followed
- [ ] No syntax errors
- [ ] No hardcoded secrets
- [ ] Database queries use prepared statements
- [ ] User input is validated and sanitized
- [ ] Output is properly escaped
- [ ] CSRF tokens included where needed
- [ ] Error handling is appropriate
- [ ] Code is readable and maintainable
- [ ] Documentation updated if needed
