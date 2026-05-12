# AI Chat Plugin for WordPress

A production-ready WordPress plugin that adds a fully customizable chatbot widget to any site, powered by your own external backend API.

---

## Features

| Feature | Details |
|---------|---------|
| 🔒 **Proxy mode** | WordPress proxies messages to your backend — the backend URL is never exposed to visitors |
| 🎨 **Full branding** | Company name, logo, colors, subtitle, disclaimer, welcome message, media-based logo/SVG icon selection — all configurable without code |
| 📱 **Responsive** | Full-screen modal on mobile, floating panel on desktop |
| ♿ **Accessible** | ARIA roles, keyboard navigation (Enter to send, Shift+Enter for newline, ESC to close), focus management |
| 🌍 **RTL-ready** | CSS structure supports right-to-left languages |
| ⚡ **Lightweight** | Vanilla JS — no jQuery or framework on the frontend |
| 🛡️ **Security-first** | Nonce verification, per-IP rate limiting, strict SVG sanitization, output escaping throughout |
| 🔧 **Developer hooks** | Filters to modify outgoing payload, incoming response, and the JS config |

---

## Requirements

- **WordPress** 6.0 or later  
- **PHP** 8.0 or later

---

## Installation

1. Download or clone this repository.
2. Upload the `ai-chat-plugin` folder to `/wp-content/plugins/`.
3. Activate via **Plugins → Installed Plugins**.
4. Go to **AI Chat** in the WordPress admin sidebar.
5. Set your **Backend Base URL** and save.

---

## Backend API Contract

The plugin expects the following endpoints on your backend:

### Chat — `POST {BACKEND_URL}/chat`

**Request body:**
```json
{
  "sessionId": "uuid-v4-string",
  "message":   "User message text",
  "channel":   "chat"
}
```

**Response body:**
```json
{
  "sessionId": "uuid-v4-string",
  "text":      "Assistant reply",
  "channel":   "chat"
}
```

### Health check — `GET {BACKEND_URL}/chat/health`

**Typical response:**
```json
{
  "status":    "UP",
  "timestamp": "2026-05-12T00:00:00.000Z",
  "services":  { "api": "UP", "redis": "UP" }
}
```

---

## Admin Settings

Navigate to **AI Chat → Settings** and configure each tab:

| Tab | Settings |
|-----|---------|
| **Backend** | Base URL, connection mode (proxy/direct), test-connection button |
| **Branding** | Company name, subtitle, logo (URL or media library), bubble icon (media SVG or inline SVG), welcome message, disclaimer, online-status label |
| **Appearance** | Primary color, secondary/accent color, widget background, text color, bubble position, border radius, bubble size, bubble shape, bubble border color/width, custom CSS |
| **Display Rules** | Global / selected pages / shortcode-only mode; comma-separated page/post IDs |
| **Advanced** | Store transcript in localStorage, request timeout, rate limit, debug mode |

---

## Connection Modes

### Proxy (recommended, default)

```
Browser → POST /wp-json/ai-chat/v1/chat → WordPress → POST {backend}/chat → Backend
```

- Backend URL is **never** sent to the browser.
- Requests are verified with a WordPress nonce (`ai_chat_proxy`).
- Rate-limited per visitor IP using WordPress transients.

### Direct

```
Browser → POST {BACKEND_URL}/chat
```

- Requires CORS to be enabled on the backend.
- Backend URL is exposed to the browser — only use if the backend is intentionally public.

---

## Shortcode

Place the chatbot trigger anywhere in page content:

```
[ai_chat_widget]
```

This renders a trigger button that opens the floating chat panel when clicked.

---

## Plugin Structure

```
ai-chat-plugin/
├── ai-chat-plugin.php           # Entry point — constants, requires, activation hooks
├── includes/
│   ├── class-plugin.php         # Singleton bootstrap
│   ├── class-admin-settings.php # Tabbed admin settings page
│   ├── class-assets.php         # Script/style enqueuer + JS config builder
│   ├── class-frontend.php       # wp_footer widget + shortcode
│   ├── class-rest-controller.php# Proxy REST endpoint + rate limiting
│   ├── class-api-client.php     # wp_remote_post HTTP client
│   ├── class-session-manager.php# UUID generation & validation
│   └── class-sanitizer.php      # Input sanitization + defaults
├── assets/
│   ├── css/
│   │   ├── chat-widget.css      # Widget styles (CSS variables, responsive, RTL)
│   │   └── admin.css            # Admin page styles
│   └── js/
│       ├── chat-widget.js       # Vanilla JS widget (session, API, UI, a11y)
│       └── admin.js             # Color pickers + test-connection AJAX
├── templates/
│   └── chat-widget.php          # wp_footer mount point
├── languages/                   # i18n .pot files go here
├── uninstall.php                # Deletes all plugin data on removal
├── readme.txt                   # WordPress.org readme
└── README.md                    # This file
```

---

## Developer Hooks

### Filters

| Filter | Description |
|--------|-------------|
| `ai_chat_proxy_payload` | Modify the payload before it is sent to the backend. Args: `array $payload`, `WP_REST_Request $request` |
| `ai_chat_proxy_response` | Modify the backend response before it is returned to the browser. Args: `array $data`, `WP_REST_Request $request` |
| `ai_chat_js_config` | Modify the JavaScript config object passed to the frontend. Args: `array $config`, `array $settings` |
| `ai_chat_api_request_args` | Modify `wp_remote_post` args for outgoing backend requests. Args: `array $args`, `string $path`, `string $base_url` |

### Example

```php
// Add a custom header to every backend request.
add_filter( 'ai_chat_api_request_args', function ( array $args ): array {
    $args['headers']['X-My-Header'] = 'value';
    return $args;
} );

// Append context to every message before forwarding.
add_filter( 'ai_chat_proxy_payload', function ( array $payload ): array {
    $payload['siteId'] = get_current_blog_id();
    return $payload;
} );
```

---

## Security

- All admin inputs are sanitized via `AI_Chat_Sanitizer::sanitize_settings()`.
- All HTML output uses `esc_html()`, `esc_attr()`, `esc_url()`, and `wp_kses()`.
- SVG input is parsed with DOMDocument and rebuilt from an allowlist of safe elements/attributes — scripts and event handlers are stripped.
- The proxy REST endpoint requires a short-lived nonce (`ai_chat_proxy`) and enforces per-IP rate limiting.
- The backend URL is never sent to the browser in proxy mode.
- The JS widget escapes all text before inserting it into the DOM (`textContent` / custom `escHtml()`).

---

## Changelog

### 1.0.0
- Initial release.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
