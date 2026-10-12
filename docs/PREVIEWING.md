# Looking at a skin without cutting a release

A merge to `main` is not a release (a release is a tag, see [RELEASING.md](RELEASING.md)), so
there are three ways to see new work before one exists.

## 1. The browser harness (no LibreNMS needed)

From the repo root:

```bash
python -m http.server 8777
```

Open `http://localhost:8777/harness/mockup.html?skin=<id>` (for example `?skin=paisley-dawn` or
`?skin=paisley-velvet`). Add `&mode=dark` or `&mode=light` to see a skin in the other mode. Needs
`harness/css/` vendored once (`harness/sync-css.sh /opt/librenms`).

## 2. A LibreNMS host that follows `main`

Staging boxes only. Production should follow releases (`'^1.0'`).

```bash
./lnms plugin:add xblossia/librenms-theme-selector dev-main
./lnms migrate --force
./lnms theme-selector:publish
./lnms theme-selector:status
```

`scripts/update.sh dev-main` does all four (not as root). Then **Plugins → Theme Selector**: pick
the skin for light and for dark mode, which previews before anything is applied.

Back to releases:

```bash
./lnms plugin:add xblossia/librenms-theme-selector '^1.0'
```

## 3. The local dev stack

Runs in WSL (Debian), with the repo mounted read-only, so edits show on the next request:

```bash
docker compose -f dev/compose.yml up -d      # then http://localhost:8000
sh dev/test.sh                               # sealed unit tests
sh dev/test.sh live                          # end to end against the running stack
```

If a new bundled skin does not show up, `docker exec theme-selector-dev-librenms-1 lnms theme-selector:publish`.
See [dev/README.md](../dev/README.md).
