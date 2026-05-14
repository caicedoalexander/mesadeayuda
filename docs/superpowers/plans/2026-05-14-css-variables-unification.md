# CSS Variables Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify all CSS color values under a centralized variable system in `styles.css`, eliminating hardcoded values and duplications.

**Architecture:** Expand `:root` variables in `styles.css` to cover all colors used across the project. Migrate each CSS file incrementally, replacing hardcoded hex values with `var()` references. Each file is one commit for easy rollback.

**Tech Stack:** CSS3 custom properties (variables), no build tools required.

---

## File Structure

**Modify:**
- `webroot/css/styles.css` — expand `:root` variables, replace internal hardcoded values
- `webroot/css/bulk-actions.css` — replace hardcoded colors
- `webroot/css/tickets-view.css` — replace Bootstrap grays and semantic colors
- `webroot/css/login.css` — convert RGBA to use RGB variables
- `webroot/css/badges.css` — replace semantic colors
- `webroot/css/admin/edit-user.css` — remove duplicate variable definitions
- `webroot/css/admin/preview-template.css` — remove duplicate variable definitions
- `webroot/css/admin/tags.css` — replace `#dc3545`
- `webroot/css/admin/users.css` — replace `#dc3545`
- `webroot/css/admin/add-tag.css` — replace `#0066cc`

---

### Task 1: Expand variables in styles.css :root

**Files:**
- Modify: `webroot/css/styles.css:12-40`

- [ ] **Step 1: Add new variables to :root**

In `webroot/css/styles.css`, expand the `:root` block. After line 23 (`--gray-900: #111827;`), add:

```css
    --gray-500: #6B7280;
    --gray-800: #1F2937;
```

After line 29 (`--transition: ...`), add before `/* App surface */`:

```css
    /* Brand color variants */
    --admin-green-light: #00D477;
    --admin-green-hover: #008f50;
    --admin-green-rgb: 0, 168, 94;
    --admin-orange-light: #F07D2D;
    --admin-orange-rgb: 205, 106, 21;
```

After line 40 (`--info-color: #0dcaf0;`), add:

```css
    /* Surfaces (Bootstrap grays) */
    --surface-light: #f8f9fa;
    --surface-hover: #e9ecef;
    --border-color: #dee2e6;
    --border-hover: #adb5bd;
    --text-muted: #6c757d;
```

- [ ] **Step 2: Verify variables are valid CSS**

Run: Open browser dev tools on any page, inspect `:root`, confirm new variables appear.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/styles.css
git commit -m "feat(css): expand :root variables with grays, surfaces, and color variants"
```

---

### Task 2: Replace hardcoded values in styles.css

**Files:**
- Modify: `webroot/css/styles.css:81-498`

- [ ] **Step 1: Replace #00A85E with var(--admin-green)**

Replace these occurrences (lines 81, 87, 95, 262, 269, 305, 365, 409, 469):

| Line | Before | After |
|------|--------|-------|
| 81 | `color: #00A85E;` | `color: var(--admin-green);` |
| 87 | `outline: 1px solid #00A85E;` | `outline: 1px solid var(--admin-green);` |
| 95 | `background-color: #00A85E;` | `background-color: var(--admin-green);` |
| 262 | `border-color: #00A85E !important;` | `border-color: var(--admin-green) !important;` |
| 269 | `border-color: #00A85E !important;` | `border-color: var(--admin-green) !important;` |
| 305 | `border-color: #00A85E transparent transparent transparent;` | `border-color: var(--admin-green) transparent transparent transparent;` |
| 365 | `background-color: #00A85E !important;` | `background-color: var(--admin-green) !important;` |
| 409 | `background-color: #00A85E !important;` | `background-color: var(--admin-green) !important;` |
| 469 | `border-color: #00A85E;` | `border-color: var(--admin-green);` |

- [ ] **Step 2: Replace #008f50 with var(--admin-green-hover)**

| Line | Before | After |
|------|--------|-------|
| 371 | `background-color: #008f50 !important;` | `background-color: var(--admin-green-hover) !important;` |
| 424 | `background-color: #008f50 !important;` | `background-color: var(--admin-green-hover) !important;` |

- [ ] **Step 3: Replace #CD6A15 with var(--admin-orange)**

| Line | Before | After |
|------|--------|-------|
| 113 | `background-color: #CD6A15 !important;` | `background-color: var(--admin-orange) !important;` |
| 117 | `outline: 1px solid #CD6A15;` | `outline: 1px solid var(--admin-orange);` |
| 498 | `color: #CD6A15;` | `color: var(--admin-orange);` |

