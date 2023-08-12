#!/usr/bin/env sh
set -ex;

curl -f https://github.com/lajosbencz.keys > authorized_keys

kubectl create configmap \
  kit-authkeys \
  --namespace kit-system \
  --from-file=authorized_keys \
  --dry-run=client \
  -o=yaml \
> 05-authkeys.yaml

rm -f authorized_keys
