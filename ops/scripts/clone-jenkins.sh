#!/bin/bash


cd /var/lib/jenkins
git init

git config --global user.email "tech+jenkins@so-sure.com"
git config --global user.name "Jenkins So-Sure"

git remote add origin ssh://APKAJ3EPZN45CVB6CQJA@git-codecommit.us-east-1.amazonaws.com/v1/repos/jenkins
git pull origin master  
/etc/init.d/jenkins restart