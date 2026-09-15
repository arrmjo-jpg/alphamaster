# ADR 0054: Media Analysis — AI Video Detection as a Callable Capability

* **Status**: Accepted
* **Date**: 2026-09-14
* **Replaces**: `MediaVerifierContract`, `VerificationAssessment` and `VerificationStatus` in ADR 0024 (defined, never bound, persisted or called)
* **Extends**: ADR 0017 (vendor drivers), ADR 0018 (settings), ADR 0020/0025 (queues), ADR 0037 (audit), ADR 0044 (AI as integration capabilities), ADR 0052 (permissions)

## Context

Future domains will need to ask whether uploaded video carries signs of AI generation, deepfakes or manipulation. A news module might ask when an article's video is submitted, a competition when an entry arrives, and a public tool when a visitor checks a clip. Other uses might never ask.

AlphaMaster had a contract for this in ADR 0024 and nothing behind it. It was also the wrong shape for the job:
* It was synchronous (`assess(MediaFile)`), and video analysis is not.
* It lived in Media, so modules forbidden from depending on Media could not call it.
* It combined the consumer's side with the vendor's.
* It had three fixed scores, with no requested types, no model or policy version, no fingerprint and no persistence.
* Its states could not express `inconclusive` or `unsupported`.

The platform already has a working precedent. `TextGeneratorContract` is declared in Core, bound by Integration, and called by Localization, a module that may not depend on Integration.

## Decision

**AI video detection is a reusable Core capability, callable explicitly by any consumer or use case. It is never applied to uploaded media on its own.**

In summary:

1. AI video detection is a reusable Core capability (`MediaAnalysisContract`).
2. A consumer — a module, form or endpoint — invokes it explicitly, for the media and types it chooses.
3. It is not applied automatically to uploaded media; nothing in intake calls it.
4. `media_analysis.enabled` is global availability, a kill switch, and nothing more.
5. Where and when analysis is used is each consumer's decision, never a setting's.
6. Duration limits are dynamic settings, `min_video_duration_seconds` and `max_video_duration_seconds` (§11).
7. They apply only to analysis requests.
8. Uploading media is never refused over them.
9. ffmpeg/ffprobe are part of the media infrastructure: the duration is read once, during intake, and analysis reads it from there (§11).
10. Analysis is asynchronous, on its own queue (§9).
11. Results are append-only and versioned by provider, model and policy; a re-analysis supersedes and never rewrites (§4, §5).
12. Results are advisory assessments of risk, not a truth about the media: there is no boolean verdict, and failed, inconclusive or unsupported never reads as authentic.

### 1. Three separate decisions, owned by three different parties

| Level | Owner | Mechanism | Answers |
| :--- | :--- | :--- | :--- |
| **Global availability** | The operator | Setting `media_analysis.enabled`, plus an active, fully configured `media_analysis` provider | Is the capability usable at all? |
| **Consumer-level usage** | Each module, form or endpoint | Its own code, and its own setting if it needs one (registered per ADR 0052, never in Core) | Does *this* place use it, for which media, for which types, and what happens after? |
| **UI feature placement** | Whatever the project builds | A screen that calls a consumer's endpoint | Where is it shown? |

**Switching the capability on does not analyse anything.** It makes analysis available to consumers that ask.

Nothing requests an analysis on a consumer's behalf:
* no middleware;
* no upload listener;
* no step in `MediaService::store()`;
* no step in the intake pipeline.

Core never reads a consumer's setting, and a consumer never writes Core's.

### 2. Two contracts in Core

* **`MediaAnalysisContract`** is what every consumer calls:

  | Method | Returns |
  | :--- | :--- |
  | `availability()` | Whether the capability is on and configured, why not, and which types it supports |
  | `request()` | An immediate ticket |
  | `find()` / `latest()` / `history()` | Recorded results |
  | `cancel()` | Whether a pending analysis was withdrawn |

  It never throws for the platform's state:
  * disabled, not configured, media not found or not ready;
  * unsupported media or types;
  * a limit reached.

  Each of those is an outcome on the ticket. A malformed request (an unknown type, a malformed consumer name) is a programming error and throws.
