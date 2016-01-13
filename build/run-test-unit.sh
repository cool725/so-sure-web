#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..
./vendor/phing/phing/bin/phing -f build/test.xml test:unit