- [ ] **Step 4: Verify no hardcoded brand colors remain**

Run:
```bash
grep -n "#00A85E\|#CD6A15\|#008f50" webroot/css/styles.css
```

Expected: Only lines 13, 14 (the variable definitions in :root).

- [ ] **Step 5: Commit**

```bash
git add webroot/css/styles.css
git commit -m "refactor(css): replace hardcoded brand colors with variables in styles.css"
```

---

### Task 3: Migrate bulk-actions.css

**Files:**
- Modify: `webroot/css/bulk-actions.css`

- [ ] **Step 1: Replace #00A85E**

| Line | Before | After |
|------|--------|-------|
| 63 | `border-color: #00A85E !important;` | `border-color: var(--admin-green) !important;` |
| 64 | `border-left: 1px solid #00A85E !important;` | `border-left: 1px solid var(--admin-green) !important;` |

- [ ] **Step 2: Replace Bootstrap grays**

| Line | Before | After |
|------|--------|-------|
| 47 | `color: #6c757d;` | `color: var(--text-muted);` |
| 68 | `color: #adb5bd;` | `color: var(--border-hover);` |
| 154 | `background-color: #F7F8FA !important;` | `background-color: var(--surface-light) !important;` |
| 184 | `background-color: #f8f9fa;` | `background-color: var(--surface-light);` |
| 251 | `background-color: #f8f9fa;` | `background-color: var(--surface-light);` |
| 271 | `color: #495057;` | `color: var(--gray-700);` |

- [ ] **Step 3: Replace border colors**

| Line | Before | After |
|------|--------|-------|
| 250 | `border-top: 1px solid #e9ecef;` | `border-top: 1px solid var(--surface-hover);` |
| 279 | `border: 1px solid #dee2e6;` | `border: 1px solid var(--border-color);` |
| 294 | `border-color: #feb2b2;` | `border-color: #feb2b2;` | (keep - this is a custom alert red, not in our system)

- [ ] **Step 4: Verify**

Run:
```bash
grep -n "#00A85E\|#f8f9fa\|#6c757d\|#dee2e6" webroot/css/bulk-actions.css
```

Expected: No matches.

- [ ] **Step 5: Commit**

```bash
git add webroot/css/bulk-actions.css
git commit -m "refactor(css): replace hardcoded colors with variables in bulk-actions.css"
```

---

### Task 4: Migrate tickets-view.css

**Files:**
- Modify: `webroot/css/tickets-view.css`

- [ ] **Step 1: Replace surface colors**

| Line | Before | After |
|------|--------|-------|
| 23 | `background: #f8f9fa;` | `background: var(--surface-light);` |
| 29 | `background: #e9ecef;` | `background: var(--surface-hover);` |
| 72 | `background: #f8f9fa;` | `background: var(--surface-light);` |
| 79 | `background: #e9ecef;` | `background: var(--surface-hover);` |
| 149 | `background-color: #f8f9fa;` | `background-color: var(--surface-light);` |
| 158 | `background-color: #f8f9fa;` | `background-color: var(--surface-light);` |
| 170 | `background-color: white;` | `background-color: white;` | (keep - explicit white)
| 177 | `background-color: #f8f9fa;` | `background-color: var(--surface-light);` |

- [ ] **Step 2: Replace border colors**

| Line | Before | After |
|------|--------|-------|
| 57 | `background: #dee2e6;` | `background: var(--border-color);` |
| 74 | `border: 1px solid #dee2e6;` | `border: 1px solid var(--border-color);` |
| 81 | `border-color: #adb5bd;` | `border-color: var(--border-hover);` |
| 171 | `border-color: #dee2e6;` | `border-color: var(--border-color);` |
| 178 | `border-color: #adb5bd;` | `border-color: var(--border-hover);` |

- [ ] **Step 3: Replace text colors**

| Line | Before | After |
|------|--------|-------|
| 24 | `color: #495057;` | `color: var(--gray-700);` |
| 35 | `color: #555;` | `color: var(--gray-700);` |
| 36 | `border-bottom: 2px solid #555 !important;` | `border-bottom: 2px solid var(--gray-700) !important;` |
| 99 | `color: #212529;` | `color: var(--gray-900);` |
| 108 | `color: #6c757d;` | `color: var(--text-muted);` |
| 188 | `color: #6c757d;` | `color: var(--text-muted);` |

- [ ] **Step 4: Replace danger color**

