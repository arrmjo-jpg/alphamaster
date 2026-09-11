# ADR 0044: AI as an Integration Capability

* **Status**: Accepted
* **Date**: 2026-09-10
* **Amended**: 2026-09-11 — the model belongs to the provider, not to a global setting: each provider keeps its own model (in its `settings`), a task that names none gets the answering provider's, and failing that the driver's default, so one vendor is never sent another's model; `ai.translation_model` is removed and an operator's choice carried onto the provider that used it. Google Gemini joins OpenAI and Anthropic as a third driver. AI providers are set up in the AI control centre with one form — provider, API key, model, test before save — replacing the generic credential editor and its failover priority, which meant nothing for AI. The endpoint, the authentication and the wire format belong to each driver and are not configuration: no base URL is stored or offered, and the name the driver gives its credential is never shown. A provider is ready when it holds a key; there is no separate enabled switch. Several providers may be configured at once, and choosing the default never deactivates the others; the default answers the platform's tasks, and there is still no failover (§3). Saving, removing a key and changing the default are audited without the key.

## Context

The capability audit of 2026-09-10 records four AI rows as RED with the same evidence: **zero references**. No module, no contract, no route, no settings group, no enum case. ADR 0017 names AI among the capabilities its manager pattern could carry and nothing implements it.

AI is about to be built, and three things make it unlike the two capabilities that already exist behind that manager.

**SMS and CAPTCHA have one operation each.** `send(SmsMessage)`. `verify(token)`. AI does not: completion, chat, embeddings, moderation, transcription, image generation are six different shapes with six different request and response types. A contract that covers all of them is not a contract.

**A vendor failure is not a delivery failure.** When Twilio refuses a message the platform tries Vonage and the recipient gets the same text. When one model refuses a prompt, another model's answer is a *different answer*. The failover chain that is right for a transport is wrong for a generator.

**The call is slow and priced per unit.** An SMS is a hundred milliseconds and a fixed price. A generation is seconds to minutes and costs by the token, on somebody's account, at a rate that changes. A design that treats it as an ordinary outbound call will put it in a request path and bill an operator for a page load.

There is also a boundary question ADR 0033 already answers and which is worth stating before anyone reaches for it: **an AI feature that only makes sense once you know what the application is about is project-specific.** "Summarise this article", "score this entry", "moderate this comment" are not foundation. The capability, its configuration, and the tasks that operate on *the platform's own* content are.

## Decision

### 1. AI is an Integration capability, with the same shape as SMS

`IntegrationCapability::AI`, a migration widening the capability constraint, drivers written against Laravel's HTTP client, credentials encrypted on the provider row, and every attempt recorded in `integration_usage_logs`. Nothing new is invented: this is the mechanism ADR 0017 exists for, and ADR 0033's revision explicitly permits provider abstraction and configuration ahead of a consumer.

Vendor SDKs stay out, for the reason ADR 0017 gives: a driver written against the HTTP client is coupled to our contract rather than a vendor's, and is fully exercisable through `Http::fake()`.

### 2. One contract per task, and the first task is the one with a consumer

Not one `AiContract` with a method per shape. A **task** is a narrow contract with one operation, its own request and result types, and its own configuration:

```php
interface TextGenerationContract
{
    public function generate(TextGenerationRequest $request): TextGenerationResult;
}
```

The first — and, until something else needs one, only — task is **translation assistance**: given a source string, a source locale and a target locale, propose a translation. It has a consumer, built and shipped: the translation workshop (ADR 0043) is a screen whose entire activity is producing a target string from a source string.

A second task is a second contract. It is not a parameter on the first.

### 3. No automatic failover between AI providers

The chain that ADR 0017 walks for SMS is not walked here. A failure returns a failure.

This is the decision most likely to be questioned, so the reasoning is written down: failover exists because a transport is interchangeable — the recipient cannot tell which carrier delivered the message. A generator is not interchangeable. Falling from one vendor to another silently changes what the platform produces, which makes the output irreproducible and makes a quality problem impossible to attribute. An operator who has configured two vendors has expressed a preference, not a redundancy.

The *selection* mechanism is unchanged: the default provider for the capability is the one used, and changing it in the Admin changes which vendor answers. Only the walk-the-chain-on-failure behaviour is disabled for this capability.

### 4. Every AI call is queued, and none is in a request path

A task is dispatched to a job on its own Redis queue (ADR 0020), and the result is written where the consumer can find it. No controller awaits a completion.

The reason is latency, and the reason it is architectural rather than a tuning choice is that the alternative degrades under exactly the conditions that matter: a synchronous call holds a PHP-FPM worker for the duration, so an AI vendor having a slow minute consumes the pool that serves sign-in.

The consequence for the interface is stated here rather than left to whoever builds it: **a suggestion arrives asynchronously.** The workshop asks for one, the field stays editable, and the suggestion appears when it appears.

