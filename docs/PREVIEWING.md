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

### Contrast fixtures

Open `http://localhost:8777/harness/contrast.html?mode=dark` for Clock Tower Daylight's banners
(including links), badges, ordinary/placeholder/read-only/disabled inputs, validation messages and
addons, striped/contextual tables, and a real RRDtool graph rendered from synthetic traffic. Add
`&skin=clock-tower-lantern` or `&skin=digital-rain` to check the same shared styles with a dark palette.
Use `mode=light` for the generated light base, tab through the controls, and hover the table rows.
The fixture changes no account settings and uses only local assets.

`tests/ContrastTest.php` resolves the bundled Daylight colour pairs (including `var()` and the sRGB
mixes used by the base) and requires 4.5:1 for text and 3:1 for graph outlines. It also pins the shared
banner-link and validation selectors in both modes. This is a targeted regression check, not a complete
CSS engine or a claim that every possible skin or page is accessible; verify the browser cascade too.

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
