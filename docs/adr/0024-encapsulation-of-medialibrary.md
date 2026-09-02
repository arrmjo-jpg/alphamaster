# ADR 0024: Encapsulation of MediaLibrary behind Internal Contracts

* **Status**: Accepted
* **Date**: 2026-09-03

## Context

Directly binding domain models to spatie/laravel-medialibrary traits creates vendor coupling across all business modules.

## Decision

Isolate MediaLibrary behind MediaServiceContract and a project-owned HasMediaAttachments trait in Modules/Media. Business models interact with our internal contracts.

## Consequences

Preserves the battle-tested power of MediaLibrary while isolating third-party code from core business domains.
