#!/bin/bash
AWS_PROFILE=sosure
export AWS_ACCESS_KEY_ID=`credstash -p $AWS_PROFILE -r eu-west-1 get ops/setup/access_key`
export AWS_SECRET_ACCESS_KEY=`credstash -p $AWS_PROFILE -r eu-west-1 get ops/setup/secret_key`

terraform apply $1