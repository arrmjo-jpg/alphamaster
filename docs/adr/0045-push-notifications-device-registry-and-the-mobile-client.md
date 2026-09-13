# ADR 0045: Push Notifications, the Device Registry, and the Mobile Client

* **Status**: Accepted
* **Date**: 2026-09-10

## Context

The capability audit of 2026-09-10 records Firebase, push notifications and device registration as RED with the same evidence as AI: nothing exists. ADR 0019 anticipated the channel — "WhatsApp and push arrive when the Integration capabilities they need do" — and ADR 0017 anticipated the transport. Neither says what Firebase *is* to this platform, and that is the question that has to be settled first, because Firebase is not one thing.

Firebase is a suite: authentication, a document database, file storage, analytics, remote configuration, and a push transport. Adopting it without a decision means adopting whichever parts the first implementer reaches for, and several of those parts contradict records this platform already has.

There is a second question behind it. Push notifications imply a mobile application, and a mobile application cannot hold a credential the way a browser does. ADR 0042 settled the browser: the access token travels in an `HttpOnly` cookie, `SameSite=Strict`, path `/api`. A native app has no such cookie jar and no same-site guarantee to lean on, so either the perimeter grows a second answer or mobile is left undecided until somebody improvises one.

## Decision

### 1. Firebase is a push transport and nothing else

The platform adopts **Firebase Cloud Messaging**, as one driver behind one Integration capability. Every other Firebase product is explicitly out.

| Firebase product | Decision | Why |
| :--- | :--- | :--- |
| Cloud Messaging (FCM) | **Adopted**, as a `push` capability driver | It is the only route to APNs and to Android push that does not require running one's own infrastructure. |
| Authentication | **Rejected** | ADR 0012 and ADR 0013 own identity: token abilities, the five-stage perimeter, mandatory MFA for administrators. A second identity provider would mean two sources for who someone is and a perimeter that one of them does not pass through. |
| Firestore / Realtime Database | **Rejected** | ADR 0003 makes PostgreSQL the sole database engine. |
| Storage | **Rejected** | ADR 0024 owns media: storage, delivery, scanning, processing, access policy. |
| Analytics, Crashlytics, Remote Config | **Out of scope here** | Client-side concerns of an application this foundation does not ship. A project may use them in its own app; nothing in the backend depends on them. |

The consequence worth stating plainly: **Firebase is a vendor, not a platform.** It sits where Twilio sits. Replacing it with APNs directly, or with any other push service, is a driver method and a provider row.

### 2. The capability is `push`, and it behaves like `sms`

`IntegrationCapability::PUSH`, a migration widening the capability constraint, a `PushDispatcherContract`, an `fcm` driver against Laravel's HTTP client, credentials encrypted on the provider row, usage logged in `integration_usage_logs`.

Failover applies here, unlike AI (ADR 0044) and for the reason AI is the exception: push *is* a transport. The recipient cannot tell which service delivered the notification, so falling to a second provider delivers the same thing.

**The credential is a service-account JSON, not an API key.** FCM's v1 API authenticates with a Google service account: a JSON document containing a private key. The existing credential mechanism stores arbitrary strings encrypted and never reads one back, which covers it — but two properties have to be honoured deliberately because the shape is unusual:

* it is large and multi-line, so the Admin's secret field must accept a paste rather than a single line;
* it contains a private key, so the ordinary rule — never logged, never returned, a failed decrypt raises rather than returning ciphertext — is not a formality.

Access tokens derived from it are short-lived and cached in the platform cache (ADR 0035) rather than re-minted per message.

### 3. `push` is a notification channel, not a parallel notification system

`NotificationChannel::PUSH` joins database, mail and SMS. Templates, preferences and the locale rule all apply unchanged (ADR 0019):

* wording comes from `notification_template_translations`, so a push is translated like everything else;
* preferences are per type and per channel, so a recipient can decline account updates by push and keep security alerts;
* the two rules that are not preferences still hold — the in-app record is always written, and a security alert is never silenced on any channel;
* it renders in the **recipient's** locale, never the triggering request's.

Delivery goes through the Integration module, so the channel owns no transport, exactly as the SMS channel does.

### 4. A push payload carries no content

This is the decision that most affects what the mobile app has to do, so it is stated before the registry.

A push message carries a notification **type**, the **id** of the in-app record, and nothing else. No subject, no body, no name, no account detail.

Two reasons, both concrete:

* **A push payload passes through Google and Apple.** It is content this platform sent to a third party for delivery, and unlike an SMS there is no reason it has to be readable in transit — the device can fetch the message over the same authenticated API it uses for everything else.
* **A lock screen is a public surface.** A message body in a push is visible to anyone holding the phone.

The device receives the type and the id, calls `GET /notifications/{id}` with its own credential, and renders what comes back. The client decides what to show on the lock screen from the type alone, and the platform never puts a sentence somewhere it cannot control.

The cost is honest and worth recording: a device that is offline when the fetch is attempted shows a generic notification. That is the right trade.

### 5. The device registry belongs to Notification, and a token is an address