| Line | Before | After |
|------|--------|-------|
| 115 | `color: #dc3545;` | `color: var(--danger-color);` |
| 125 | `background: #dc3545;` | `background: var(--danger-color);` |

- [ ] **Step 5: Verify**

Run:
```bash
grep -n "#f8f9fa\|#e9ecef\|#dee2e6\|#6c757d\|#dc3545\|#495057\|#212529" webroot/css/tickets-view.css
```

Expected: No matches.

- [ ] **Step 6: Commit**

```bash
git add webroot/css/tickets-view.css
git commit -m "refactor(css): replace hardcoded colors with variables in tickets-view.css"
```

---

### Task 5: Migrate login.css

**Files:**
- Modify: `webroot/css/login.css`

- [ ] **Step 1: Replace RGBA values with RGB variables**

| Line | Before | After |
|------|--------|-------|
| 16 | `background: radial-gradient(circle at 30% 30%, rgba(205, 106, 21, 1), rgba(205, 106, 21, 0.5));` | `background: radial-gradient(circle at 30% 30%, rgba(var(--admin-orange-rgb), 1), rgba(var(--admin-orange-rgb), 0.5));` |
| 20 | `background: radial-gradient(circle at 30% 30%, rgba(0, 168, 94, 1), rgba(0, 168, 94, 0.5));` | `background: radial-gradient(circle at 30% 30%, rgba(var(--admin-green-rgb), 1), rgba(var(--admin-green-rgb), 0.5));` |

Note: Line 24 (brown circle `rgba(143, 87, 54, ...)`) is a unique color not in our system - leave as is.

- [ ] **Step 2: Verify**

Run:
```bash
grep -n "rgba(205, 106, 21\|rgba(0, 168, 94" webroot/css/login.css
```

Expected: No matches.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/login.css
git commit -m "refactor(css): use RGB variables for gradients in login.css"
```

---

### Task 6: Migrate badges.css

**Files:**
- Modify: `webroot/css/badges.css`

- [ ] **Step 1: Replace semantic colors**

| Line | Before | After |
|------|--------|-------|
| 14 | `background-color: #ffc107;` | `background-color: var(--warning-color);` |
| 15 | `background-color: #dc3545;` | `background-color: var(--danger-color);` |
| 16 | `background-color: #0d6efd;` | `background-color: var(--primary-color);` |
| 17 | `background-color: #198754;` | `background-color: var(--success-color);` |
| 19 | `background-color: #6c757d;` | `background-color: var(--text-muted);` |
| 20 | `background-color: #0dcaf0;` | `background-color: var(--info-color);` |
| 21 | `background-color: #ffc107;` | `background-color: var(--warning-color);` |
| 22 | `background-color: #dc3545;` | `background-color: var(--danger-color);` |

- [ ] **Step 2: Verify**

Run:
```bash
grep -n "#ffc107\|#dc3545\|#0d6efd\|#198754\|#6c757d\|#0dcaf0" webroot/css/badges.css
```

Expected: No matches.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/badges.css
git commit -m "refactor(css): use semantic color variables in badges.css"
```

---

### Task 7: Remove duplicate variables from admin/edit-user.css

**Files:**
- Modify: `webroot/css/admin/edit-user.css:9-47`

- [ ] **Step 1: Delete the local variable block**

Remove lines 9-47 (the entire `.edit-user-container { --edit-user-green: ... }` variable block).

Keep only the structural CSS starting at line 49 (`.edit-user-container { ... }` with actual styles).

- [ ] **Step 2: Update variable references**

Replace throughout the file:

| Before | After |
|--------|-------|
| `var(--edit-user-green)` | `var(--admin-green)` |
| `var(--edit-user-orange)` | `var(--admin-orange)` |
| `var(--gradient-primary)` | `linear-gradient(135deg, var(--admin-green) 0%, var(--admin-green-light) 100%)` |
| `var(--gradient-accent)` | `linear-gradient(135deg, var(--admin-orange) 0%, var(--admin-orange-light) 100%)` |
| `var(--gradient-celebrate)` | `linear-gradient(135deg, var(--admin-green) 0%, var(--admin-orange) 100%)` |

- [ ] **Step 3: Verify no local variables remain**

Run:
```bash
grep -n "\-\-edit-user\|\-\-gradient-primary\|\-\-gradient-accent\|\-\-gradient-celebrate" webroot/css/admin/edit-user.css
```

Expected: No matches.

- [ ] **Step 4: Commit**

```bash
git add webroot/css/admin/edit-user.css
git commit -m "refactor(css): remove duplicate variables from edit-user.css, use global"
```

---

### Task 8: Remove duplicate variables from admin/preview-template.css

**Files:**
- Modify: `webroot/css/admin/preview-template.css:1-12`

- [ ] **Step 1: Delete the local :root block**

Remove lines 1-12 (the entire `:root { --admin-green: ... }` block).

- [ ] **Step 2: Verify file still works**

The remaining CSS already uses `var(--admin-green)`, `var(--gray-900)`, etc. which will now inherit from the global `styles.css`.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/admin/preview-template.css
git commit -m "refactor(css): remove duplicate :root variables from preview-template.css"
```

