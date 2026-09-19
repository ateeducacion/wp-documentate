#!/usr/bin/env bash
#
# Keeps every pull request's screenshot gallery on one branch.
#
# The galleries used to get a branch each, named after the pull request, and
# nothing ever deleted them: the branch list grew by one entry per PR and every
# fetch dragged the new refs down. They all live on a single branch now, one
# directory per PR, and the directory goes when the PR closes.
#
# The branch is rebuilt as a root commit on every run and force-pushed, so it
# never accumulates history: what a closed PR leaves behind stops being
# referenced instead of sitting in the branch's past forever.
#
# Usage:
#   galeria-capturas.sh publish <pr-number> <source-dir>
#   galeria-capturas.sh remove  <pr-number>
#
# Needs GITHUB_REPOSITORY and a git credential helper already set up
# (`gh auth setup-git`). Prints the resulting commit SHA on success.

set -euo pipefail

readonly BRANCH='ci/capturas'
readonly ATTEMPTS=5

readonly MODE="${1:?publish or remove}"
readonly PR="${2:?pull request number}"
readonly SOURCE="${3:-}"

# GALERIA_REMOTE overrides the target, which is what the test uses to drive the
# whole thing against a bare repository instead of GitHub.
readonly REMOTE="${GALERIA_REMOTE:-https://github.com/${GITHUB_REPOSITORY:?}.git}"
readonly DEST="${RUNNER_TEMP:-/tmp}/galeria-capturas"

if [ "${MODE}" = 'publish' ] && [ ! -d "${SOURCE}" ]; then
	echo "No such source directory: ${SOURCE}" >&2
	exit 1
fi

# Lays the current branch content out in DEST with no history attached, so the
# commit built on top of it is a root commit.
checkout_flat() {
	rm -rf "${DEST}"

	if [ -n "$1" ]; then
		git clone -q --depth 1 --branch "${BRANCH}" "${REMOTE}" "${DEST}"
		rm -rf "${DEST}/.git"
	else
		mkdir -p "${DEST}"
	fi

	git init -q -b "${BRANCH}" "${DEST}"
	git -C "${DEST}" remote add origin "${REMOTE}"
}

for attempt in $(seq 1 "${ATTEMPTS}"); do
	# Re-read the tip on every attempt: another PR may have published since.
	previous=$(git ls-remote "${REMOTE}" "refs/heads/${BRANCH}" | cut -f1)

	if [ "${MODE}" = 'remove' ] && [ -z "${previous}" ]; then
		echo "Nothing to remove: ${BRANCH} does not exist."
		exit 0
	fi

	checkout_flat "${previous}"
	rm -rf "${DEST:?}/pr-${PR}"

	if [ "${MODE}" = 'publish' ]; then
		mkdir -p "${DEST}/pr-${PR}"
		cp -R "${SOURCE}/." "${DEST}/pr-${PR}/"
		message="Gallery for PR #${PR}"
	else
		message="Drop the gallery of PR #${PR}"
	fi

	# An empty tree cannot be committed; leave a marker so the branch survives
	# the removal of the last gallery.
	shopt -s nullglob
	remaining=( "${DEST}"/pr-* )
	shopt -u nullglob
	if [ ${#remaining[@]} -eq 0 ]; then
		printf 'Screenshot galleries per pull request. Written by .github/workflows/capturas.yml.\n' \
			> "${DEST}/README.md"
	fi

	git -C "${DEST}" add -A
	git -C "${DEST}" \
		-c user.name='github-actions[bot]' \
		-c user.email='41898282+github-actions[bot]@users.noreply.github.com' \
		commit -q -m "${message}"

	# With no branch yet a plain push is the safe move: it fails if someone
	# created it meanwhile, and the next attempt sees it and leases against it.
	if [ -n "${previous}" ]; then
		push=(push "--force-with-lease=refs/heads/${BRANCH}:${previous}")
	else
		push=(push)
	fi

	if git -C "${DEST}" "${push[@]}" -q origin "HEAD:refs/heads/${BRANCH}"; then
		git -C "${DEST}" rev-parse HEAD
		exit 0
	fi

	echo "Attempt ${attempt} lost the race for ${BRANCH}; retrying." >&2
	sleep $(( RANDOM % 7 + 3 ))
done

echo "Could not update ${BRANCH} after ${ATTEMPTS} attempts." >&2
exit 1
