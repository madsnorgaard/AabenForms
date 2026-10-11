# Keycloak (mock MitID identity provider)

Public URL: https://auth.aabenforms.dk - realm `danish-gov-test`, imported from
`realms/danish-gov-test.json` on first start. Login theme `themes/aabenforms-mitid`.

## Runtime (since Keycloak 26)

- Image `quay.io/keycloak/keycloak:26.x`, **production mode** (`start`), TLS at Traefik
  (`KC_HTTP_ENABLED=true`, `KC_PROXY_HEADERS=xforwarded`).
- Database: the `keycloak_db` Postgres container (volume `keycloak_db_data`),
  password `KC_DB_PASSWORD` in `.env`. Keycloak majors are now ordinary schema
  migrations; the old `start-dev` H2 file store could not be upgraded at all.
- Hostname v2: `KC_HOSTNAME=https://auth.aabenforms.dk` fixes the public URL;
  `KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true` keeps the internal endpoints Drupal calls
  (`http://keycloak:8080/...`) working. **Tokens always carry the public issuer**
  `https://auth.aabenforms.dk/realms/danish-gov-test`, so that value must be in
  `aabenforms_mitid.settings:accepted_issuers` (it is, in config/sync).
- Bootstrap admin: `KC_BOOTSTRAP_ADMIN_USERNAME/PASSWORD` map to the existing
  `KEYCLOAK_ADMIN` / `KEYCLOAK_ADMIN_PASSWORD` in `.env`; only used on an empty database.

## One source for the theme and the realm

- `themes/aabenforms-mitid` is the only copy of the DKFDS login theme. DDEV
  mounts it too (`.ddev/docker-compose.mocks.yaml`), so local and production
  cannot drift. Production Keycloak caches themes: after a theme change is
  deployed, run `docker compose restart keycloak`.
- `realms/danish-gov-test.json` mirrors the live production realm: the five
  personas (incl. birthe.jensen, mikkel.jensen's `fuldmagt`), all claim mappers
  (incl. `digital_post_exempt`, `fuldmagt`), the post-logout redirect URIs and
  the user profile with `unmanagedAttributePolicy: ENABLED`. Without that
  policy Keycloak 24+ silently drops the personas' `cpr` and other attributes.
  The live realm is managed by hand; keep this file in step with it.

## Re-import the realm after editing the JSON

`--import-realm` only imports into an empty database:

    docker compose down keycloak keycloak_db
    docker volume rm apiaabenformsdk_keycloak_db_data
    docker compose up -d

## Upgrading Keycloak

Minor/patch bumps (26.x): merge, deploy, done. Majors: read the upgrading guide first,
then bump the tag; Dependabot ignores Keycloak and Postgres majors on purpose.

## Verified on 2026-09-17 (local run of 26.7.4 on Postgres with this realm and theme)

Realm imported, themed login page renders, authorization-code flow with PKCE for
`aabenforms-backend` succeeds, id_token has `acr=http://eidas.europa.eu/LoA/substantial`,
the `ssn` claim and the four address claims, userinfo answers on the back channel.
