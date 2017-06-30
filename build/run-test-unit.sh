#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

IS_PRODUCTION=`grep mongodb_url app/config/parameters.yml | grep prod.so-sure.net | wc -l`
if [ "$IS_PRODUCTION" != "0" ]; then
    echo "This is a production server.  Do not run!"
    exit 1
else
    echo "Non-production server - safe to run"
fi

set -e

if [ -d /dev/shm/cache/test ]; then
  rm -rf /dev/shm/cache/test/
fi

./vendor/phing/phing/bin/phing -f build/test.xml test:unit
./vendor/phing/phing/bin/phing force:cs
