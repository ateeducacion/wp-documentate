#!/usr/bin/env bash
#
# Drives scripts/galeria-capturas.sh against a bare repository of its own.
#
# The script force-pushes a branch, so what it does with a gallery already
# there — and with the one it is asked to drop — is worth proving rather than
# reading. Run it with `make test-galeria` or directly.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly SCRIPT="${ROOT}/scripts/galeria-capturas.sh"
WORK="$(mktemp -d)"
readonly WORK
trap 'rm -rf "${WORK}"' EXIT

export GALERIA_REMOTE="${WORK}/origin.git"
export RUNNER_TEMP="${WORK}/tmp"
mkdir -p "${RUNNER_TEMP}"
git init -q --bare "${GALERIA_REMOTE}"

failures=0

check() {
	if [ "$2" = "$3" ]; then
		printf '  ok   %s\n' "$1"
	else
		printf '  FAIL %s\n       expected: %s\n       actual:   %s\n' "$1" "$3" "$2"
		failures=$(( failures + 1 ))
	fi
}

# Lists the paths on the branch, one per line, sorted.
tree_of() {
	git --git-dir="${GALERIA_REMOTE}" ls-tree -r --name-only ci/capturas 2>/dev/null | sort
}

commits_on_branch() {
	git --git-dir="${GALERIA_REMOTE}" rev-list --count ci/capturas 2>/dev/null || echo 0
}

make_gallery() {
	local dir="$1" marker="$2"
	rm -rf "${dir}"
	mkdir -p "${dir}/img"
	printf '%s' "${marker}" > "${dir}/img/uno.png"
	printf '{"marker":"%s"}' "${marker}" > "${dir}/indice.json"
}

echo 'galeria-capturas.sh'

# Removing from a branch that does not exist is a no-op, not a failure.
"${SCRIPT}" remove 7 > /dev/null
check 'remove on a missing branch succeeds' "$(commits_on_branch)" '0'

make_gallery "${WORK}/shots" 'pr-101-first'
"${SCRIPT}" publish 101 "${WORK}/shots" > /dev/null
check 'first publish creates the branch' "$(tree_of)" "$(printf 'pr-101/img/uno.png\npr-101/indice.json')"
check 'first publish is a root commit' "$(commits_on_branch)" '1'

# A second PR must land beside the first, not replace it.
make_gallery "${WORK}/shots2" 'pr-202-first'
"${SCRIPT}" publish 202 "${WORK}/shots2" > /dev/null
check 'a second PR keeps the first' "$(tree_of)" \
	"$(printf 'pr-101/img/uno.png\npr-101/indice.json\npr-202/img/uno.png\npr-202/indice.json')"
check 'the branch never grows history' "$(commits_on_branch)" '1'

# Re-publishing replaces that PR's directory and leaves the other alone.
make_gallery "${WORK}/shots" 'pr-101-second'
"${SCRIPT}" publish 101 "${WORK}/shots" > /dev/null
check 'a re-run replaces its own gallery' \
	"$(git --git-dir="${GALERIA_REMOTE}" show ci/capturas:pr-101/img/uno.png)" 'pr-101-second'
check 'a re-run leaves the other PR alone' \
	"$(git --git-dir="${GALERIA_REMOTE}" show ci/capturas:pr-202/img/uno.png)" 'pr-202-first'

# A stale file inside a PR's directory does not survive its next run.
make_gallery "${WORK}/shots" 'pr-101-third'
rm "${WORK}/shots/indice.json"
"${SCRIPT}" publish 101 "${WORK}/shots" > /dev/null
check 'a re-run drops files the new gallery lacks' "$(tree_of)" \
	"$(printf 'pr-101/img/uno.png\npr-202/img/uno.png\npr-202/indice.json')"

# Closing a PR takes its directory away and nothing else.
"${SCRIPT}" remove 101 > /dev/null
check 'remove drops only that PR' "$(tree_of)" \
	"$(printf 'pr-202/img/uno.png\npr-202/indice.json')"

# The branch has to survive losing its last gallery.
"${SCRIPT}" remove 202 > /dev/null
check 'the last removal keeps the branch alive' "$(tree_of)" 'README.md'
check 'the branch is still one commit' "$(commits_on_branch)" '1'

# The publish prints the SHA the gallery comment links to.
make_gallery "${WORK}/shots" 'pr-303'
sha="$("${SCRIPT}" publish 303 "${WORK}/shots")"
check 'publish prints the pushed SHA' "${sha}" \
	"$(git --git-dir="${GALERIA_REMOTE}" rev-parse ci/capturas)"

if [ "${failures}" -ne 0 ]; then
	printf '\n%d check(s) failed.\n' "${failures}"
	exit 1
fi

printf '\nAll checks passed.\n'