* **`MediaAnalyzerContract`** is the vendor side. Media calls it and Integration binds it. It needs its own seam because Media may not depend on Integration. Core's `NullMediaAnalyzer` answers "not configured" when nothing else is bound.

The consumer names itself with a dotted identifier such as `news.article_video`. The name is recorded on the analysis and carried by the finished event. It is a string, never a class, so Core does not learn which modules exist.

### 3. A closed vocabulary

The types are:
* `ai_generated`
* `deepfake`
* `face_manipulation`
* `visual_manipulation`
* `synthetic_audio`

Core owns this list. A consumer chooses from it and cannot invent a type. An analyzer declares the subset it supports. Provenance (C2PA) is not a type here: it is evidence rather than inference, and it is future work.

### 4. Media carries out the lifecycle

```
consumer → request() → checks (enabled, configured, media servable, accepted file,
           supported types, size/duration/daily limits) → fingerprint → reuse or record
         → pending row → dispatch after commit → queue `media-analysis`
         → job claims row (pending → processing) → MediaAnalyzerContract::analyze()
         → completed | inconclusive | unsupported | failed | cancelled (or pending again to retry)
         → MediaAnalysisFinished event after commit → consumer decides
```

* **Deduplication.** The fingerprint covers:
  * the file's checksum;
  * the requested types;
  * the analyzer, its model and the policy version.

  An equivalent request returns the existing analysis (`reused`) instead of paying for it twice. A new model version or policy is a new analysis. A consumer that means to run it again says `reanalyze`.
* **Checked again when the job runs.** If the capability was switched off or the media removed while waiting, the analysis ends `cancelled` without calling the analyzer. Neither is an analyzer failure.
* **Retries.** A retryable failure (a rate limit or an outage) returns the row to `pending` after the vendor's `Retry-After` or a doubling backoff, for up to three attempts, then `failed`. The row is the state and the claim is atomic, so a retry and the sweep cannot both send one file.
* **Recovery.** `SweepMediaAnalyses` recovers rows a dead worker left `processing` and rows whose dispatch was lost.
* **The file reaches the analyzer only when it asks.** It gets a stream or a short-lived URL from the input at run time. The storage path and disk are never exposed.

### 5. The result is an assessment, never a verdict

There is no `is_ai` field and no boolean of that kind anywhere.

| Field | Meaning |
| :--- | :--- |
| `status` | `pending`, `processing`, `completed`, `inconclusive`, `unsupported`, `failed`, `cancelled`. Only `completed` and `inconclusive` are assessments. **`failed`, `unsupported`, `cancelled` and `inconclusive` do not mean authentic.** |
| `scores` | 0 to 1, per requested type the analyzer scored. **A type it did not assess is absent and listed in `unsupported_types`; it is never scored 0.** |
| `confidence` | As the analyzer reported it, if at all |
| `classification` | `likely_synthetic`, `likely_authentic`, `inconclusive`, or null. **Advisory**, derived from the operator's thresholds when set; null when none are set |
| `signals` | Structured evidence from the analyzer |
| `provider`, `analyzer`, `model_version`, `policy_version`, `input_fingerprint` | What produced the result, under which thresholds, from which content |
| `requested_at`, `started_at`, `completed_at`, `attempts`, `error_code`, `superseded_by`, `reanalysis_of` | The lifecycle |

**Results are append-only.**
* A re-analysis creates a new row. Once it reaches an assessment, the earlier row is marked `superseded_by`, and nothing else in the earlier row is written.
* A failed re-analysis leaves the earlier result current.
* A human review is its own row (`media_analysis_reviews`), attributed and audited, and never edits the analysis.

**The decision belongs to the consumer:** review, label, hide, block, or ignore.

### 6. Integration owns vendors

A separate capability, `media_analysis`, is not part of `ai`. That capability generates text from one default provider and model, and a media analyzer differs in contract, limits, cost and what it is sent.

