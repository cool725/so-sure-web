#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR

ansible-playbook -i "localhost," --connect=local deploy.yml
