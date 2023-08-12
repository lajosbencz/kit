#!/usr/bin/env sh
set -ex;

CWD="$(dirname "$0")"
DIR_ETC="$CWD/etc"
DIR_SSH="$DIR_ETC/ssh"

mkdir -p "$DIR_SSH"
ssh-keygen -A -f "$CWD"

kubectl create secret generic \
  kit-hostkeys \
  --namespace kit-system \
  --from-file="${DIR_SSH}/ssh_host_ecdsa_key" \
  --from-file="${DIR_SSH}/ssh_host_ed25519_key" \
  --from-file="${DIR_SSH}/ssh_host_rsa_key" \
  --dry-run=client \
  -o=yaml \
> "$(dirname "$0")"/05-hostkeys.yaml

rm -fr "$DIR_ETC"
