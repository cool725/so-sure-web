#!/bin/bash
set -e
cd /var/ops
git pull origin master
/var/ops/ansible/run-deploy-local.sh prod
/var/ops/scripts/rollbar-deploy.sh prod
