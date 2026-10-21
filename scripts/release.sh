#!/bin/sh
# Cut a release: the steps of docs/RELEASING.md, with the checks that make a bad release hard.
#
#   scripts/release.sh prepare patch|minor|major|X.Y.Z   branch + pull request that dates the changelog
#   scripts/release.sh tag X.Y.Z [--yes]                 tag main and push the tag (this publishes)
#   scripts/release.sh notes X.Y.Z                       print that version's changelog section
#
# A release is a tag, vX.Y.Z, on a commit of main. Hosts that follow ^1.0 take it the next night (or at
# once with scripts/update.sh), because Composer reads the repository's tags; nothing is uploaded.
# `tag` therefore refuses unless CI and the integration suites have PASSED on that very commit: a tag
# that is later found broken is already installed on hosts. The release workflow then runs everything
# again and publishes the GitHub Release (it never removes a tag).
#
# Needs git, and the GitHub CLI (gh) logged in as someone who can push to the repository.
set -eu

die() {
    echo "release.sh: $*" >&2
    exit 1
}

cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

valid_version() {
    printf '%s' "$1" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
}

latest_version() {
    git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n 1 | sed 's/^v//'
}

# How version $1 stands against version $2, in $order: newer, same or older. Both must be X.Y.Z. Plain awk, no
# `sort -V`: a comparison that cannot run must stop the release, not read as "not newer". Components are compared
# as digit strings (a longer one is bigger, then character by character; none has a leading zero), so they can be
# any size and 1.10.0 is newer than 1.9.0.
compare_versions() {
    valid_version "$1" && valid_version "$2" || die "cannot compare '$1' with '$2': both must be versions (X.Y.Z)"
    order="$(awk -v a="$1" -v b="$2" 'BEGIN {
        split(a, x, "."); split(b, y, ".")
        for (i = 1; i <= 3; i++) {
            p = x[i] ""; q = y[i] ""
            if (p == q) continue
            if (length(p) != length(q)) newer = length(p) > length(q); else newer = p > q
            print (newer ? "newer" : "older")
            exit
        }
        print "same"
    }')" || die "cannot compare $1 with $2: awk failed"
    case "$order" in
        newer | same | older) ;;
        *) die "cannot compare $1 with $2: awk said '$order'" ;;
    esac
}

# The body of a version's changelog section, which must exist and not be empty.
notes() {
    valid_version "$1" || die "'$1' is not a version (X.Y.Z)"
    body="$(awk -v v="$1" '/^## \[/ { if (on) exit; if (index($0, "## [" v "] - ") == 1) { on = 1; next } } on { print }' CHANGELOG.md)"
    [ -n "$body" ] && printf '%s' "$body" | grep -q '[^[:space:]]' || die "CHANGELOG.md has no section '## [$1] - date' with something in it"
    printf '%s\n' "$body"
}

# main, clean, and exactly what is on GitHub.
on_main() {
    [ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "not on main"
    [ -z "$(git status --porcelain)" ] || die "the working tree is not clean"
    git fetch -q origin main --tags
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main is not the same as origin/main (pull or push first)"
}

cmd_prepare() {
    [ $# -eq 1 ] || die "usage: release.sh prepare patch|minor|major|X.Y.Z"
    on_main
    current="$(latest_version)"
    case "$1" in
        patch | minor | major)
            [ -n "$current" ] || die "there is no release yet: give the first version, release.sh prepare 1.0.0"
            next="$(printf '%s' "$current" | awk -F. -v k="$1" '{ if (k == "major") printf "%d.0.0", $1 + 1; else if (k == "minor") printf "%d.%d.0", $1, $2 + 1; else printf "%d.%d.%d", $1, $2, $3 + 1 }')"
            ;;
        *)
            valid_version "$1" || die "'$1' is not patch, minor, major or a version (X.Y.Z)"
            next="$1"
            ;;
    esac
    if [ -n "$current" ]; then
        compare_versions "$next" "$current"
        [ "$order" = newer ] || die "$next is not newer than the latest release, v$current"
    fi
    pending="$(awk '/^## \[Unreleased\]/ { on = 1; next } /^## \[/ { on = 0 } on { print }' CHANGELOG.md)"
    printf '%s' "$pending" | grep -q '[^[:space:]]' || die "CHANGELOG.md has nothing under '## [Unreleased]': write what changed first"
    branch="release-v$next"
    git switch -q -c "$branch"
    today="$(date -u +%Y-%m-%d)"
    awk -v v="$next" -v d="$today" '/^## \[Unreleased\]/ { print; print ""; print "## [" v "] - " d; next } { print }' CHANGELOG.md > CHANGELOG.md.new
    mv CHANGELOG.md.new CHANGELOG.md
    git add CHANGELOG.md
    git commit -q -m "Release $next: date the changelog"
    git push -q -u origin "$branch"
    gh pr create --title "Release $next" --body "Dates the changelog for $next. Once this is merged and CI and the integration suites are green on main: \`scripts/release.sh tag $next\`."
    echo "Merge that pull request, wait for the checks on main, then: scripts/release.sh tag $next"
}

cmd_tag() {
    [ $# -ge 1 ] || die "usage: release.sh tag X.Y.Z [--yes]"
    version="$1"
    yes="${2:-}"
    valid_version "$version" || die "'$version' is not a version (X.Y.Z)"
    on_main
    notes "$version" > /dev/null
    git rev-parse -q --verify "refs/tags/v$version" > /dev/null && die "v$version already exists"
    current="$(latest_version)"
    if [ -n "$current" ]; then
        compare_versions "$version" "$current"
        [ "$order" = newer ] || die "$version is not newer than the latest release, v$current"
    fi
    sha="$(git rev-parse HEAD)"
    for workflow in CI Integration; do
        result="$(gh run list --commit "$sha" --workflow "$workflow" --json status,conclusion --jq '.[0] | (.status + " " + (.conclusion // "-"))' 2>/dev/null || true)"
        case "$result" in
            "completed success") ;;
            "") die "no $workflow run for $sha yet (it starts when main is pushed): wait for it" ;;
            "completed "*) die "$workflow did not pass on $sha ($result): fix main first" ;;
            *) die "$workflow is still running on $sha ($result): wait for it" ;;
        esac
    done
    echo "Tag v$version at $sha ($(git log -1 --format=%s)). CI and Integration passed on it."
    echo "Hosts that follow ^${version%%.*}.0 will install it that night. This cannot be taken back."
    if [ "$yes" != "--yes" ]; then
        printf 'Tag and push? [y/N] '
        read -r answer
        [ "$answer" = y ] || [ "$answer" = Y ] || die "not tagged"
    fi
    git tag -a "v$version" -m "Release $version"
    git push -q origin "v$version"
    echo "Pushed v$version. The Release workflow now re-runs everything and publishes the GitHub Release."
}

main() {
    [ $# -ge 1 ] || die "usage: release.sh prepare|tag|notes ..."
    sub="$1"
    shift
    case "$sub" in
        prepare) cmd_prepare "$@" ;;
        tag) cmd_tag "$@" ;;
        notes) [ $# -eq 1 ] || die "usage: release.sh notes X.Y.Z"; notes "$1" ;;
        *) die "unknown command '$sub' (prepare, tag or notes)" ;;
    esac
}

main "$@"
