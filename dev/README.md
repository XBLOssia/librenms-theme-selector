# Dev instance

A throwaway LibreNMS in Docker, with this repo installed as a package plugin.
Not for production.

- Image `librenms/librenms:26.9.1.1`, the first release containing `63e0394`
  (the production host's commit).
- The repo is bind-mounted **read-only** at `/plugin` and installed via a Composer path
  repository with symlinks, so edits to `src/`, `resources/` and `skins/` show
  on the next request. There's no rebuild; OPcache revalidates on every request.
- State lives in Docker named volumes. `docker compose -f dev/compose.yml down -v`
  gives a fresh instance.

## One-time: Docker in WSL

Docker runs inside the WSL Debian distro, not Docker Desktop. Run in Debian:

```bash
sudo apt-get update && sudo apt-get install -y docker.io docker-compose docker-buildx
sudo usermod -aG docker "$USER"
```

Then from Windows, restart the distro so the group change applies:

```bash
wsl --terminate Debian
```

## Run

From the repo root, inside WSL (`/mnt/c/Users/<you>/LibreNMS Theme`):

```bash
docker compose -f dev/compose.yml up -d --build
```

First start takes a few minutes: migrations, then `lnms plugin:add`. Follow it
with `docker compose -f dev/compose.yml logs -f librenms` and wait for
`[theme-selector] users`. Then open http://localhost:8000.

## Login

There are no passwords. The instance uses LibreNMS's `http-auth` mechanism, and
nginx supplies the username from an `X-Dev-User` request header:

| Who | How |
|---|---|
| `dev-admin` (admin) | any request with no header, i.e. the browser |
| `dev-user` (user) | `curl -H 'X-Dev-User: dev-user' http://localhost:8000/...` |

A session sticks to the user it started as. To switch in the browser, clear
the `librenms_session` cookie. This is why port 8000 is published on
`127.0.0.1` only.

## Testing per-user graph colours

```bash
docker exec theme-selector-dev-librenms-1 sh /plugin/dev/test-graphs.sh
```

Builds a dummy device, port and synthetic RRD, then checks that users with
different skins get different graphs, that an explicit "stock" choice stays
stock under any default, and that nothing leaks into the persistent config.

## Tests

```bash
sh dev/test.sh            # PHP lint, unit tests (1,085 checks), token catalog check
sh dev/test.sh mutate     # break each defence in turn; every one must be caught
sh dev/test.sh live       # end to end against this instance: the core patch tooling, graphs, then uploads
sh dev/test.sh all
```

Run from WSL/Linux with Docker. **The unit and mutation runs are sealed**: a
throwaway container with the repository mounted read-only, a read-only root
filesystem and a RAM-only `/tmp`. They include hostile archives and code that
is deliberately broken, so they must never be able to reach the repository. (A
mutation run once followed a symlink to `/` in the old, writable setup and
deleted a bind-mounted copy of this repository, so don't loosen this.) The live
tests need this stack up; its container mounts the repository read-only as well.

**Speed.** `all` takes about two and a half minutes: unit first, then the mutation
check and the live suites at the same time (the mutation check is sealed and never
touches this stack, so they don't interfere; its output is held and printed after the
live output). The mutation check runs its mutations eight at a time
(`MUTATE_JOBS=12 sh dev/test.sh mutate` for more). The upload route is rate limited
per user to 12 a minute; `test-upload.sh` spreads its uploads over seven extra admins
it creates (`dev-up1` to `dev-up7`), each with a bucket of its own, instead of sleeping
out the window, and only the test of the limit itself bursts one admin. The two live
suites can't run side by side: they share the database, the default skin, the graph
colours and the skins directory.

## The port-graph change to LibreNMS, as a diff and as a test

`dev/port-colours-diff.py` prints the change (two files: the six series lines of
`generic_data.inc.php` read `graph_colours.port_in.0..2` and `.port_out.0..2`, and the
two palettes are declared in `config_definitions.json` with the old literals as their
defaults) against any LibreNMS checkout. It never writes inside the checkout.

```bash
python dev/port-colours-diff.py /path/to/librenms                 # the diff
python dev/port-colours-diff.py /path/to/librenms --out /tmp/new  # also write the two changed files
sh dev/test-port-colours.sh                                       # prove it on the dev stack (WSL/Linux)
python scripts/helper-audit.py /path/to/librenms                  # which helpers hard-code what
```

`test-port-colours.sh` swaps the changed files into the dev container, draws `port_bits`
over a fixed window before and after, checks the hashes are equal with default config,
that a configured palette changes the graph, and restores the originals.

**Checking that a graph is unchanged, on any install.** Open a port graph in the
browser, copy its request as cURL (developer tools, Network), pin `from=` and `to=` in the
URL to fixed numbers so the data window cannot move, and hash the response before and
after the change:

```bash
curl -s '<the copied URL, with from= and to= pinned>' -H 'Cookie: ...' | sha256sum
```

Equal hashes with the same bytes means the change draws exactly what it drew before.

## Resetting the plugin install

`vendor/` is part of the container, not a volume. Recreating the container
(`up -d --build`, `down` then `up`) reinstalls the plugin from scratch, which
is the path a real `lnms plugin:add` takes. A plain `restart` keeps it.
