#!/bin/bash
ulimits -a
cat /etc/security/limits.conf

cd /var/ops
git pull origin master
cd /var/sosure

/usr/bin/redis-server --port 6379 &
/usr/bin/mongod --dbpath /tmp --fork --logpath /tmp/mongo.log --logappend
/var/ops/ansible/create-parameters.sh build
/var/ops/scripts/install-vendors.sh -n -u jenkins /var/sosure test
/var/ops/scripts/create-folders.sh -n -u jenkins /var/sosure test
/var/ops/scripts/clear-cache.sh /var/sosure test
/var/ops/scripts/run-encore.sh /var/sosure test

cd /var/sosure
./build/run-test-functional.sh
