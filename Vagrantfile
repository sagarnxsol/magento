# -*- mode: ruby -*-
# vi: set ft=ruby :
# See https://github.com/discourse/discourse/blob/master/docs/VAGRANT.md
#
Vagrant.configure("2") do |config|
	config.vm.box = "magento"
	config.vm.box_url = "magento_box.box"

  config.vm.network :forwarded_port, guest: 3306, host: 3366
  config.ssh.forward_agent = true

  is_not_windows = RUBY_PLATFORM =~ /darwin/ || RUBY_PLATFORM =~ /linux/ || false
  host_ip = "192.168.50.5"

  config.hostmanager.enabled = is_not_windows
  config.hostmanager.manage_host = is_not_windows
  config.hostmanager.ignore_private_ip = false
  config.hostmanager.include_offline = true
  config.vm.hostname = 'magento.dev'
  config.vm.network :private_network, ip: host_ip
  config.hostmanager.aliases = %w(magento.phpmyadmin)

  config.vm.network :private_network, ip: host_ip

  config.vm.synced_folder "./", "/vagrant/", id: "vagrant-root", :nfs => is_not_windows
##  config.vm.provision :shell, :path => "./vagrant/bootstrap.sh"


  config.vm.provider :virtualbox do |v|
    v.customize ["modifyvm", :id, "--memory", 4096]
    v.customize ["modifyvm", :id, "--cpus", "4"]
  end

end
