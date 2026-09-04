# ADR 0024: Media Encapsulation behind Internal Contracts

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — implemented; MediaLibrary evaluated and not adopted; `HasMediaAttachments` deferred to its first consumer
* **Revised**: 2026-09-04 — named variants and watermarking recorded as target architecture, explicitly not implemented

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

## Extension — 2026-09-04: named variants and watermarking

This section records target architecture. **None of it is implemented**, and the record above is unchanged: `MediaProcessorContract` exists, only processors this environment can run are registered, and image dimensions are read from the file header without decoding. Thumbnailing and video metadata remain contracts without drivers because the container has neither gd, imagick nor ffmpeg — verified again on 2026-09-04 against the built image, whose extension list is `pdo_pgsql, pgsql, pdo_sqlite, redis, pcntl, posix, bcmath, intl, zip, exif, opcache`.

The audit asked that this be stated plainly rather than implied, so: the pipeline is built, the processors are not.

### Variants are named, not dimensioned

A derivative is requested by name, never by pixel size. A caller asks for `thumbnail`; it does not ask for 320×320.

```
original   the uploaded bytes, never modified
thumbnail  small, for lists and pickers
medium     inline display
large      full-width display, produced only where a project needs it
social     social sharing
og         Open Graph, consumed by ADR 0032
```

Dimensions, fit, crop behaviour, output format and quality belong to configuration, so a project tunes them without a code change and two projects on this foundation can differ. Names are the contract; numbers are settings. A consumer that hard-codes a size is coupling to a value the platform is entitled to change.

A variant that has not been produced resolves to the original rather than to a broken URL. An unoptimised image that loads is better than a correct-looking URL that does not.

### The original is never modified

Whatever arrives is what is stored. Every derivative is a separate stored object recorded against the media row, and regenerating variants — after a configuration change, or when a new variant is introduced — reads the original and writes new derivatives. Nothing overwrites it.

This is what makes variant configuration safe to change: if the original were processed in place, a quality setting would be a destructive migration.

### Watermarking applies to derivatives only

Configuration, held as settings (ADR 0018):

```
watermark.enabled     watermark.media_id    watermark.position
watermark.opacity     watermark.scale       watermark.margin_x / margin_y
watermark.apply_to    the named variants it applies to
```

`watermark.media_id` references a `MediaFile` like every other branding asset, so the watermark is managed through the same upload, scanning and storage path as any other image.

**The original is never watermarked.** It is the archival copy and the input to every future regeneration; a watermark burned into it could not be removed, and changing the watermark later would be impossible for every file already uploaded. Watermarking is therefore a step in derivative generation, and `apply_to` names which derivatives receive it — a thumbnail in an administrative list rarely should.

### Implementation status

| Element | State |
| :--- | :--- |
| Storage, delivery, CDN resolution, signed URLs | Implemented, Phase 10 |
| Scanning contract, honest `not_scanned` reporting | Implemented, Phase 10 |
| Header-only image dimension reading | Implemented, Phase 10 |
| Named variants, generation, regeneration | **Deferred** — requires gd or imagick |
| Watermarking | **Deferred** — requires the above |
| Video metadata | **Deferred** — requires ffprobe |
| `HasMediaAttachments` | **Deferred** to its first consumer |

Tracked in ADR 0029.

## Consequences

Preserves the isolation the original decision was for, at a lower dependency cost than it anticipated: the boundary exists, and nothing is installed that nothing calls.

Adopting a media vendor later remains open and cheap. `MediaStorageContract` and `MediaProcessorContract` are where one would attach, and no business model would change — which is the same guarantee this record made when it named a vendor, now demonstrated rather than asserted.

The capabilities that need gd, imagick or ffmpeg are genuinely absent rather than stubbed, so a caller can tell what the platform does not do. Adding those extensions to the container image is what unblocks them; the contracts are already in place to receive them.
