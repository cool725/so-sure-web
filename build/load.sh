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

sudo app/console --env=vagrant doctrine:mongodb:fixtures:load 
sudo app/console --env=vagrant fos:user:create patrick@so-sure.com patrick@so-sure.com test
sudo app/console --env=vagrant fos:user:promote patrick@so-sure.com ROLE_CLAIMS
sudo app/console --env=vagrant fos:user:promote patrick@so-sure.com ROLE_ADMIN
