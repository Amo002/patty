# Questions

Things the brief does not answer. One file per question.

How to answer: open the file, write your answer under **Answer**, change `Status: open` to `Status: closed`, and tell the orchestrator. It records the decision in [../decisions.md](../decisions.md) and updates the requirements.

Until a question is closed, its **Recommendation** is the working assumption.

| # | Question | Status | Blocks |
|---|---|---|---|
| [Q-001](Q-001-below-zero-sale.md) | What happens when a sale would take stock below zero? | closed | PTY-9 |
| [Q-002](Q-002-over-delivery.md) | Can a delivery exceed what was ordered? | closed | PTY-8 |
| [Q-003](Q-003-meaning-of-received.md) | What does the `received` state mean? | closed | PTY-7, PTY-8 |
| [Q-004](Q-004-short-close.md) | Can an order be closed with quantity still outstanding? | closed | PTY-7, PTY-8 |
| [Q-005](Q-005-pos-idempotency.md) | What if the POS sends the same sale twice? | closed | PTY-9 |
| [Q-006](Q-006-units.md) | How are units handled? | closed | PTY-3 |
| [Q-007](Q-007-editing-rules.md) | What can be edited, and when? | closed | PTY-6, PTY-7 |
| [Q-008](Q-008-no-login-experience.md) | No-login experience | closed | PTY-22 |
| [Q-009](Q-009-pos-endpoint-protection.md) | POS endpoint protection | closed | PTY-9 |
| [Q-010](Q-010-idempotency-conflict.md) | Reference reused with a different payload | closed | PTY-9 |
| [Q-011](Q-011-small-edges.md) | Time, deletes, delivery date, bounds | closed | several |
| [Q-012](Q-012-ui-ticket-split.md) | UI ticket split and PTY-17 scope | **open** (phase 2b) | UI tickets |
| [Q-013](Q-013-exposing-ids.md) | Database ids in URLs and API | closed | PTY-3, PTY-16 |
| [Q-014](Q-014-tolerance-model.md) | Tolerance model (over, under, cap) | closed | PTY-3, PTY-7, PTY-8 |