A `push_devices` table in the Notification module: `user_id`, an opaque `token`, the platform (`ios` / `android` / `web`), a device identifier the client generates and keeps, and `last_seen_at`.

It sits in Notification rather than User because a push token is a *delivery address*, which is what this module already stores — `notification_preferences` is keyed by `user_id` in the same way, and Notification reaches the recipient's phone number through a Core contract rather than by importing User (ADR 0019). The same direction holds here.

**Registration is the account's own act, behind no permission.** `POST /notifications/devices` and `DELETE /notifications/devices/{id}`, acting on `$request->user()`, taking no account identifier — the shape phone verification already uses, and for the same reason: there is no way to register a device against somebody else's account however the route is called.

**Token lifecycle is decided here rather than discovered later**, because a device registry that is not pruned becomes a table of addresses nobody reads:

* a token registered against a device identifier **replaces** the previous token for that device — FCM rotates tokens, and two rows for one handset means two notifications;
* a vendor response of `UNREGISTERED` or `INVALID_ARGUMENT` **deletes** the row. The vendor saying an address is dead is authoritative, and keeping it means retrying forever;
* signing out deletes the device's row. A shared handset must not keep receiving the previous account's notifications;
* a row unseen for a long period is eligible for removal by the retention policy that already governs the audit trail (ADR 0037), configured rather than fixed.

A recipient with no registered device is skipped rather than failing the notification, exactly as a recipient with no phone number is.

### 6. The mobile client is an API client, and it holds a bearer token

ADR 0042's cookie transport is a browser answer to a browser problem. A native application is a different caller, and the decision is:

**Mobile authenticates with the same Sanctum personal access tokens, carrying the same abilities, presented in an `Authorization` header.** Nothing about the perimeter changes: `auth:sanctum`, `ability`, `active`, and — for administrative routes — `admin` and `email-verified`. `tokenCan()` reads real abilities because the token is a real `PersonalAccessToken`, which is exactly the property ADR 0042 was careful to preserve.

The token is stored in the platform's own secure store — Keychain on iOS, Keystore on Android — and never in application-managed files or preferences. That is the mobile equivalent of `HttpOnly`: the guarantee is the operating system's rather than the browser's.

Two things follow that are worth being explicit about:

* **MFA is unchanged.** ADR 0013's flow is API-level: login yields an `mfa_token`, the challenge exchanges it. A mobile client walks the same states the Admin does.
* **There is no mobile API, no BFF, no `/mobile` prefix.** ADR 0001 makes this an API-only backend; a second API shaped for one client is two contracts to keep in step and the first one drifts. The mobile app consumes the same OpenAPI contract (ADR 0010) and can generate its own client from it (the ADR 0011 property, in a different language).

### 7. The mobile application itself is project-specific

The foundation provides: the API, the device registry, the push channel, the transport abstraction, and the contract a client generates from. It does not provide an application.

By ADR 0033's test — *would a different kind of application need this, in this shape?* — a screen, a navigation structure and a notification's meaning are all things you can only design once you know what the app is about. What is generic is everything above; what is specific stops at the API boundary.

## Consequences

**Push is a channel, so adding it costs an enum case, a driver, a table and a client — not a subsystem.** Preferences, templates, translation, the security-alert rule and the in-app record all work the day the channel exists, because they were built to.

**The contentless payload puts a requirement on every mobile client**: it must fetch before it can render anything specific. A project that finds this inconvenient should read §4 again rather than add a body to the payload.

**Two credential shapes now exist behind ADR 0017**: an API key and a service-account document. The Admin's secret field has to accommodate the second, which is a UI change with a security property attached, not a cosmetic one.

**Signing out has to reach the device registry.** That is a change to a path this platform already has, and it is the kind of coupling that gets missed — logout currently deletes the presented token and clears the session cookie, and nothing else. Deleting the device row is a third thing it will have to do, and Notification owns that row, so the call crosses a module boundary that today does not exist.

**Rows 8 and 9 of the capability matrix are unblocked by this record and are not implemented by it.** They stay RED until the code exists.

## Alternatives considered

**Firebase as the identity provider as well.** It is the reason many teams adopt Firebase at all, and it would delete the platform's own perimeter: ADR 0012's abilities, ADR 0013's mandatory administrator MFA, and the account-type discriminator of ADR 0028 all live on this side of the boundary. Two identity systems is one too many, and the one that would lose is the one the audit trail references.

**APNs and FCM as two direct integrations.** Removes a hop for iOS and doubles the transport code, the credential handling and the failure modes. FCM's own APNs bridge is the reason FCM is worth adopting; if it ever stops being, a second driver behind the same contract is the answer.

**A payload carrying the message body.** Renders instantly and offline, and puts the platform's content on a lock screen and inside a third party's delivery pipeline. Rejected on both counts, with the offline case accepted as the cost.

**A device token column on `users`.** Simpler until the second device, which is immediately: one account, a phone and a tablet. A registry is the shape from the start.

**A dedicated mobile BFF.** Fewer round trips for a chatty client, and a second contract that drifts from the first. If a screen needs three calls, the honest fix is an endpoint that serves that need in the one API — available to every client, generated into every typed client, tested once.
