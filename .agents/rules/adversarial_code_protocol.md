# Multi-Vector Code Resilience & Drawback Protocol

## 1. Adversarial & Drawback Thinking (Pre-Mortem Analysis)
Before writing or modifying any feature:
- Identify at least 3 failure modes (e.g. network timeout, expired third-party tokens, race conditions, malicious input, large file upload).
- Implement defensive programming: never assume external APIs (Meta, WhatsApp, Google) always return 200 OK.
- Use explicit timeouts on all cURL / HTTP requests (e.g. `CURLOPT_TIMEOUT => 15`).
- Never fail silently: return structured, traceable JSON responses with sanitized error codes.

## 2. Lean & Anti-Bloat Code Policy
- Keep code lightweight, modular, and maintainable.
- Do NOT write monolithic 1000-line functions. Divide logic into focused, single-purpose methods.
- Zero redundant external libraries: prioritize native PHP (PDO, cURL, GD) and clean vanilla JS/CSS.
- Prevent server storage exhaustion: store third-party media IDs/CDN links instead of heavy local files. Always auto-purge temporary upload buffers immediately upon successful transmission.

## 3. Strict Security & Tenant Isolation
- Multi-Tenant Boundary: Every SQL query touching tenant data MUST enforce `tenant_id = ?` or `user_id = ?`.
- Zero raw string concatenation in SQL queries: 100% Prepared Statements.
- Strict Upload Validation: Verify MIME types and magic bytes, never rely on file extension alone. Disallow executable scripts (.php, .phtml, .sh, .exe).
- Cross-Site Scripting (XSS) Prevention: All dynamic outputs rendered into HTML must pass through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

## 4. Professional UI Guidelines
- NO EMOJIS: Never use emojis in user interfaces, buttons, badges, tables, or system messages.
- Use clean, vector SVG icons (Lucide or FontAwesome) with professional enterprise typography (Inter, Outfit).
- Match the MargLead enterprise design language with proper dark/light theme support.
