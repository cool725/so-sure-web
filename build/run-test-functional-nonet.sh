#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

set -e

if [ -f  app/logs/test.log ]; then
  rm app/logs/test.log
fi

sudo rm -rf /dev/shm/cache/test/

app/console --env=test doctrine:mongodb:schema:drop
app/console --env=test doctrine:mongodb:fixtures:load
app/console --env=test sosure:doctrine:index
./vendor/phing/phing/bin/phing -f build/test.xml test:unit
./vendor/phing/phing/bin/phing -f build/test.xml test:functional:nonet
./vendor/phing/phing/bin/phing force:cs
