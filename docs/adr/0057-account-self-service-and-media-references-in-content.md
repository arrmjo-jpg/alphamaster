# ADR 0057: Account Self-Service in the Console, and Media References in Content

* **Status**: Accepted
* **Date**: 2026-09-15
* **Amends**: ADR 0037 (audit vocabulary), ADR 0055 §8 (the editors gain image fields)
* **Closes**: ADR 0029 item 23
* **Builds on**: ADR 0024 (media), ADR 0049 (interface catalogue), ADR 0051 §4 (the account profile), ADR 0055 §10 (media references)

## Context

Three gaps shared one cause. The platform had the capability, and the console did not use it.

* **The administrator's own account.** `/api/v1/profile` (ADR 0051 §4) already let any signed-in account, administrators included, change its name, bio, phone and preferred language, set a password, and set or remove its picture. `/auth/mfa` already reported and disabled the second factor. The console's account page could only confirm a phone number, and ADR 0029 item 23 recorded that as open.
* **Pictures.** The picture was stored as media, but `/auth/me` did not carry it, so the top bar could only draw initials.
* **Images in content.** Team members have `avatar_media_id`. Pages and members have a per-language `og_media_id`. Both are resolved through `MediaReferenceContract`, which accepts only a public image that is ready to serve. The API accepted these ids, but the editors offered no way to choose one.

The reference platform was studied for experience, not architecture. Its administrator profile combines editing, a password change, sessions, activity, analytics and permissions. Its avatar upload writes a file to a public disk and hands the path back to be saved. Its team picker chooses from a central media library or uploads into it. Its pages have no image fields at all.

## Decision

### 1. The administrator's profile is the account profile

There is no administrator-specific profile API. The console's account page is a client of `/profile`, `/profile/password`, `/profile/avatar` and `/auth/mfa`, exactly as a mobile client would be.

**An account is not a team member.** An account signs in. A team member is content a site shows. The only thing they share is the kind of picture they use: a public image from the media capability.

### 2. What the page offers

| Section | Endpoint | Rule |
| :--- | :--- | :--- |
| Picture | `POST`/`DELETE /profile/avatar` | JPEG, PNG or WebP up to 5 MB. The validator reads the bytes, and the picture is public |
| Identity | `/auth/me` | an administrator's address is shown and not edited (ADR 0051 §4) |
| Profile | `PATCH /profile` | name, bio, phone, preferred language; only changed fields are sent |
| Phone | `/auth/phone/verify` | unchanged; a new number is stored unconfirmed |
| Password | `PUT /profile/password` | the current password when one exists; every other session is signed out |
| Two-factor | `GET`/`DELETE /auth/mfa` | status, methods, recovery codes left; disabling needs a current code or an unused recovery code |

* **Disabling the second factor, for an administrator,** signs out every session, this one included, and the next sign-in asks for enrolment again. This behaviour already existed (ADR 0013). The page says so before the button is pressed.
* **Replacing a method from inside a session is not offered.** Enrolment happens at sign-in.
* **The preferred language is not the console's language.** It is the language the platform writes to the account in. The console's language is still chosen in the top bar (ADR 0049).
* **No permission gates the page.** Nothing on it names an account.

`/auth/me` gains `avatar_url`, read through `ProfileAvatarContract`, so every screen that shows who is signed in can draw the picture without fetching the whole profile.

### 3. What an account does to itself is audited

ADR 0037 recorded account changes made by administrators, and social links whoever made them. A change an account makes to itself is now recorded too. The subject is the account, whoever changed it.

```
account.updated           changed=[name, bio]  by_account_holder=true  email_verification_cleared  phone_verification_cleared
account.password_changed  had_password  other_sessions_revoked
account.avatar_changed    media_id
account.avatar_removed
account.mfa_enabled       method
account.mfa_disabled      sessions_revoked
```

* **The redaction rule is unchanged.** Fields are named, never valued. There is no password in any form, no second-factor secret, and no recovery code or code used. A media id names a file, not a person, and is recorded; the file's name is not.
* **A request that changes nothing records nothing:** a save of current values, a removal when there is no picture, a refused code.
* **Enabling and disabling a factor are not authentication events.** They change who can sign in, once. Challenges and sign-ins stay out of the trail, as ADR 0037 decided.

### 4. Content refers to images through one field and one capability

There is **no upload endpoint per module**, and none per screen. An image for content is uploaded through `POST /media` as a public image in a collection named for its consumer (`team`, `pages`). The content keeps the id, and `MediaReferenceContract` decides whether the id is acceptable.

The console has one field for this, used by every editor:

* **Upload.** The field uploads and asks until the image is ready. Only a ready image's id is handed to the editor, because that is the rule the save enforces. An image that fails processing is reported. One still processing when the field stops asking is left in the library.
* **Library.** Offered only to an account holding `media.view`, and showing only public, ready images, because a private one would be refused.
* **Remove.** Clears the reference and deletes nothing: the file is the library's.

Editing content still needs the content's own permission. Uploading needs no media permission, because media is a platform capability (ADR 0024).

**Where the fields go:**
* A team member's picture is the same in every language, so it saves on choice, like the links.
* A sharing image is per language and saves with that language's translation.

**What is not added:**
* **A featured image for pages.** ADR 0055 did not decide one, the reference platform's pages have none, and no consumer needs one.
* **Images inside a page body.** The sanitizer allows no `<img>`. Allowing one raises questions this record does not settle: a URL or a media id, private media, and what an edge caches.

### 5. Concurrency

The profile write is partial, self-only and last-write-wins, and it takes no `If-Match`. Nothing else edits an account's own bio or preferred language concurrently in any way that matters. Where two parties can edit an account, the administrative edit, the existing rules apply.

Content keeps ADR 0055's row lock. The picture is replaced atomically by media.

### 6. Image processing is not required by any of this

ADR 0024's named variants remain unbuilt, and nothing here needs them:
* A picture and a thumbnail in the console are drawn with `object-fit: cover` at a fixed box.
* A sharing image is served as its original.
* Uploads are capped at 5 MB, and the validator reads the bytes.

Serving originals costs bytes, not correctness. Variants become necessary when a consumer needs a fixed pixel size, for example Open Graph's recommended dimensions, or when bandwidth on public pages measurably matters. That work is ADR 0024's, needs gd or imagick in the image, and stays there.

## Consequences

* An administrator can correct their own name, set a password, set a picture and see and disable their second factor, without holding `users.update`.
* The trail answers "what did this account do to itself" with the same redaction as everything else in it.
* A future module that needs an image uses the same field and the same contract, with no upload code of its own.
* Pages and members get sharing images in the console, and members get pictures.
* Still absent: a list of the account's sessions, voluntary enrolment from the console, replacing a method in-session, image variants, and images inside HTML bodies.
