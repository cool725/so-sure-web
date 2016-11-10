#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR/..

# TODO: clear play devices
sudo app/console --env=vagrant doctrine:mongodb:fixtures:load --append --fixtures src/AppBundle/DataFixtures/MongoDB/c/PlayDevice
