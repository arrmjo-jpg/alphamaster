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
| `enabled` | off | Global availability |
| `max_file_size_mb`, `max_duration_seconds`, `daily_limit` | unset | Platform limits; the stricter of these and the analyzer's applies |
| `timeout_seconds` | 300 | Operational ceiling for one analysis |
| `likely_synthetic_threshold`, `likely_authentic_threshold` | unset | Thresholds for the advisory classification |

No limit or threshold is guessed on the operator's behalf. The vendor, model and credentials are configured on the provider row. None of this configuration names where analysis is used.

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

The container has no ffmpeg (ADR 0024). A driver that needs frames must bring its own isolated worker, or send the file to a vendor.
