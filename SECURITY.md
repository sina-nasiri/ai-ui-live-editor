# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub's ["Report a vulnerability"][advisory] form
on this repository. If that is unavailable, email the maintainer listed on the
GitHub profile.

Please include what you can: what you did, what happened, and what you expected.
A proof of concept helps a lot. You will get an acknowledgement within a few
days, and credit in the fix unless you would rather not have it.

[advisory]: https://github.com/sina-nasiri/ai-ui-live-editor/security/advisories/new

## What this project's threat model actually is

This tool fetches arbitrary URLs on your behalf and renders them same-origin.
That is inherently a sensitive combination, so it is worth being explicit about
what is defended and what is not.

### Defended

| Risk | Control |
|---|---|
| **Server-side request forgery** | `app/Support/UrlGuard.php` allows only `http`/`https`, resolves the hostname, and refuses every private, loopback, link-local, CGNAT, and reserved range — including IPv4-mapped IPv6 addresses. **Every redirect hop is re-checked**, so a public host cannot bounce the fetch into your network. |
| **Cloud metadata access** | `169.254.0.0/16` is blocked by the same guard. There is a test asserting this specifically. |
| **Script execution from a loaded page** | `app/Support/PageSnapshot.php` strips `<script>`, `<iframe>`, `<object>`, every `on*` handler, and `javascript:` URLs before the snapshot reaches the browser. The preview is same-origin by necessity, so this is the control that keeps a loaded site from reading your storage. |
| **Model output** | AI responses are treated as untrusted input. Element ids are pattern-matched, CSS declarations are filtered by `CssGuard`, and any returned HTML goes through `HtmlFragment::sanitize()`. |
| **Abuse of an open instance** | `/proxy` and `/ai/*` are rate limited. Response size is capped. Redirects are capped. |
| **Transport** | TLS verification is on by default and configurable, not hardcoded off. |
| **Key leakage via error pages** | `api_key` is in `dontFlash`, and `.env.example` ships `APP_DEBUG=false`. |

### Not defended — know before you deploy

- **A public instance is an open fetcher.** Anyone who can reach it can make
  your server fetch any public URL. If you expose one, set `EDITOR_ALLOWED_HOSTS`
  to an allow-list, keep the rate limits, and put it behind authentication.
- **Browser-supplied API keys pass through your server.** They are used for the
  one request and never stored, but a hostile operator of a *hosted* instance
  could log them. On a shared instance, use a key you can rotate. Self-hosting
  with `ANTHROPIC_API_KEY` in `.env` avoids this entirely.
- **`EDITOR_ALLOW_PRIVATE_NETWORKS=true` disables the SSRF guard.** It exists so
  you can edit `http://localhost:3000`. Never set it on a machine that other
  people can reach.
- **Do not paste a production API key into a hosted instance you do not run.**

## Supported versions

The `main` branch is what receives fixes.