* `MediaAnalysisManager` follows ADR 0017.
* Drivers implement `MediaAnalysisProviderContract`: configuration fields, missing configuration, descriptor (supported types, accepted files, the vendor's limits) and analyze.
* Credentials are encrypted on the provider row. Every call is logged in `integration_usage_logs` with one unit.
* **No failover:** two analyzers give two different readings of one file.
* **No driver ships.** No vendor has been chosen, and there is no log-style analyzer, because a detector that answers without looking is the absence of one. Until a driver is added, the capability reports itself not configured.

### 7. Operator configuration

The `media_analysis` settings group:

| Setting | Default | Meaning |
| :--- | :--- | :--- |
| `enabled` | off | Global availability: a kill switch, never a list of places analysis is used |
| `min_video_duration_seconds`, `max_video_duration_seconds` | unset | Duration limits for analysis requests (§11) |
| `max_file_size_mb`, `daily_limit` | unset | Platform limits; for size, the stricter of this and the analyzer's applies |
| `timeout_seconds` | 300 | Operational ceiling for one analysis |
| `likely_synthetic_threshold`, `likely_authentic_threshold` | unset | Thresholds for the advisory classification |

No limit or threshold is guessed on the operator's behalf. The vendor, model and credentials are configured on the provider row. None of this configuration names where analysis is used.

Retention is not configured here. Results are append-only records of what was sent to whom, and are removed with the media they belong to (the foreign key cascades); a separate retention period would be a policy decision about that record, and none has been made.

### 8. Permissions, audit, cache

* **Permissions** for the Admin surface: `media.analysis.view`, `media.analysis.request`, `media.analysis.review`. A consumer's own screen uses the consumer's own permission.
* **Audited:**
  * `media.analysis_requested`, for a manual request from the Admin;
  * `media.analysis_reviewed`, for a human review, recording the decision and whether a note was given, never the note itself.

  Analyses a module requests are not audited: the row is their operational record. Neither action records a signed URL, a credential or media content.
* **Cache:** every analysis response is authenticated, so it is `no-store` (ADR 0036), and a result never reaches an edge. No application cache is used; `latest()` reads the table.

### 9. Queue

The `media-analysis` queue runs on its own Horizon supervisor, with one process by default. It uses a dedicated Redis connection whose `retry_after` exceeds the longest allowed analysis, so a long analysis is never handed to a second worker while the first is still running.

### 10. The Admin surface

The media library's detail panel is one consumer (`admin.manual`), shown only to `media.analysis.view`. It says why analysis is unavailable when it is, and requests an analysis only when an operator presses the button, for types the operator chooses. It shows:

* a score per assessed type;
* each type that was not assessed, named as such rather than scored 0;
* failed and inconclusive results as exactly that;
* the provider, model and policy version behind each result.

A review is submitted as its own record. The panel never starts an analysis when a file is opened or uploaded.

### 11. Duration limits, and where the duration comes from

*Added 2026-09-14.*

**The limits are settings.** `media_analysis.min_video_duration_seconds` and `media_analysis.max_video_duration_seconds` are declared in the settings catalogue, stored as whole seconds, and edited in the Admin as `HH:MM:SS`. The setting definition's new `unit` (`seconds`) is how the screen knows to present them that way. No duration is written in code; three and five minutes are an operator's example, not a default.

* Both bounds are optional; unset means no limit.
* Both must be between 0 and 86400.
* A write that leaves the minimum above the maximum is refused as a whole. A catalogue whose settings constrain one another implements `ConstrainsSettings`, and the settings service checks the group as it would stand after the write, inside the write's transaction.

**They apply to analysis requests, and to nothing else.** Uploading a video is never checked against them. An eight-minute video under a five-minute maximum uploads, processes and is served exactly as before. A request to analyse it answers `duration_too_long`, records nothing, and sends nothing to the analyzer.

**The outcomes are their own:** `duration_too_short`, `duration_too_long` and `duration_unavailable`.

* **Bounds.** Both are inclusive and compared to the millisecond: under a 3:00 minimum, 3:00.000 is accepted and 2:59.999 is refused. The analyzer's own maximum still applies when it is stricter.
* **Which media.** The limits apply to media that plays for a length of time (video and audio). An image has no duration to limit.
* **Unknown duration.** Timed media whose duration was never read is refused as `duration_unavailable`, whatever the limits are. It is not sent on the assumption that it fits.
* **Re-checked before running.** The job checks again before handing the file over, so limits tightened while a request waited cancel it (`DURATION_TOO_LONG` on the row).
* **Through the Admin API.** Each outcome is a stable 422 with a code (`MEDIA_ANALYSIS_DURATION_*`) and details naming the duration and the limit, never a 500.

**The duration is read once, during intake, with ffprobe.** The backend image installs `ffmpeg`, which provides `ffprobe`.

* **Where it runs.** The image is the one every PHP service runs from, but only the queued `media` jobs execute ffprobe, in the Horizon worker. A request never probes a file.
* **Which processor.** `TimedMediaProcessor` handles video and audio through `FfprobeInspector`. It records `duration_ms`, `duration_seconds`, dimensions and codecs on the media record, so an analysis request reads the metadata and never runs the probe again.
* **Probe failures.** A file ffprobe cannot read is still stored and ready; `probe_error` records a stable reason (`probe_failed`, `unsupported_format`, `timed_out`, …). Duration is metadata, not a condition of accepting an upload.
* **Existing files.** Files taken in before this have no duration. `php artisan media:probe-durations` queues a reading for each once and skips any file that already has one.

**The probe is run defensively:**

* **No shell.** The command is an argument list, and the only path in it is a temporary file the inspector named.
* **Local files only.** `-protocol_whitelist file` and a `file:` input stop a crafted container from reaching the network or another file.
* **Bounded.**
  * A size ceiling applies before anything is copied.
  * `-probesize` and `-analyzeduration` cap what ffprobe reads.
  * A wall-clock timeout stops a probe that runs too long, and the output is capped.
  * The probe runs at lower priority, under `nice`.
* **Cleaned up.** The temporary copy is removed on every exit, including a timeout.
* **Nothing of ffprobe's output kept.** Its error text names the temporary path and helps nobody.

These bounds are deployment configuration (`config/media.php`), not operator settings. Container-level CPU and memory limits for the worker remain a deployment decision; none is imposed by the compose files.

## Alternatives considered

**Analyse every uploaded video.** Rejected. It spends money and sends media to a third party for uses that never asked, and it takes the decision away from the consumer.

**Keep `MediaVerifierContract` and add to it.** Rejected, for the reasons in Context. It was never used, so replacing it cost nothing.

**Fold media analysis into the `ai` capability.** Rejected: different contract, limits, cost and data flow.

**A general AI service registry.** Rejected for now (ADR 0033). The existing pattern (Core contract, Integration capability, settings, consumer) covers text generation and media analysis without one.

**A boolean verdict with a threshold applied in Core.** Rejected. No analyzer supports the claim, and a consumer's tolerance for risk is its own.

## Consequences

Any module can add AI video detection to one form and not another with a single call, and without knowing the vendor.

An operator can switch the capability off at once; every consumer then receives `disabled`, and no media is sent.

The worker and scheduler become part of the capability. A deployment that runs neither leaves analyses `pending`, and they are visible as such.

A real result needs a vendor driver. That is an external decision: the vendor, its contract, credentials, data-processing terms and cost. The capability is ready for one and works end to end against a test driver.

The image installs ffmpeg for reading metadata (§11), and that is all the platform uses it for. A driver that needs frames extracted locally must bound that work the way the probe is bounded, or send the file to a vendor.

Duration limits make an analysis depend on a duration having been read. A video whose duration could not be read cannot be analysed until it is; for files taken in before the probe existed, `media:probe-durations` reads them once.
