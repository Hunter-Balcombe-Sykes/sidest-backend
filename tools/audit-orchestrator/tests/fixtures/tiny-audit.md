# Tiny Audit — Test Fixture

**Stage 0: synthetic test fixture**

Source: synthetic. For parser tests only.

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## Suggested Bundled Sessions

### High-impact bundles (P0/P1 mixed)

- **B-T1 — Test bundle one.** #T-001, #T-002. ~1–2h. Two sibling tests of the parser. Same file, same fix shape. **Don't pull in:** #T-003 (different domain).

### Mechanical / low-risk bundles (P2/P3)

- **B-T2 — Test bundle two.** #T-003. ~0.5h. One-item bundle for edge case coverage.

### Standalone — do NOT bundle

- **XL refactors:** #T-004 (synthetic XL test).
- **Architectural decisions:** #T-005 (synthetic arch test).
- **High-value standalones:** #T-006 (synthetic high-value).

### Dependencies between bundles / items

- **#T-002 follows #T-001** — sequence test.

---

## P0 — Test tier P0

- [ ] **#T-001** · P0 — First test item title
    - **Where:** path/to/file.py:10-20
    - **Affects:** Parser test fixtures only.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Do the thing.
    - **Technical:** Engineering rationale here.
    - **Plain English:** Plain rationale here.
    - **Evidence:**
        ```python
        # snippet
        ```

## P1 — Test tier P1

- [ ] **#T-002** · P1 — Second test item title
    - **Where:** path/to/file.py:30-40
    - **Affects:** Sibling of #T-001.
    - **Effort:** S (~1h)
    - **What to do:**
        - Do the other thing.

- [x] **#T-003** · P1 — Already-completed test item
    - **Where:** path/to/done.py
    - **Affects:** Tests the done-state parser.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Already done.

## P2 — Test tier P2 (empty section, valid)

(none)

## P3 — Test tier P3

- [ ] **#T-004** · P3 — Test XL item (should be classified skip)
    - **Where:** vast/swath/of/codebase.py
    - **Affects:** Many things.
    - **Effort:** XL (~16h+)
    - **What to do:**
        - Big refactor.

- [ ] **#T-005** · P3 — Test architectural item
    - **Where:** app/Services/CoreService.php
    - **Affects:** Architecture.
    - **Effort:** L (~8h)
    - **What to do:**
        - Architectural decision.

- [ ] **#T-006** · P3 — Test high-value standalone
    - **Where:** app/Webhooks/Stripe.php
    - **Affects:** Money path.
    - **Effort:** M (~4h)
    - **What to do:**
        - Stripe webhook fix.
