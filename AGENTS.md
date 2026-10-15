# MargLead Agent Development Guidelines

## Prime Directives
1. **Drawback Thinking:** Always evaluate edge-cases, failure vectors, rate limits, and network dropouts before writing implementation code.
2. **Lean & Modular Code:** Write compact, well-structured, single-responsibility functions. Avoid code bloat or unnecessary dependencies.
3. **Strict Data Isolation:** Guarantee multi-tenant safety with parameterized queries and explicit `tenant_id` / `user_id` scopes.
4. **Zero-Storage Media Policy:** Utilize provider CDNs (Meta Graph CDN) and purge temporary media uploads immediately after API delivery.
5. **UI Standard:** Zero emojis. Clean, corporate enterprise UI utilizing Lucide vector icons, subtle borders, and harmonious theme palettes.
