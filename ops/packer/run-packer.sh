#!/bin/bash
AWS_PROFILE="sosure"
ACCESS_KEY=`credstash -p $AWS_PROFILE -r eu-west-1 get ops/setup/access_key`
SECRET_KEY=`credstash -p $AWS_PROFILE -r eu-west-1 get ops/setup/secret_key`

packer build \
    -var "aws_access_key=$ACCESS_KEY" \
    -var "aws_secret_key=$SECRET_KEY" \
    $1 
