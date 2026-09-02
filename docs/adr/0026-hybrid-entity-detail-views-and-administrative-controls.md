# ADR 0026: Hybrid Entity Detail Views and Administrative Controls

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Administrative workflows require balancing quick context-preserving edits with deep multi-tab management, lightweight real-time awareness, and dynamic custom field control.

## Decision

Adopt three ratified architectural choices: (1) Hybrid Detail Views (D08): Slide-Over Drawer for quick previews/edits plus Full-Page navigation for complex records; (2) Real-Time Strategy (D09): 30-second Polling baseline for Foundation notifications, with architecture ready for Laravel Reverb; (3) Custom Field Administration (D10): Hybrid management allowing developers to seed initial fields while granting Admins full CRUD control from the React Admin UI.

## Consequences

Maximizes usability and developer productivity while keeping infrastructure requirements lean.
