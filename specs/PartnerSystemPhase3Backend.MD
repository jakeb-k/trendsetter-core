# Partner System Phase 3 Backend Specification

## Purpose

This document translates the product-level Phase 3 partner alert spec into a backend implementation contract for `trendsetter-core`.

Phase 3 backend work is responsible for:

- evaluating accountability alert conditions from server-trusted data
- suppressing false positives and repeated alerts
- creating in-app partner notifications using Laravel's notification primitives
- supporting preset-only encouragement actions
- exposing notification read APIs
- recording enough evaluation metadata for debugging and tuning

This phase must build on the current Phase 2 backend without expanding partner visibility beyond the safe snapshot contract.

## Current Baseline

By the end of Phase 2, the backend already supports:

- invite creation and acceptance
- live `goal_partnerships` records
- `status` and `notify_on_alerts` on partnerships
- safe partnership listing and snapshot read APIs
- partner lifecycle controls: patch settings, pause, unpause, unlink
- owner-only protection on raw feedback and review endpoints
- schedule-aware snapshot calculations in `GoalPartnerSnapshotService`

Phase 3 reuses that foundation. It does not replace it.

## Scope

Phase 3 backend includes:

1. shared occurrence-generation extraction
2. alert evaluator service
3. alert event persistence
4. in-app partner notification integration
5. read/read-all notification APIs
6. preset encouragement persistence and API
7. event-driven evaluation triggers
8. scheduled evaluation command
9. config defaults and test coverage

Phase 3 backend does not include:

- push notifications
- emails after invite acceptance
- free-text partner messaging
- expanded partner data access
- client-authoritative alert generation

## Hard Rules

- Alerts are generated from persisted backend data only.
- Partners never receive raw notes, moods, feelings, AI plan text, or journal content.
- `goal_partnerships.status = paused` suppresses new alerts.
- `goal_partnerships.notify_on_alerts = false` suppresses new alerts.
- Unlinked or deleted partnerships must immediately stop new alert creation.
- At most one partner-facing alert notification should be created per partnership/goal within a 24 hour cooldown window.
- The alert evaluator must share the same recurrence logic used by the Phase 2 snapshot service.
- No generic high-churn read endpoint should trigger alert evaluation in v1.
- V1 alert timing should use a single canonical backend time basis, preferably UTC.
- User-cleared or expired read notifications should be hard deleted, not soft deleted.
- Delivery failures for queued notification jobs should rely on Laravel's existing queue + `failed_jobs` behavior, not a custom failed-notifications table.

## Backend Deliverables

### 1. Shared occurrence service

The recurrence logic currently embedded inside `GoalPartnerSnapshotService` must be extracted into a reusable backend service.

Suggested class:

- `App\Services\GoalOccurrenceService`

Minimum responsibilities:

- generate scheduled occurrences for one event in a date window
- generate scheduled occurrences for a goal/event collection in a date window
- resolve event end date from `scheduled_for + duration_in_weeks - 1 day`
- build weekly offsets from `times_per_week`
- build event/date lookup keys
- expose helpers for:
  - scheduled occurrences to date
  - future occurrences
  - missed eligible occurrences

`GoalPartnerSnapshotService` should then delegate recurrence generation to this shared service instead of owning its own recurrence implementation.

This is required so snapshot output and alert generation cannot drift.

### 2. Alert evaluator service

Suggested class:

- `App\Services\GoalPartnershipAlertEvaluator`

Public API should look roughly like:

- `evaluate(GoalPartnership $partnership, string $triggerSource): AlertEvaluationResult`

Responsibilities:

- load goal, events, and owner feedback context
- exit early when partnership is ineligible
- derive safe signal values needed for alert decisions
- determine which alert types are currently true
- apply grace suppression
- choose the highest priority candidate
- build a stable dedupe key
- enforce duplicate suppression
- enforce rate limiting
- persist an alert-event row with outcome metadata
- create partner notification row only when the outcome is `generated`

The evaluator should not return raw feedback, only safe derived state.

### 3. Notification service

Suggested class:

- `App\Services\GoalPartnershipNotificationService`

Responsibilities:

- create partner-facing notifications using Laravel database notifications
- format title/body from alert type and role tone
- attach safe context payload and deep link target
- optionally create owner-facing encouragement notifications

The formatter must only use safe data already allowed by Phase 2 snapshot rules.

Suggested notification classes:

- `App\Notifications\PartnerAlertNotification`
- `App\Notifications\PartnerEncouragementNotification`

### 4. Encouragement service

