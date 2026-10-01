# Q-014 How should delivery tolerances work?

Status: closed (2026-10-01)

## Context

Q-002 set a 5% over-delivery tolerance. Mohamad asked for it to work like SAP and Microsoft Dynamics: SAP has over- and under-delivery percentages; Dynamics also uses absolute limits ("max 2 kg").

## Options

**A.** Over, under and an absolute cap, snapshotted onto PO lines.

**B.** Over plus a cap only.

**C.** Over and under, no cap.

## Answer

**A.** Defaults: over 5%, under 5%, cap 2,000 g and 2,000 ml, no cap for pieces. Overridable per ingredient. Recorded as D-035.
