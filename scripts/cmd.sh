#!/usr/bin/env sh
set -ex;

cp /var/kit/authkeys/authorized_keys /var/kit/ssh/
chmod 0600 /var/kit/ssh/authorized_keys

/usr/sbin/sshd -D -e
