#!/bin/bash
set -e
cd /var/ops
git reset --hard
git clean -fd
git pull origin master
/var/ops/ansible/run-deploy-local.sh prod