### 5. AI never writes to the platform. It proposes, and a person accepts

A generated translation is placed in the editor's field. It is not saved. The operator reads it, edits it or discards it, and presses the button that already exists.

Three reasons, in the order they matter:

* **The audit trail stays true.** ADR 0037's trail records who changed a value. If a job wrote translations, the trail would record a system actor for content nobody read, and the one question an audit trail exists to answer becomes unanswerable.
* **Quality is somebody's responsibility.** A suggestion an operator accepted is a translation the operator is accountable for. A translation that appeared is nobody's.
* **It is reversible by construction.** Nothing has to be undone if the model is wrong, because nothing was written.

This applies to every future task, and it is the line that keeps "AI features" from becoming "the platform changed and nobody knows why".

### 6. Prompts are code

They ship with a deployment, they are reviewed in a diff, and they are not editable from the Admin.

A prompt is not configuration; it is the logic of the task. Making it runtime-editable would put the platform's behaviour outside its own version control, make a bug irreproducible from the repository, and hand whoever holds `settings.update` the ability to change what the platform tells a vendor about its own content.

What an operator *does* control: whether the capability is active, which vendor and model answer, the per-task ceiling on output size, and the timeout. Those are settings in an `ai` group.

### 7. Model is per task, not per platform

A cheap fast model is right for a translation suggestion and wrong for something else. `ai.translation_model` rather than `ai.model`, and a new task brings its own key.

The model is a setting rather than a driver constant because it changes on the vendor's schedule, not ours: a model is deprecated with a few months' notice and an operator must be able to move without a deployment.

### 8. Usage is logged in units, and cost is not stored

`integration_usage_logs` gains a nullable `units` column — tokens, for this capability; null for SMS and CAPTCHA, where the count is always one.

Cost is deliberately **not** recorded. Prices change, and a stored cost is wrong retroactively in a table nobody re-reads; worse, it invites a spend total that is confidently incorrect. Units are a fact the vendor reported. Turning units into money is the operator's arithmetic with the operator's contract, and it belongs on the vendor's invoice.

The existing rule stands and is worth restating because it is more important here than for SMS: **a usage log records that a call happened, never what it said.** No prompt, no completion, no fragment of either. A log that carried prompts would be a copy of the platform's content in a table with different access rules.

### 9. What may be sent to a vendor is enumerated, not assumed

A task declares the content it sends. For translation assistance that is: the source string, the source locale, the target locale. Nothing else.

Never sent, by construction rather than by care: credentials, secrets, audit records, personal data of accounts, whole rows, or any content the operator did not put in front of the task. A task that needs more is a new task with a new declaration and a new review.

The operator-facing consequence: sending content to a third party is a decision, so the capability ships **inactive**, exactly as Twilio does (ADR 0017), and activating it is a deliberate administrative act with an audit record.

### 10. It degrades to absent

Every consumer works with the capability switched off. The workshop is a translation workshop without AI; the suggestion button is not there. No screen depends on a vendor being configured, and no test needs one — the driver is faked at the HTTP boundary like every other.

## Consequences

**Adding a vendor is a driver method and a row.** Nothing that asks for a suggestion changes.

**Adding a task is a contract, a job, a settings key and a consumer.** The four-part shape is the cost, and it is deliberately not one line — a task is a new thing the platform sends somebody else's content to.

**No automatic failover is a real limitation.** A single misconfigured vendor means no suggestions until an operator changes the default. That is the correct failure: a suggestion is optional, and silently substituting a different model is worse than not answering.

**The queue is the seam that will need attention first.** A burst of suggestions is a burst of paid calls, and nothing here rate-limits them beyond the queue's own concurrency. The first sign of trouble is a bill, which is a bad first sign — a per-period ceiling on AI calls is the obvious next decision and is deliberately not made now, because the shape of it depends on usage nobody has yet.

**The AI rows in the capability matrix (10, 11, 12, 15) are unblocked by this record and are not implemented by it.** They stay RED until the code exists.

## Alternatives considered

**A general `AiContract` with a method per shape.** One interface, six methods, most of them unimplemented by any driver. Every consumer would have to know which methods its configured vendor actually supports, which is the coupling the manager pattern exists to remove.

**Synchronous calls with a short timeout.** Simpler, and it puts vendor latency in the sign-in worker pool. A three-second timeout does not fix that; it converts a slow feature into a broken one while still holding the worker for three seconds.

**Writing generated content directly.** Faster for an operator with hundreds of untranslated fields, and it breaks the audit trail's central claim. If bulk translation is wanted later, the honest shape is a job that produces a *batch of suggestions* an operator reviews and accepts, not a job that writes.

**Firebase's or a cloud provider's managed AI.** No architectural difference — each would be a driver behind the same contract. The decision is deliberately vendor-shaped rather than vendor-named.
