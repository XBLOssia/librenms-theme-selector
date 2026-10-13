# Contributing

Thanks for wanting to help. Bug reports, skins, fixes and documentation are all welcome. By taking part you
agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Reporting a bug or asking for something

Open an [issue](https://github.com/XBLOssia/librenms-theme-selector/issues/new/choose); the forms ask for what
is needed (the plugin and LibreNMS versions, the skin and mode, what you saw and what you expected). **Do not
report a security problem in an issue.** Use a [private advisory](https://github.com/XBLOssia/librenms-theme-selector/security/advisories/new)
instead; [docs/SECURITY.md](docs/SECURITY.md) says what is in scope.

## Sharing a skin

A skin is a validated `.zip` an administrator uploads. [docs/AUTHORING.md](docs/AUTHORING.md) is the format, and
[examples/](examples/) has complete ones to start from (`corpo-blue` has fonts and a texture). Fonts need a
licence that lets them be redistributed, and the bundle's `LICENSE.txt` should say so. To have a skin shipped
with the plugin rather than only shared, open an issue first so we can agree it fits.

## Changing the code

1. Open an issue first for anything bigger than a fix, so the effort isn't wasted.
2. Branch from `main` and open a pull request. `main` is protected: nothing is pushed to it directly.
3. **Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md)**, in the section it belongs to. Release
   notes are made from it, and [docs/RELEASING.md](docs/RELEASING.md) explains how a version is cut.
4. Update the docs that describe what you changed. [docs/TOKENS.md](docs/TOKENS.md) is generated; the unit
   tests check it, so regenerate it rather than editing it by hand.
5. Run the tests (below). The pull request must pass `unit (PHP 8.2)`, `unit (PHP 8.4)` and `mutation check`
   before it can merge.

### Running the tests

The suites include deliberately hostile inputs, so they run in a sealed throwaway container (the repository is
mounted read-only). You need Docker, on Linux or in WSL:

```sh
sh dev/test.sh           # unit tests, PHP lint, token catalog and TOKENS.md checks
sh dev/test.sh mutate    # break each defence in turn; each must be caught
sh dev/test.sh live      # end to end against the dev instance (see dev/README.md)
```

[dev/README.md](dev/README.md) covers the Docker dev instance and [docs/PREVIEWING.md](docs/PREVIEWING.md) the
ways to look at a skin without installing it. A change to a defence in the upload path needs a test, and
usually a mutation, so that breaking it again is caught; see [docs/SECURITY.md](docs/SECURITY.md).

### Style

Match what is around you. CSS is written against the tokens in [docs/TOKENS.md](docs/TOKENS.md), not against
LibreNMS's own class names where a token exists. Keep a pull request to one change, and say in it why, not only
what.
