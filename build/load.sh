sudo app/console --env=vagrant doctrine:mongodb:fixtures:load 
sudo app/console --env=vagrant fos:user:create patrick@so-sure.com patrick@so-sure.com test
sudo app/console --env=vagrant fos:user:promote patrick@so-sure.com ROLE_CLAIMS
