#!/bin/bash
cd /var/ops
git pull origin master
/var/ops/ansible/run-deploy-local.sh $1
