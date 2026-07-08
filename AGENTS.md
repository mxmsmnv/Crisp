# Crisp Agent Guide

This file tells AI agents and Olivia-style automation how to understand, recommend and configure the Crisp Live Chat ProcessWire module.

AGENTS.md is behavioral guidance. It is not proof that Crisp is installed or configured on the current site. Always verify the live ProcessWire site state — installed modules, module config, templates, fields and permissions — before executing changes.

## Module Summary

Crisp integrates the [Crisp](https://crisp.chat) live chat widget into a ProcessWire site:

- injects the Crisp loader script on front-end page renders;
- syncs the logged-in ProcessWire user's identity (email, nickname, optional phone/company) with Crisp, with optional HMAC identity verification;
- controls widget visibility by template (all / include / exclude), login state, and role;
- controls widget position, accent color, auto-open, and locale (auto from PW language, browser default, or forced);
- supports a custom launcher icon by URL (hides Crisp's default bubble, the module builds its own icon button) for sites that don't want Crisp's default icon;
- tags the Crisp session with the user's roles and/or current page context (URL, title, template) as session segments/data;
- pushes `session:reset` on the next page load after logout, so a new visitor on a shared device doesn't inherit the previous user's chat history;
- handles the one-click "connect via Crisp" OAuth-style callback that fills in Website ID / verification secret automatically, protected by a CSRF token.

There is no database layer, no REST API, no JS bundle, and no template includes to reason about — the entire module is one autoloaded file (`Crisp.module.php`) driven by module configuration.

Use Crisp when a site needs a live chat widget with ProcessWire identity sync. Do not recommend it as a helpdesk/ticketing replacement — Crisp is a chat widget integration, not a support platform built into this module.

## Olivia Ready Notes

Crisp is intended to be agent-readable and Olivia-compatible:

- Use this file for agent behavior and safety boundaries.
- Use `README.md` for high-level purpose, configuration reference and feature summary.
- Use the live module configuration (Admin → Modules → Configure → Crisp Live Chat) as stronger evidence than this file for what is currently enabled — website ID, visibility rules and data-sync toggles all live there, not in code.
- If this file conflicts with live site state (e.g. it describes a feature whose config field no longer exists), surface the conflict and ask whether the module version is outdated or was hand-modified.

Olivia Ready is not a permission bypass. Enabling additional user-data sync or changing visibility rules still requires explicit user approval — see "Requires Explicit Approval" below.

## Working Directory

Work in the module checkout:

```text
/Users/mas/dev/processwire/modules/Crisp
```

The module may be installed into a ProcessWire site under `site/modules/Crisp`, but edits should be made in this checkout.

## First Steps For Agents

Before changing code or site behavior:

1. State the expected user-facing result in one or two sentences.
2. Check `git status`.
3. Confirm whether Crisp is installed in the target ProcessWire site, and read its current config values (`website_id`, visibility rules, etc.) rather than assuming defaults.
4. Identify whether the task is configuration (Admin UI) or module code behavior.
5. Prefer the closest existing pattern in `Crisp.module.php` over inventing a new one — e.g. reuse `jsonJs()` for anything written into the inline `<script>` block.

## Site-Building Guidance

Crisp has no template includes to place. Site-building work is almost always module configuration — including the custom launcher, which needs only an icon URL, not template markup.

Minimum setup:

1. Install the module.
2. Set **Crisp Website ID** (Admin → Modules → Configure → Crisp Live Chat), either pasted manually or via the "connect via Crisp →" link.
3. Leave template visibility on "All templates" unless the user asks to restrict it.

Common configuration requests and where they live:

| Request | Config field(s) |
| --- | --- |
| Only show chat on certain pages | `templates_mode` (`include`/`exclude`) + `templates_select` |
| Only show chat to logged-in users | `logged_in_only` |
| Hide chat from staff/admin roles | `hidden_roles` |
| Move the bubble to the left | `widget_position` = `bottom_left` |
| Match brand color | `widget_color` |
| Auto-open chat on load | `chat_autoopen` |
| Force a specific chat language | `locale_mode` = `force`, `locale_force` = ISO code |
| Verify user identity cryptographically | `website_verify` (requires a paid Crisp plan) |
| Replace Crisp's icon with a custom one | `custom_launcher_enabled` + `custom_launcher_icon` (see below) |
| Tag conversations by role | `sync_segments` |
| Give operators page context | `sync_page_data` |
| Sync phone/company | `sync_phone_field` / `sync_company_field` (must name a real field on the user template) |

### Custom launcher icon

When `custom_launcher_enabled` is on and `custom_launcher_icon` holds a direct image URL, the module hides Crisp's default bubble and builds its own round button (positioned per `widget_position`) showing that icon — no template markup is needed on the site's side:

```text
custom_launcher_enabled = 1
custom_launcher_icon = https://example.com/chat-icon.png
```

Do not enable `custom_launcher_enabled` with an empty/broken `custom_launcher_icon` URL — the default bubble is still hidden even if the icon fails to load, leaving visitors with no way to open the chat.

## Safe Operations

Agents may normally do these after checking current site config:

- explain Crisp's capabilities and configuration options;
- read module configuration and README;
- set/update `website_id` and `website_verify` from values the user provides directly;
- adjust template visibility, position, color, auto-open and locale settings;
- set `custom_launcher_icon` to an image URL the user provides when they want a non-default icon;
- add small, additive config documentation.

## Requires Explicit Approval

Ask before:

- enabling `sync_phone_field` / `sync_company_field` / `sync_page_data` — these send additional visitor/user data (PII, browsing context) to a third-party service (Crisp); confirm the user understands what leaves the site;
- enabling `website_verify` (identity verification) — the HMAC secret must be treated like a credential and never logged or exposed client-side;
- changing `logged_in_only` or `hidden_roles` on a live site with real traffic;
- restricting `templates_mode` in a way that could hide the widget from pages where visitors currently rely on it (e.g. checkout, support pages).

## High Risk Or Destructive

Treat these as high risk and require a clear user request plus a rollback plan:

- modifying `handleCrispCallback()`'s CSRF check or removing the token verification — this is what prevents an attacker from silently repointing the widget (and visitor identity data) at a different Crisp website;
- changing how values are escaped into the inline `<script>` block (`jsonJs()`) — this is the fix for a prior stored-XSS issue where `website_id` was interpolated unescaped; any new dynamic value written into the script must go through `jsonJs()`, not raw string interpolation;
- removing the `Session::logout` → `session:reset` flow without a replacement — this exists to stop chat history leaking between users on shared devices.

## Common Mistakes To Avoid

- Do not interpolate any dynamic value into the inline `<script>` template without `jsonJs()` (or `json_encode` with the same `JSON_HEX_*` flags) — raw interpolation is how the module's prior XSS bug happened.
- Do not assume `sync_phone_field`/`sync_company_field` do anything if the named field doesn't exist on the user template — `syncUser()` checks `$user->hasField()` and silently skips otherwise; verify the field name against the actual user template.
- Do not assume the widget renders on `admin` template pages — `ready()` explicitly skips them.
- Do not attach `injectCrispScript` globally on `Page::render` again — it is intentionally hooked per-page-instance in `ready()` to avoid duplicate injection on nested renders (e.g. PageTable).
- Do not remove the `$this->injected` guard in `injectCrispScript()` — it prevents the script being inserted twice if `render` fires more than once for the same request.
- Do not treat this AGENTS.md as proof the module is installed anywhere — always check the live module list.

## Layer Map

- `Crisp.module.php`: the entire module — install/config, hooks, script injection, all business logic. There is no separate repository/API/admin-process file.
- `README.md`: human-facing purpose, installation and configuration reference.
- `LICENSE`: MIT.

## Change Risk

- Low risk: README/config-description copy, adding new non-default-on config fields with safe defaults.
- Medium risk: changes to visibility filtering (`ready()`), locale/appearance rendering.
- High risk: anything touching `handleCrispCallback()` (CSRF/OAuth), `jsonJs()`/escaping, or the `Session::logout` reset flow.

For medium and high risk work, move in this order:

1. Config schema (`getDefaultConfig()`).
2. Hook/filter logic (`ready()`, `injectCrispScript()`).
3. Config UI (`getModuleConfigInputfields()`).
4. README.

## Verification

```bash
php -l Crisp.module.php
```

There is no test suite or build step. For behavior changes, manually verify in a browser:

- widget appears/disappears correctly for the configured template + role + login-state rules;
- inline script has no unescaped dynamic values (check page source, not just behavior);
- if `custom_launcher_enabled` is on, the default bubble is hidden and the module's own icon button opens the chat;
- log out while logged in with an active chat session and confirm the next page load does not show the previous conversation.

## Version And Changelog

When changing module behavior or agent-facing guidance, bump the version consistently in both places inside `Crisp.module.php`:

- the `@version` docblock at the top of the file;
- `version` in `getModuleInfo()`.

Use patch versions for documentation and small fixes. Use minor versions for new config options/capabilities. Use major versions for breaking changes (e.g. removing a config key, changing escaping behavior in a way that changes rendered output).

## Handoff

Finish with a short report:

- what changed;
- what was verified (and how, since there is no automated test suite);
- known risks or limitations;
- any config the user still needs to set in the Admin UI for the change to take effect.
