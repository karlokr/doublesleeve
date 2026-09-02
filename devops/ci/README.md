# The CI runner

GitLab has no runners of its own. A pipeline with no runner does not fail, it
sits **pending** forever, which is a confusing way to be broken, so this is the
first thing to set up.

## Why it is not in the GitLab stack

The GitLab stack is a Swarm service, and this is not, on purpose:

- **Swarm services cannot be privileged**, so docker-in-docker cannot run as one.
- A runner is host-local infrastructure. It belongs to the machine you want
  builds to happen on, not to a replicated service with a placement constraint.

## Why there is no docker-in-docker here

The usual recipe pairs a privileged runner with a `docker:dind` service. This one
mounts the host's Docker socket instead, and the jobs talk to the daemon that is
already running.

**The trade, stated plainly:** a job that can reach the Docker socket can start a
container that mounts the host filesystem, which is root on that host. On a
single-tenant GitLab where every pipeline is your own code that is an acceptable
trade, and it is the same trust you already extend to anything you `docker run`.
It would not be acceptable on a runner shared with people you do not trust.

What you get for it: **the build cache is the host daemon's cache.** The image is
2.7 GB and mostly unchanged base layers, so a dind runner would re-pull and
re-extract most of it every pipeline. This one does not.

If you would rather have the isolation, the alternative is Kaniko, which needs no
privileged mode and no socket; the cost is a slower, colder build.

## Register it

GitLab 19 issues a **runner authentication token** from the UI. The old
`gitlab-runner register --registration-token` flow is gone.

1. In the project: **Settings > CI/CD > Runners > New project runner**
2. Tick **Run untagged jobs**, create it
3. Copy the `glrt-...` token
4. Then, on the machine that will run builds:

```bash
mkdir -p devops/ci/config
docker run --rm -v "$PWD/devops/ci/config:/etc/gitlab-runner" \
  gitlab/gitlab-runner:v17.7.0 register \
    --non-interactive \
    --url https://gitlab.karlokrakan.me/ \
    --token glrt-REPLACE_ME \
    --executor docker \
    --docker-image docker:27-cli \
    --docker-volumes /var/run/docker.sock:/var/run/docker.sock \
    --docker-volumes /cache \
    --description doublesleeve-local
```

5. Start it:

```bash
docker compose -f devops/ci/compose.yml up -d
docker logs -f doublesleeve-runner
```

The runner appears in the same Settings page within a few seconds, and pending
pipelines start immediately.

## Checking it works

```bash
curl -s -H "PRIVATE-TOKEN: $TOKEN" \
  https://gitlab.karlokrakan.me/api/v4/projects/karlokr%2Fdoublesleeve/runners
```

An empty array means it did not register. A pipeline stuck on pending with no
job log means the same thing, and is the failure this file exists to prevent.

## The registry

`$CI_REGISTRY_IMAGE` resolves to `registry.karlokrakan.me/karlokr/doublesleeve`,
because the GitLab stack sets `registry_external_url`. The pipeline logs in with
`$CI_REGISTRY_USER` / `$CI_REGISTRY_PASSWORD`, which GitLab injects into every
job, so nothing needs configuring for the push to work.

Production pulls from that same registry, so the host running the shop needs a
deploy token:

**Settings > Repository > Deploy tokens**, scope `read_registry`, then on the
production host:

```bash
docker login registry.karlokrakan.me -u <token-user> -p <token>
```