Suggested class:

- `App\Services\GoalPartnershipEncouragementService`

Responsibilities:

- validate preset key
- ensure the notification belongs to the authenticated recipient
- ensure encouragement is only sent once per alert notification
- store the encouragement action
- optionally create an owner-facing `encouragement_received` notification

No free-text payload is allowed.

## Alert Types

The backend must support these alert types:

- `streak_broken`
- `consecutive_misses`
- `behind_pace`
- `inactivity`

### `streak_broken`

Condition:

- there was an active streak
- a scheduled occurrence is now old enough to judge
- that occurrence has no matching owner log
- that missed occurrence breaks the streak

### `consecutive_misses`

Condition:

- at least 2 scheduled occurrences in a row are missed and eligible for evaluation

Threshold should be configurable.

### `behind_pace`

Condition:

- pace status is behind
- pace delta is materially negative
- expected points have crossed a minimum threshold so the alert does not fire on tiny/new goals

Suggested default:

- `pace_delta <= -2`
- `points_expected_by_today >= 4`

### `inactivity`

Condition:

- no owner logs have been submitted for at least the configured number of days

Suggested default:

- `>= 3 days`

If the owner has never logged, inactivity should derive from goal start or the first active scheduled period, never from partnership creation time.

## Alert Priority

If multiple alert conditions are true in the same evaluation, create at most one user-facing partner notification.

Priority order:

1. `consecutive_misses`
2. `streak_broken`
3. `inactivity`
4. `behind_pace`

`consecutive_misses` stays first because it is the more extensible miss-based signal: it captures both that a break happened and how deep the current miss pattern is.

All candidate types should still be persisted in evaluation metadata even if only one is surfaced.

## Evaluation Triggers

The backend must support two v1 evaluator sources:

- `log_submit`
- `scheduled_scan`

### `log_submit`

Integration point:

- `EventController::storeEventFeedback`

Behavior:

- trigger evaluation only after the feedback write succeeds
- evaluate the partnership tied to the event's goal, if one exists

### `scheduled_scan`

Integration point:

- console command + scheduler

Behavior:

- periodically evaluate partnerships where recent app-open or log-submit triggers may not happen

Explicit app-open evaluation can be added later via a dedicated lightweight heartbeat endpoint if product needs it.
V1 must not piggyback on `GoalController::getGoals`.

## Evaluation Eligibility

A partnership is eligible for alert creation only if all are true:

- partnership exists
- `status = active`
- `notify_on_alerts = true`
- goal exists
- goal is not completed
- goal has at least one event
- the recipient partner user exists

Suppressed eligibility outcomes should still be recorded in alert-event metadata where practical.

## Grace Window

Because the scheduling model is date-based, Phase 3 should use simple post-date grace rules in a canonical backend time basis for v1.

Default behavior:

- a due-today occurrence is not alertable yet
- once the date rolls over, apply a `6 hour` grace window before treating it as missed

Recommended config:

- `PARTNER_ALERT_GRACE_HOURS=6`

Canonical-time rule for v1:

- store and evaluate alert timing in UTC or a single equivalent backend canonical timezone
- do not attempt moving-timezone correction in v1
- frontend display conversion does not affect evaluator behavior

The evaluator should still bias toward suppression rather than early alerting at uncertain boundaries.

## Duplicate Suppression

The evaluator must build a stable `dedupe_key` for candidate alerts.

Recommended components:

- `partnership_id`
- `goal_id`
- `alert_type`
- signal fingerprint

Signal fingerprint examples:

- `streak_broken`: first missed occurrence date that broke the streak
- `consecutive_misses`: latest missed occurrence date plus miss count
- `behind_pace`: the first threshold crossing into behind pace, then a new fingerprint only if the gap worsens materially
- `inactivity`: last log date or inactivity threshold boundary

If the same dedupe key has already produced a notification, do not generate another identical notification.

## Rate Limiting

In addition to dedupe, Phase 3 must enforce a cooldown.

Default rule:

- no more than one generated partner alert notification per partnership/goal per 24 hours

Recommended config:

- `PARTNER_ALERT_RATE_LIMIT_HOURS=24`

This cooldown applies even if the alert type changes during that window.

## Data Model

### Laravel database notifications

Use Laravel's built-in database notification system for user-facing partner notifications instead of creating a custom `goal_partnership_notifications` table.

This should rely on:

- `Illuminate\Notifications\Notifiable` on `User`
- Laravel's `notifications` table
- notification classes for partner alert payloads

Required payload fields in notification `data` should include:

