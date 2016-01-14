#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..
app/console --env=test doctrine:mongodb:schema:drop
./vendor/phing/phing/bin/phing -f build/test.xml test:functional
