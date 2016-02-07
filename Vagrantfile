# -*- mode: ruby -*-
# vi: set ft=ruby :

$script = <<SCRIPT
set -e
export CACHE_DIR=/dev/shm/cache

# make sure paramters.yml exists
if [ ! -f /vagrant/app/config/parameters.yml ]; then
  cp /vagrant/app/config/parameters.yml.dist /vagrant/app/config/parameters.yml
  echo "    kernel.logs_dir: /tmp" >> /vagrant/app/config/parameters.yml
  echo "    kernel.cache_dir: $CACHE_DIR" >> /vagrant/app/config/parameters.yml
else
  CORRECT_CACHE_DIR=`grep "kernel.cache_dir: $CACHE_DIR" /vagrant/app/config/parameters.yml | wc -l`
  if [ "$CORRECT_CACHE_DIR" == "0" ]; then
    echo "kernel.cache_dir is not set to $CACHE_DIR in parameters.yml and is required to be set as such."
    exit 1
  fi
fi

WWW_USER=vagrant
ENVIRONMENT=vagrant

# Set SYMFONY_ENV 
BASH_RC=/home/$WWW_USER/.bashrc
if [ -f $BASH_RC ]; then
  SYMFONY_ENV_PRESENT=`cat $BASH_RC | grep "SYMFONY_ENV" | wc -l`
  if [ "$SYMFONY_ENV_PRESENT" == "0" ]; then
    echo "Adding SYMFONY_ENV to ~/.bashrc"
    echo "export SYMFONY_ENV=$ENVIRONMENT" >> $BASH_RC
  else
    echo "Updating SYMFONY_ENV to ~/.bashrc"
    sed -i "s/export SYMFONY_ENV\=.*/export SYMFONY_ENV=$ENVIRONMENT/" $BASH_RC
  fi
fi

echo "Setting correct app.php"
cp /vagrant/web_app/app_$ENVIRONMENT.php /vagrant/web/app.php

if [ ! -d $CACHE_DIR ]; then
  echo "Creating cache dir"
  sudo mkdir $CACHE_DIR
  chown -R $WWW_USER $CACHE_DIR
fi

sudo sudo add-apt-repository ppa:ansible/ansible
sudo apt-get update
sudo apt-get install -y python-pip ansible

cd /vagrant/ops/ansible
ansible-playbook --connection=local -v vagrant.yml

/vagrant/ops/scripts/deploy.sh -u "" /vagrant vagrant

SCRIPT

Vagrant.configure("2") do |config|
  config.trigger.before :up do
    info "Checking files for correct owner"
    run 'bash -c "if [ `find . -not -user $USER | wc -l` != 0 ]; then echo \"Change file permissions for $USER\" | tee /dev/stderr; exit 1; else echo \"File permissions are ok\"; fi"'
  end
  config.vm.define "dev", primary: true, autostart: true do |dev_config|
    dev_config.vm.box = "ubuntu1404"
    dev_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev_config.vm.network "private_network", ip: "10.0.4.2"
    #dev_config.vm.synced_folder ".", "/vagrant", owner: "www-data"
    dev_config.vm.synced_folder ".", "/vagrant", nfs: true
    #dev_config.vm.synced_folder ".", "/vagrant"
    dev_config.ssh.forward_agent = true
    dev_config.vm.provision "shell",
    	inline: $script
    	
    dev_config.vm.provider "virtualbox" do |v|
      v.customize ["modifyvm", :id, "--memory", 1200]
      v.customize ["modifyvm", :id, "--cpus", 1]
      
      # Virtualbox has issues with symlinks - https://www.virtualbox.org/ticket/10085#comment:12
      v.customize ["setextradata", :id, "VBoxInternal2/SharedFoldersEnableSymlinksCreate/vagrant", "1"]

      # if you ever need the gui
      # v.gui = true
      
      # This setting makes it so that network access from the vagrant guest is able to
      # resolve connections using the hosts VPN connection
      # it means we can DNS resolve internal.vpn domains
      v.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
      v.customize ["modifyvm", :id, "--natdnsproxy1", "on"]
    end      
  end

  config.vm.define "dev_nonfs", primary: false, autostart: false do |dev_nonfs_config|
    dev_nonfs_config.vm.box = "ubuntu1404"
    dev_nonfs_config.vm.network "forwarded_port", guest: 80, host: 40080 # apache sosure website
    dev_nonfs_config.vm.network "forwarded_port", guest: 27017, host: 47017 # mongodb
    dev_nonfs_config.vm.network "private_network", ip: "10.0.4.2"
    dev_nonfs_config.vm.synced_folder ".", "/vagrant"
    dev_nonfs_config.ssh.forward_agent = true
    dev_nonfs_config.vm.provision "shell",
    	inline: $script

    dev_nonfs_config.vm.provider "virtualbox" do |v|
      v.customize ["modifyvm", :id, "--memory", 1200]
      v.customize ["modifyvm", :id, "--cpus", 1]
      
      # Virtualbox has issues with symlinks - https://www.virtualbox.org/ticket/10085#comment:12
      v.customize ["setextradata", :id, "VBoxInternal2/SharedFoldersEnableSymlinksCreate/vagrant", "1"]

      # if you ever need the gui
      # v.gui = true
      
      # This setting makes it so that network access from the vagrant guest is able to
      # resolve connections using the hosts VPN connection
      # it means we can DNS resolve internal.vpn domains
      v.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
      v.customize ["modifyvm", :id, "--natdnsproxy1", "on"]
    end      
  end

  config.vm.define "ubuntu1404", autostart: false do |ubuntu1404_config|
    ubuntu1404_config.vm.box_url = "http://cloud-images.ubuntu.com/vagrant/trusty/current/trusty-server-cloudimg-amd64-vagrant-disk1.box"
    ubuntu1404_config.ssh.forward_agent = true
  end
end

# load extra Vagrantfile which can be used to specify user specific configurations
begin
  load 'VagrantfileExtra.rb'
rescue LoadError
  # ignore
end