- `partner_alert_type`
- `source`
- `partnership_id`
- `goal_id`
- `goal_title`
- `role`
- `deep_link`
- `dedupe_key` nullable
- `related_alert_event_id` nullable
- `encouragement`
  - `allowed`
  - `already_sent`
  - `presets`

Required read-state behavior:

- use Laravel's `read_at`
- recipient-only read/update semantics
- hard delete read notifications when user clears them or when retention pruning removes them

Push delivery remains out of scope, but this notification payload shape should be future-compatible with additional delivery channels.

### Table: `goal_partnership_alert_events`

Purpose:

- stores evaluation outcomes for observability

Suggested columns:

- `id`
- `partnership_id`
- `goal_id`
- `subject_user_id`
- `recipient_user_id`
- `trigger_source`
- `primary_alert_type` nullable
- `candidate_types` JSON nullable
- `outcome`
- `reason_codes` JSON nullable
- `dedupe_key` nullable
- `signal_date` nullable
- `snapshot_excerpt` JSON nullable
- `notification_id` nullable
- `evaluated_at`
- timestamps

Suggested `outcome` values:

- `generated`
- `suppressed_no_signal`
- `suppressed_grace`
- `suppressed_duplicate`
- `suppressed_rate_limited`
- `suppressed_paused`
- `suppressed_notify_disabled`
- `suppressed_goal_completed`
- `suppressed_no_partnership`

Suggested `trigger_source` values:

- `log_submit`
- `scheduled_scan`
- `manual_debug`

Indexes to consider:

- `(partnership_id, evaluated_at)`
- `(goal_id, evaluated_at)`
- `(recipient_user_id, evaluated_at)`
- `(outcome, evaluated_at)`

Retention rule:

- generated rows may be kept longer for audit/debugging
- suppressed rows should be aggressively pruned or compacted
- a practical v1 policy is to keep suppressed rows for 7 to 14 days max

If suppression volume becomes high, keep only the latest suppressed outcome per partnership/outcome/day instead of every individual suppression row.

### Table: `goal_partnership_encouragements`

Purpose:

- stores partner encouragement actions tied to an alert notification

Suggested columns:

- `id`
- `partnership_id`
- `goal_id`
- `source_notification_id`
- `sender_user_id`
- `recipient_user_id`
- `preset_key`
- timestamps

Constraints:

- one encouragement per source notification

Indexes to consider:

- `(source_notification_id)` unique
- `(recipient_user_id, created_at)`

## Models

Suggested Eloquent models:

- `GoalPartnershipAlertEvent`
- `GoalPartnershipEncouragement`

Relationships to add:

- `GoalPartnership` has many alert events
- `GoalPartnership` has many encouragements
- `User` has many sent encouragements

Required casts:

- `context_payload` => `array`
- `candidate_types` => `array`
- `reason_codes` => `array`
- `snapshot_excerpt` => `array`
- `read_at` => `datetime`
- `delivered_at` => `datetime`
- `evaluated_at` => `datetime`

Laravel notification classes should be used for recipient-facing alerts instead of a custom `GoalPartnershipNotification` model.

## API Surface

All notification APIs must be behind `auth:sanctum`.

### `GET /api/v1/partner-notifications`

Purpose:

- list authenticated user's partner-related notifications

Implementation note:

- this endpoint should read from Laravel database notifications scoped to the authenticated user

Response fields per item should include:

- `id`
- `type`
- `source`
- `title`
- `body`
- `deep_link`
- `read_at`
- `created_at`
- `partnership`
  - `id`
  - `goal_id`
  - `status`
  - `role`
  - `goal`
    - `id`
    - `title`
    - `status`
- `encouragement`
  - `allowed`
  - `already_sent`
  - `presets`

The nested partnership payload must stay redacted and mirror the Phase 2 serialization contract where possible.

### `GET /api/v1/partner-notifications/unread-count`

Purpose:

- return unread badge count for the authenticated user

### `POST /api/v1/partner-notifications/{notification}/read`

Purpose:

- mark one notification as read

Rules:

- recipient-only
- idempotent

### `POST /api/v1/partner-notifications/read-all`

Purpose:

- mark all of the authenticated user's partner notifications as read

### `DELETE /api/v1/partner-notifications/clear-read`

Purpose:

- hard delete the authenticated user's read partner notifications

### `POST /api/v1/partner-notifications/{notification}/encouragement`

Purpose:

- send a preset encouragement from the partner in response to an alert

Request body:

- `preset_key`

