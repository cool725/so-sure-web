#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd $DIR

ansible-playbook -i "deploy_inventory" --connect=local deploy.yml