---

### Task 9: Migrate admin/tags.css

**Files:**
- Modify: `webroot/css/admin/tags.css`

- [ ] **Step 1: Replace #dc3545**

| Line | Before | After |
|------|--------|-------|
| 192 | `color: #dc3545;` | `color: var(--danger-color);` |
| 193 | `border: 1.5px solid #dc3545;` | `border: 1.5px solid var(--danger-color);` |
| 197 | `background: #dc3545;` | `background: var(--danger-color);` |

- [ ] **Step 2: Verify**

Run:
```bash
grep -n "#dc3545" webroot/css/admin/tags.css
```

Expected: No matches.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/admin/tags.css
git commit -m "refactor(css): use danger-color variable in tags.css"
```

---

### Task 10: Migrate admin/users.css

**Files:**
- Modify: `webroot/css/admin/users.css`

- [ ] **Step 1: Replace #dc3545**

| Line | Before | After |
|------|--------|-------|
| 254 | `color: #dc3545;` | `color: var(--danger-color);` |
| 255 | `border-color: #dc3545;` | `border-color: var(--danger-color);` |
| 259 | `background: #dc3545;` | `background: var(--danger-color);` |

- [ ] **Step 2: Verify**

Run:
```bash
grep -n "#dc3545" webroot/css/admin/users.css
```

Expected: No matches.

- [ ] **Step 3: Commit**

```bash
git add webroot/css/admin/users.css
git commit -m "refactor(css): use danger-color variable in users.css"
```

---

### Task 11: Migrate admin/add-tag.css

**Files:**
- Modify: `webroot/css/admin/add-tag.css`

- [ ] **Step 1: Replace #0066cc**

| Line | Before | After |
|------|--------|-------|
| 221 | `background-color: #0066cc;` | `background-color: var(--admin-blue);` |

- [ ] **Step 2: Replace --gradient-celebrate with inline gradient**

Lines 63 and 246 reference `var(--gradient-celebrate)` which was defined in edit-user.css. Replace with inline gradient:

| Line | Before | After |
|------|--------|-------|
| 63 | `background: var(--gradient-celebrate);` | `background: linear-gradient(135deg, var(--admin-green) 0%, var(--admin-orange) 100%);` |
| 246 | `background: var(--gradient-celebrate);` | `background: linear-gradient(135deg, var(--admin-green) 0%, var(--admin-orange) 100%);` |

- [ ] **Step 3: Verify**

Run:
```bash
grep -n "#0066cc\|--gradient-celebrate" webroot/css/admin/add-tag.css
```

Expected: No matches for `#0066cc`. The gradient references should now be inline.

- [ ] **Step 4: Commit**

```bash
git add webroot/css/admin/add-tag.css
git commit -m "refactor(css): use admin-blue variable and inline gradients in add-tag.css"
```

---

### Task 12: Final verification

**Files:**
- All CSS files in `webroot/css/`

- [ ] **Step 1: Verify no hardcoded brand colors remain**

Run:
```bash
grep -rn "#00A85E\|#CD6A15\|#008f50" webroot/css/ --include="*.css" | grep -v "styles.css"
```

Expected: No matches (only styles.css should have them in :root definitions).

- [ ] **Step 2: Verify no duplicate :root definitions**

Run:
```bash
grep -rn "^:root\|^\s*:root" webroot/css/ --include="*.css"
```

Expected: Only `webroot/css/styles.css` should have a `:root` block.

- [ ] **Step 3: Visual verification**

Open in browser and check these pages for visual regressions:
- `/login` — circles should have correct green/orange colors
- `/tickets` — list should display correctly
- `/tickets/view/1` — ticket view should look correct
- `/admin/users` — table and badges should look correct
- `/admin/tags` — cards should display correctly

- [ ] **Step 4: Commit verification results**

If all checks pass, no additional commit needed. If fixes were required, commit them.

```bash
git log --oneline -12
```

Expected: 11 commits for this refactoring (Tasks 1-11).
