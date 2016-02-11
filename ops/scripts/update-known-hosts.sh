#!/bin/bash

if [ "$1" == "" ]; then
    echo "Usage: $0 hostname"
    exit 1
fi

FIND_HOST=$1
FIND_IP=`dig +short $FIND_HOST`
ssh-keygen -R $FIND_HOST
ssh-keygen -R $FIND_IP
ssh-keygen -R $FIND_HOST,$FIND_IP
ssh-keyscan -H $FIND_HOST,$FIND_IP >> ~/.ssh/known_hosts
ssh-keyscan -H $FIND_IP >> ~/.ssh/known_hosts
ssh-keyscan -H $FIND_HOST >> ~/.ssh/known_hosts
