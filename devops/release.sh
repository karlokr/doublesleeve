#!/usr/bin/env bash
#
# Cut a release from this machine.
#
#   ./devops/release.sh              patch:  v1.2.3 -> v1.2.4
#   ./devops/release.sh minor        minor:  v1.2.3 -> v1.3.0
#   ./devops/release.sh major        major:  v1.2.3 -> v2.0.0
#   ./devops/release.sh v2.5.0       that exact version
#   ./devops/release.sh patch --dry  say what would happen, do none of it
#
# There is no CI here on purpose. A hosted runner was never allocated to the
# repository and that is a billing problem rather than an engineering one, so
# the release runs where the code already is. Nothing is lost by it: the version
# is still semantic, the image still carries three tags, the release is still a
# real GitHub release. The only difference is which machine types the command.
#
# What this refuses to do is release something it cannot describe. A dirty tree,
# an unpushed commit, a tag that already exists, a registry it cannot reach -
# each stops the run BEFORE the image is built, because a half-published release
# is worse than none.
#
# ORDER MATTERS, and it is: build, push, THEN tag and publish. The git tag and
# the GitHub release are created only once the image is in the registry, so a
# release can never name an image that was never pushed. If the push fails, no
# tag exists and the same command can simply be run again.
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGE="${APP_IMAGE:-ghcr.io/karlokr/doublesleeve}"
BUMP="${1:-patch}"
DRY=false
for arg in "$@"; do [ "$arg" = "--dry" ] && DRY=true; done

say()  { printf '==> %s\n' "$1"; }
stop() { printf '!!  %s\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------- checks ----
# All of them before anything is built, so a failure costs nothing.

[ -n "$(git status --porcelain)" ] && stop "working tree is dirty - commit or stash first"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
say "branch: $BRANCH"

git fetch --quiet --tags origin
LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/$BRANCH" 2>/dev/null || echo none)"
[ "$LOCAL" != "$REMOTE" ] && stop "HEAD is not what origin/$BRANCH has - push first, so the tag points at something others can fetch"

command -v gh >/dev/null || stop "gh is not installed"
gh auth status >/dev/null 2>&1 || stop "gh is not logged in"

# ---------------------------------------------------------------- version ---
PREVIOUS="$(git tag --list 'v*' --sort=-v:refname | head -1)"
PREVIOUS="${PREVIOUS:-v0.0.0}"

case "$BUMP" in
  v[0-9]*)          VERSION="$BUMP" ;;
  major|minor|patch)
    IFS='.' read -r MA MI PA <<< "${PREVIOUS#v}"
    case "$BUMP" in
      major) MA=$((MA+1)); MI=0; PA=0 ;;
      minor) MI=$((MI+1)); PA=0 ;;
      patch) PA=$((PA+1)) ;;
    esac
    VERSION="v${MA}.${MI}.${PA}"
    ;;
  *) stop "don't understand '$BUMP' - use major, minor, patch, or an explicit vX.Y.Z" ;;
esac

git rev-parse "$VERSION" >/dev/null 2>&1 && stop "$VERSION already exists"

say "previous: $PREVIOUS"
say "releasing: $VERSION"
say "image: $IMAGE:$VERSION"

if $DRY; then
  say "dry run, stopping here"
  git log --oneline "${PREVIOUS}..HEAD" 2>/dev/null | head -20 || true
  exit 0
fi

# ------------------------------------------------------------------ build ---
# GHCR takes the same token gh already holds, so there is no second credential
# to create or keep somewhere.
say "logging in to ghcr.io"
gh auth token | docker login ghcr.io -u "$(gh api user --jq .login)" --password-stdin >/dev/null

say "building"
docker build -f devops/image/Dockerfile \
  -t "$IMAGE:$VERSION" \
  -t "$IMAGE:$LOCAL" \
  -t "$IMAGE:latest" .

# Three tags, each for a different question. The version is what a human
# deploys; the sha is what ties a running container to an exact tree when
# someone asks what is actually on production; latest is a convenience and
# should never be what production pins to.
say "pushing"
docker push --quiet "$IMAGE:$VERSION"
docker push --quiet "$IMAGE:$LOCAL"
docker push --quiet "$IMAGE:latest"

# ---------------------------------------------------------------- release ---
# The tag is created only after the image exists, so a release can never name an
# image that was never pushed.
say "tagging"
git tag -a "$VERSION" -m "$VERSION"
git push --quiet origin "$VERSION"

say "creating the GitHub release"
NOTES="$(mktemp)"
{
  echo "Image: \`$IMAGE:$VERSION\`"
  echo
  echo "Deploy:"
  echo '```bash'
  echo "./devops/prod/deploy.sh $VERSION"
  echo '```'
  echo
  echo "Rollback is deploying \`$PREVIOUS\`. Migrations are forward-only and"
  echo "backward-compatible, so the previous image runs against this schema."
  echo
  echo "## Changes"
  git log --pretty='- %s' "${PREVIOUS}..${VERSION}^" 2>/dev/null | head -50 || true
} > "$NOTES"

gh release create "$VERSION" --title "$VERSION" --notes-file "$NOTES"
rm -f "$NOTES"

say "$VERSION released"
say "deploy with:  ./devops/prod/deploy.sh $VERSION"
