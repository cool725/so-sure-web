#!/bin/bash

if [ "$1" == "" ]; then
    echo "Usage: $0 env"
    exit 1
fi

./run-terraform.sh $1 graph | dot -Tpng > graph.png
xdg-open graph.png