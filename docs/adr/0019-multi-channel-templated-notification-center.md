# ADR 0019: Multi-Channel Templated Notification Center

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Notifications must dispatch across Database, Email, SMS, and WhatsApp with translatable templates and user preference controls.

## Decision

Extend Laravel Notifications with notification_templates (translatable subjects/bodies) and notification_preferences. Notification jobs are processed asynchronously on dedicated Redis queues.

## Consequences

Separates notification template management from code and respects user communication channels.
