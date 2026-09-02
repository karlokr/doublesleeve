#!/bin/sh
# The base image runs everything in /tmp/post-install-scripts/ after its
# installer succeeds. That is the hook that makes a first deploy build the whole
# shop with nobody typing anything.
#
# Thin on purpose: the real thing is version-controlled with the other ops
# scripts, so it can also be re-run by hand.
exec bash /provisioning/deploy/bootstrap.sh
