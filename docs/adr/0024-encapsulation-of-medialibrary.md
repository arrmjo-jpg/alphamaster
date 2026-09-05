# ADR 0024: Media Encapsulation behind Internal Contracts

* **Status**: Accepted
* **Date**: 2026-09-03
* **Revised**: 2026-09-04 — implemented; MediaLibrary evaluated and not adopted; `HasMediaAttachments` deferred to its first consumer
* **Revised**: 2026-09-04 — named variants and watermarking recorded as target architecture, explicitly not implemented
* **Revised**: 2026-09-05 — variant-set ownership, missing-variant behaviour, system assets, and the closed name vocabulary

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

A variant that has not been produced resolves to the original rather than to a broken URL. An unoptimised image that loads is better than a correct-looking URL that does not. That sentence was written before variant sets were owned by anything, and it reads as one case where there are now two; *Requesting a variant an asset does not have*, below, states the rule precisely and supersedes it.

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

### Who decides which variants an asset has

Media must not know what a logo is. It cannot be allowed to learn that `favicon` means one thing and a news image another, because the moment it does, the module has a domain and stops being foundation (ADR 0033).

The seam already exists in this module and is reused rather than invented. `MediaAccessPolicyContract` handles the same shape of problem: Media knows *that* a private file needs authorization and cannot know *who* satisfies it, so the attaching module registers a policy and Media refuses when none answers. Variants are the same question about a different property — Media knows *that* a file may have derivatives and cannot know *which ones*.

**A variant policy is registered by the module that owns the asset, and Media asks it.** The policy declares what it claims and answers with a set of variant names. Media holds no mapping from a collection or a model to a set of variants, and no list of names beyond the ones it can produce.

**The vocabulary of names is closed, and Media owns it.** A policy chooses from the variants Media supports; it cannot introduce one. Media holds the set of names it knows how to derive, a policy's answer is a subset of that set, and a name Media does not support is not a variant at all — asking for one is the caller error ADR 0031 answers with a validation failure rather than a fallback.

Two different questions are being kept apart. Which derivatives exist as a concept is a property of the platform's imaging capability and is settled here. Which of them a particular asset should have is a property of what that asset is for, and only the owning module can answer it. So Media knows the identifiers its own contract supports and nothing about what they mean to a consumer: it cannot tell that `og` is read by social crawlers or that `thumbnail` fills a picker, and it does not need to.

Adding a name to the vocabulary is therefore a change to this foundation capability, made on foundation terms and recorded as one — not something an owning module does to accommodate itself. That is the line ADR 0033 draws: a project extends the platform by consuming its contracts, not by widening them to fit a requirement of its own.

The claim is expressed over **the attachable type and the collection together**, not the attachable type alone. Branding assets have no attachable at all: ADR 0018 makes a setting's value point at a `MediaFile`, not the file point back at a setting, so `attachable_type` is null for every logo and favicon on the platform. `collection` is the one signal present on every row — it defaults to `default` and is already constrained to a lowercase identifier — which makes the pair the only key that covers both an attached content image and an unattached system asset.

**Where no policy claims an asset, its set is the original alone.** Nothing is derived. That is the conservative default in both directions: it never spends work deriving six sizes of an icon, and it never guesses a set on behalf of a module that has not said what it wants. A missing policy is visible through the resolution rule below rather than through silently absent files.

### Requesting a variant an asset does not have

Two situations look identical to a caller and are not:

* the variant **is** in the asset's set but has not been produced yet — queued, still processing, or failed;
* the variant **is not** in the asset's set and never will be — a `thumbnail` of a favicon.

One rule covers both. **The original is served, and the response states which variant was actually served.**

Serving the original keeps every page working: a correct URL to an unoptimised image is better than a broken one, which is what ADR 0032 already relies on when an `og` variant does not exist. Naming what was served is what keeps that from being a silent substitution — a list that believes it is rendering thumbnails and is in fact rendering full-resolution originals is a performance defect that looks like success, and this project does not accept a result that cannot be distinguished from the thing it is standing in for.

A `404` was considered and rejected: it turns a missing derivative into a broken page, and the most common cause of a missing derivative is a queue that has not caught up yet.

### System assets are original-only, and Media does not know why

Logos, light and dark variants, favicons, application and touch icons, default and social images, and the watermark image are stored as media and referenced by settings (ADR 0018). None of them has a derived set.

This needs no rule of its own. **It is what the default above already produces**: the branding collection registers no variant policy, so its assets have the original and nothing else. Media is not told that these are system assets, and contains no branch that treats them differently — which is the property that matters, because a rule saying *do not thumbnail a favicon* would require Media to recognise a favicon.

Should a project ever need a derived logo, the module that owns branding registers a policy for that collection, and nothing in Media changes.

Content images are the same mechanism with a different answer. A module owning article or page images registers a policy declaring the set it needs — typically the original with a thumbnail, a medium rendering, and social and Open Graph sizes. That list is the owning module's decision and appears nowhere in this module.
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
| Variant policy seam and its default | Decided 2026-09-05, **not implemented** |
| Variant resolution and reporting of what was served | Decided 2026-09-05, **not implemented** |

Tracked in ADR 0029.

## Consequences

Preserves the isolation the original decision was for, at a lower dependency cost than it anticipated: the boundary exists, and nothing is installed that nothing calls.

Adopting a media vendor later remains open and cheap. `MediaStorageContract` and `MediaProcessorContract` are where one would attach, and no business model would change — which is the same guarantee this record made when it named a vendor, now demonstrated rather than asserted.

The capabilities that need gd, imagick or ffmpeg are genuinely absent rather than stubbed, so a caller can tell what the platform does not do. Adding those extensions to the container image is what unblocks them; the contracts are already in place to receive them.
