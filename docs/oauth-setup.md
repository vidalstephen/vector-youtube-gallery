# OAuth setup for Vector YouTube Gallery

Phase 7 adds OAuth as an optional alternative to public API-key mode. API-key mode remains supported and is still the simplest path for public channel/playlist galleries. OAuth is intended for operators who need channel-owner authorization, private/unlisted access where allowed by YouTube Data API policy, or revocable account-level connection state.

## Security model

- Never paste OAuth client secrets, refresh tokens, access tokens, or passwords into chat, issue trackers, logs, screenshots, or support tickets.
- Client secrets and tokens are stored with `autoload=no` in `wp_options` so they do not load on every front-end request.
- Token material is sealed before storage using the WordPress salt material available to the site.
- Admin UI and diagnostics may show status, expiry, scopes, connected account/channel, and masked client ID only.
- Admin UI and diagnostics must never display raw access tokens, refresh tokens, client secrets, or authorization codes.
- Disconnect must revoke/delete OAuth material without deleting locally cached YouTube metadata unless the separate clean-uninstall toggle is enabled.

## Google Cloud Console prerequisites

1. Open Google Cloud Console → APIs & Services.
2. Create or select a project for the site.
3. Enable **YouTube Data API v3**.
4. Configure the OAuth consent screen:
   - App name: your site or organization name.
   - User support email: an operator-controlled email.
   - Developer contact email: an operator-controlled email.
   - Publishing status: Testing is acceptable for local/dev sites; Production requires Google review for broader use.
5. Create OAuth Client ID credentials:
   - Application type: **Web application**.
   - Name: e.g. `Vector YouTube Gallery — example.com`.
   - Authorized redirect URI: copy the exact URI shown in the plugin OAuth settings tab.

## Redirect URI shape

The plugin callback endpoint should be treated as exact-match. For local Docker development the redirect URI will look like:

```text
http://localhost:8000/wp-admin/admin-post.php?action=vyg_oauth_callback
```

For production it will look like:

```text
https://example.com/wp-admin/admin-post.php?action=vyg_oauth_callback
```

Google requires the scheme, host, path, and query string to match the configured redirect URI.

## Requested scopes

Phase 7 should start with the narrowest read-only scope that supports the plugin’s needs:

```text
https://www.googleapis.com/auth/youtube.readonly
```

Do not request upload, write, or account-management scopes unless a later phase explicitly adds a feature that requires them.

## Local development caveats

- Google OAuth will not redirect to arbitrary private container hostnames such as `http://vyg-wp`.
- Use the externally reachable WordPress URL for the OAuth redirect (`http://localhost:8000` locally, real HTTPS domain in production).
- Camofox screenshots may temporarily set `siteurl/home=http://vyg-wp` for browser routing; restore them before testing real OAuth redirects.
- Live OAuth E2E is blocked until the operator provisions client ID/secret out-of-band.

## Phase 7 implementation order

1. Document prerequisites and exact redirect URI.
2. Add token/client-config storage with sealed values and `autoload=no`.
3. Add OAuth client that can build authorization URL and exchange/refresh tokens.
4. Add settings UI for connect/reconnect/disconnect.
5. Add callback handler with nonce/state validation.
6. Add diagnostics and disconnect integration.
7. Capture Camofox screenshots of the OAuth settings UI. Live Google authorization remains blocked until credentials exist.