Rules:

- recipient-only
- only valid for system-generated partner alert notifications
- only one encouragement allowed per alert notification
- preset must be from the backend-approved allowlist
- must fail if the partnership no longer exists or is no longer accessible

## Notification Copy Rules

The backend must own the canonical notification type and safe payload shape.

Allowed notification content:

- goal title
- alert type
- partnership role tone
- deep link
- safe snapshot-derived labels like next event label

Forbidden notification content:

- raw log notes
- moods
- feelings
- reasons/excuses
- AI plan text
- review text
- private reflections

Role tone may affect wording:

- `cheerleader`
- `drill_sergeant`
- `silent`

Role tone must never affect permissions, thresholds, or data exposure.

## Command And Scheduler

Suggested command:

- `goal-partnerships:evaluate-alerts`

Responsibilities:

- scan eligible active partnerships in chunks
- only evaluate narrowed candidates, such as partnerships where the owner has not logged recently or where a due window has passed
- call the same evaluator used by trigger-based entrypoints

Recommended schedule:

- daily

Suggested scheduler registration:

- register in `routes/console.php`

## Performance Expectations

- Scheduled scans must chunk queries, never load all partnerships into memory at once.
- Trigger-based evaluation should avoid N+1 loads.
- Notification list APIs must paginate or cursor.
- Read-all updates should be set-based, not row-by-row.
- Evaluation should reuse precomputed occurrence helpers where possible.
- Daily scans should strictly narrow candidates before evaluation rather than scanning every active partnership blindly.

## Security Requirements

- Authorization must be recipient-bound for all notification APIs.
- Encouragement actions must verify the authenticated user is the intended notification recipient.
- No notification endpoint may leak removed partnership data after unlink.
- Alert generation must never depend on client-reported schedule or streak assumptions.

## Config

Recommended config keys:

- `PARTNER_ALERT_GRACE_HOURS=6`
- `PARTNER_ALERT_RATE_LIMIT_HOURS=24`
- `PARTNER_ALERT_CONSECUTIVE_MISSES=2`
- `PARTNER_ALERT_INACTIVITY_DAYS=3`
- `PARTNER_ALERT_BEHIND_PACE_POINTS=2`
- `PARTNER_ALERT_MIN_EXPECTED_POINTS=4`
- `PARTNER_ALERT_SCAN_CHUNK_SIZE=200`
- `PARTNER_ALERT_SUPPRESSED_RETENTION_DAYS=7`
- `PARTNER_NOTIFICATION_READ_RETENTION_DAYS=7`

These values should live in config, not hard-coded service internals.

## Implementation Order

Recommended backend implementation order:

1. Extract recurrence logic from `GoalPartnerSnapshotService` into `GoalOccurrenceService`.
2. Add Laravel database notifications table if not already present.
3. Add migrations for alert-event and encouragement tables.
4. Add models, casts, relationships, and notification classes.
5. Implement `GoalPartnershipAlertEvaluator`.
6. Integrate evaluator into `EventController::storeEventFeedback`.
7. Implement notification list/read/read-all/clear APIs.
8. Implement encouragement API and service.
9. Add scheduled command and scheduler hook.
10. Add pruning for suppressed alert events and read notifications.
11. Add feature and unit coverage.

## Acceptance Criteria

Phase 3 backend is complete when:

- alert evaluation can run from log submit and scheduled scan
- due-today unlogged occurrences do not alert prematurely
- repeated identical alert state is deduped
- rate limiting prevents alert spam
- paused partnerships never generate alerts
- `notify_on_alerts = false` partnerships never generate alerts
- completed goals do not generate alerts
- partner notifications are listable and markable as read
- encouragements are preset-only and one-time per alert
- no notification payload exposes raw private goal/event feedback
- alert events record generated and suppressed outcomes for debugging
- read notifications can be hard deleted by user action or retention pruning

## Test Matrix

Backend tests should cover:

- evaluator detects each alert type
- evaluator suppresses within grace window
- evaluator suppresses duplicate dedupe key
- evaluator suppresses within cooldown window
- evaluator suppresses paused partnership
- evaluator suppresses `notify_on_alerts = false`
- evaluator suppresses completed goal
- `storeEventFeedback` trigger path
- scheduled command trigger path
- notification list returns recipient-only rows
- unread count accuracy
- single read endpoint idempotence
- read-all endpoint behavior
- clear-read hard delete behavior
- encouragement preset validation
- encouragement recipient authorization
- one encouragement per alert constraint
- no private payload leakage in notification responses
