#!/usr/bin/env sh
set -ex;

set > "/var/kit/env"

/usr/sbin/sshd -D -e
