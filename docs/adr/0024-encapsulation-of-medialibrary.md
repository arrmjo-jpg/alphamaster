# ADR 0024: Media Encapsulation behind Internal Contracts

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — implemented; MediaLibrary evaluated and not adopted; `HasMediaAttachments` deferred to its first consumer

## Context

Directly binding domain models to a media vendor's traits creates vendor coupling across all business modules. The original record named `spatie/laravel-medialibrary` as the implementation to isolate, and titled itself accordingly.

Implementing the module showed the isolation principle to be right and the specific vendor to be unnecessary. The package was installed, the module was built, and nothing in it ended up calling the package: its principal value is image conversions, which run through `spatie/image` and require gd or imagick, neither of which exists in the backend container; its `Media` model uses integer keys against this platform's ULID convention (ADR 0004); and the association, collection and disk handling that remained is a thin layer over Laravel's own filesystem abstraction. Six packages were installed for a dependency the code never referenced, and an architecture rule guarding `Spatie\MediaLibrary` passed only because nothing imported it — a rule that appeared to protect the boundary while protecting an empty set.

The package was therefore removed and this record revised. The decision it expresses is unchanged; the vendor it named is not part of it.

## Decision

Isolate media behind `MediaServiceContract` in `Modules/Media`. Business models interact with our internal contracts and see `MediaFile` and nothing else — no storage implementation, no disk, no path. Whatever backs media underneath can be replaced without a single business model changing, which is the property the original record was protecting and the reason it survives the vendor's removal.

Attachment reaches a business model through a project-owned `HasMediaAttachments` trait, introduced with the first model that attaches media. Phase 10 shipped it ahead of any consumer, and it sat unused: no model in `app` and no fixture in `tests` referenced it, so static analysis could not even read it. Keeping it to avoid an empty slot in this record would have been the same mistake this ADR was revised to correct — six MediaLibrary packages were installed for a dependency the code never called. It is three trivial morph-relation methods; the decision is what matters, and the decision is that media reaches a model only through AlphaMaster types.

Storage is `MediaStorageContract` over Laravel's filesystem disks rather than a second configuration system: disks are already a driver abstraction, and the disk each file lives on is recorded per row so a migration between backends can proceed file by file. Delivery is `CdnUrlResolverContract`, configured from the Settings module's `cdn` group, naming no vendor and returning the storage URL unchanged when no CDN is configured. Private media is never routed through a CDN, because a shared cache in front of a signed URL is how private files stop being private.

Malware scanning is `MediaScannerContract`. No antivirus exists in this environment, so the registered driver reports `not_scanned` and deliberately never reports `clean`: a row asserting cleanliness on the strength of a scanner that did not run is a guarantee nobody checked. Metadata extraction is `MediaProcessorContract`, resolved per media type, with only the processors this environment can actually run registered — image dimensions come from the file header without decoding, and thumbnailing and video metadata remain contracts without drivers until the container gains gd and ffprobe.

Access is two visibilities. Media knows whether a file needs authorization; who satisfies it is a business question — owner, team member, judge — that Media cannot anticipate, so it is delegated to a `MediaAccessPolicyContract` the attaching module registers. Private media attached to a type with no registered policy is denied: an unanswered question is not a yes.

Authenticity assessment is `MediaVerifierContract`, defined and unimplemented. There is no consumer and no analyzer is possible without frame extraction, so the contract exists to settle the shape rather than to promise the capability. An implementation returns a risk assessment and never a verdict, because no analyzer can support the claim that a file definitively is or is not machine generated.

Intake is asynchronous on the `media` queue (ADR 0020): validate, store, scan, process, ready. Jobs take an id rather than a model so a retry re-reads current state, and each is idempotent. Deletion is soft; bytes are purged by an explicit retention job, never as a side effect of a row disappearing.

## Consequences

Preserves the isolation the original decision was for, at a lower dependency cost than it anticipated: the boundary exists, and nothing is installed that nothing calls.

Adopting a media vendor later remains open and cheap. `MediaStorageContract` and `MediaProcessorContract` are where one would attach, and no business model would change — which is the same guarantee this record made when it named a vendor, now demonstrated rather than asserted.

The capabilities that need gd, imagick or ffmpeg are genuinely absent rather than stubbed, so a caller can tell what the platform does not do. Adding those extensions to the container image is what unblocks them; the contracts are already in place to receive them.
