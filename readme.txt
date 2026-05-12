=== AI Chat Plugin ===
Contributors:      ai-chat-plugin
Tags:              chatbot, chat, widget, AI, customer support
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A customizable website chatbot widget powered by an external backend API.

== Description ==

AI Chat Plugin lets you add a fully branded, floating chat bubble to any WordPress site.
It connects to your own backend chatbot API and supports a secure **proxy mode** so your
backend URL is never exposed to visitors.

= Key features =

* 🔒 **Proxy mode** (default) — messages are routed through WordPress, backend URL stays private
* 🎨 **Full branding** — company name, logo, colors, subtitle, disclaimer, welcome message
* 📱 **Responsive** — full-screen on mobile, elegant panel on desktop
* ♿ **Accessible** — ARIA roles, keyboard navigation, ESC to close
* 🌍 **RTL-ready** — CSS structure supports right-to-left languages
* ⚡ **Lightweight** — no jQuery or framework on the frontend (vanilla JS)
* 🛡️ **Security-first** — nonce verification, rate limiting, SVG sanitization, strict output escaping
* 🔧 **Developer hooks** — filters to override payload, response, and JS config

= Minimum Requirements =

* WordPress 6.0+
* PHP 8.0+

== Installation ==

1. Upload the `ai-chat-plugin` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **AI Chat → Settings** and set your backend URL.
4. Customize branding, colors and display rules.
5. Save. The chat bubble will now appear on your site.

== Frequently Asked Questions ==

= What backend format is required? =

The backend must implement:

* `POST {url}/chat` — accepts `{ sessionId, message, channel }`, returns `{ sessionId, text, channel }`
* `GET {url}/chat/health` — returns `{ status: "UP", ... }`

= What is proxy mode? =

In proxy mode (default), the browser sends messages to a WordPress REST endpoint
(`/wp-json/ai-chat/v1/chat`) which forwards them to your backend. The backend URL
is never exposed to visitors.

= Can I add the widget to specific pages only? =

Yes. Under **Display Rules**, choose "Selected pages" and enter the page/post IDs.

= Can I embed the widget inline? =

Use the shortcode `[ai_chat_widget]` to insert a trigger button anywhere in your content.

= Is there a dark mode? =

Use the **Custom CSS** field to override the CSS custom properties (`--ai-chat-bg`, etc.).

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
