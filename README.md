# Crisp

Crisp adds the [Crisp](https://crisp.chat) live chat widget to a ProcessWire site, with automatic identity sync for logged-in users and full control over where, when and how the widget appears.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Crisp Does

- Injects the Crisp chat widget on front-end pages, with a one-click "connect via Crisp" setup flow.
- Syncs the logged-in ProcessWire user's email and nickname with Crisp, with optional HMAC identity verification.
- Optionally syncs phone and company from any ProcessWire user field.
- Controls widget visibility by template (all, only selected, or all except selected), login state, and role.
- Controls widget position, accent color, auto-open behavior, and chat language (auto-detected, browser default, or forced).
- Supports a custom launcher icon — hides Crisp's default bubble and shows your own icon (by URL) instead, no markup required.
- Tags the Crisp session with the user's roles and current page context, so operators see who they're talking to.
- Resets the chat session on logout, so a shared device never shows one visitor's history to the next.

## Configuration

Everything is configured from **Admin → Modules → Configure → Crisp Live Chat**, organized into tabs:

- **Connection** — connection status, Website ID, User Verification Secret — from your Crisp dashboard, or filled in automatically via the "connect via Crisp" link.
- **Visibility** — template visibility (all / only selected / all except selected), login-only restriction, and hidden roles.
- **Appearance** — position, accent color, auto-open, custom launcher icon URL, and chat language (auto-detect, browser default, or forced ISO code).
- **Session & Data** — tag sessions with roles, send page context, sync phone/company fields.

## Installation

1. Copy the `Crisp` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules.
3. Install `Crisp`.
4. Open **Modules → Configure → Crisp Live Chat** and paste your Website ID, or connect via the in-admin link.

## Documentation

See [Crisp's Web SDK docs](https://docs.crisp.chat/guides/chatbox-sdks/web-sdk/) for background on the underlying widget behavior.

See [AGENTS.md](AGENTS.md) for AI-agent usage and safety guidance.

## Author

Maxim Semenov  
[smnv.org](https://smnv.org)  
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT
