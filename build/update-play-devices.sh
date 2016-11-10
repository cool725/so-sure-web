#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

# /c/ version of PlayDevice Fixtures will run upset on existing data, so safe to rerun
sudo app/console --env=vagrant doctrine:mongodb:fixtures:load --append --fixtures src/AppBundle/DataFixtures/MongoDB/c/PlayDevice
