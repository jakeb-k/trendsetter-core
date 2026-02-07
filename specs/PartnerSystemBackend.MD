Backend requirements

Partner relationship rules: enforce one active partner per goal, allow different partners across different goals, prevent self-invites, prevent duplicate active links, support pending invites, accepted active links, declined and expired states.

Invitation delivery: send invite email with expiring single-use link, link must be claimable by an existing or newly created account, accepting binds the relationship to the authenticated partner user.

Access control: partner can only read the partnered goal snapshot, never raw logs, never private text fields, never AI plan content, never edit goal/events/logs, initiator can manage link settings.

Partner snapshot generation: compute and return a safe “signals-only” snapshot containing streak length, last log time, inactivity days, weekly and rolling consistency, points earned, points expected by today, pace status and delta, next scheduled event time and optional label, momentum trend, exclude all private content.

Alert evaluation triggers: detect streak break, consecutive missed expected logs, behind pace beyond threshold, inactivity over X days, apply grace window for short outages, rate-limit per goal per 24h, suppress duplicates.

Hybrid evaluation model: run evaluation on user check-in events (app open, successful log submit), additionally run periodic evaluations for users who haven’t checked in recently so dodging the app doesn’t dodge accountability.

Notification delivery: create in-app notifications for partners only, no ongoing emails after invite, include neutral wording and deep link target, support “mark read”, support one-tap encouragement messages to the initiator (optional v1 but recommended), ensure encouragement is preset/controlled not free-text.

Lifecycle handling: pausing stops future alerts, removing instantly revokes access and stops alerts, removal is silent (no notification to other party), deleting a goal silently removes any partnership tied to it.

Observability: record when alerts were generated and delivered so you can debug rate limiting, grace window behaviour, and false positives.