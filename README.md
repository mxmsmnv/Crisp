# Crisp Live Chat — ProcessWire Module

Integrates the [Crisp](https://crisp.chat) live chat widget into your ProcessWire site.

---

## Requirements

- ProcessWire 3.0 or higher
- PHP 8.2 or higher
- A [Crisp](https://crisp.chat) account (free tier supported)

---

## Installation

1. Copy `Crisp.module.php` into `/site/modules/Crisp/`
2. Go to **Modules → Refresh** in the ProcessWire admin panel
3. Find the **Crisp** module and click **Install**

---

## Configuration

### Basic Settings

- **Website ID** — your site identifier from the Crisp dashboard
- **User Verification Secret** *(optional)* — secret key for HMAC user verification.
  Found in **Crisp → Settings → Workspace Settings → Advanced configuration →
  Identity Verification**. Requires a paid plan (Mini or higher).
  Leave empty if not used.

---

### Widget Visibility

#### Template-based Visibility

Control on which ProcessWire templates the Crisp widget appears:

| Mode | Description |
|---|---|
| **All templates** | Widget is shown on all pages |
| **Include selected** | Widget is shown only on selected templates |
| **Exclude selected** | Widget is hidden on selected templates |

#### Visibility Rules

- **Logged-in users only** — widget is shown exclusively to logged-in
  ProcessWire users
- **Hidden roles** — hide the widget from users with specific roles

---

### Widget Appearance

- **Widget position** — set the widget position: `bottom_right` (default)
  or `bottom_left`
- **Widget color** — set the widget color theme using a Crisp color name
  (e.g. `pink`, `blue`, `green`)

---

### Chat Behaviour

- **Auto-open on page load** — automatically opens the chat window when
  the page loads

---

### Locale / Language

The module supports three language detection modes:

| Mode | Description |
|---|---|
| **Auto** | Detected from ProcessWire's multi-language system, with fallback to PHP system locale |
| **Browser default** | Crisp detects the language from the visitor's browser settings |
| **Force** | Forces a specific language for all visitors |

When using **Force** mode, provide an ISO language code (e.g. `en`, `fr`, `de`,
`es`, `ru`, `zh`).  
Full list of supported codes: https://docs.crisp.chat/guides/chatbox-sdks/web-sdk/language/

---

### User Data Sync

When a ProcessWire user is logged in, their **email** and **name** are
automatically synced with the Crisp widget. If a User Verification Secret
is configured, HMAC verification is applied automatically.

---

### OAuth Callback Handling

When Crisp redirects back after OAuth, the module automatically reads
`crisp_website_id` and `crisp_verify` GET parameters, saves them to the
module configuration, and redirects to a clean URL.

---

## Links

- [Crisp](https://crisp.chat)
- [Crisp Web SDK Docs](https://docs.crisp.chat/guides/chatbox-sdks/web-sdk/)

## Author

[Maxim Alex](https://github.com/mxmsmnv)  


---

## License

MIT License. See [LICENSE](LICENSE) for details.
