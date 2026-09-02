# ADR 0007: Shadcn/UI and Tailwind CSS for Admin Platform

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

The admin UI requires enterprise accessibility, high customization, themeability (dark/light mode), and first-class RTL/LTR directional support.

## Decision

Standardize on Shadcn/UI (Radix UI primitives) and Tailwind CSS v4. Components reside inside the admin codebase, granting complete control over styling, behavior, and accessibility.

## Consequences

Avoids vendor lock-in of monolithic component libraries (such as Ant Design) while maintaining full accessibility and design token flexibility.
