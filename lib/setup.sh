#!/bin/bash
export LC_ALL=C

cd /opt

hostname localhost

echo "Update chroot..."

apt-get update
apt-get full-upgrade -y
apt-get install -y php-cli gcc g++ gdb binutils sudo patchelf

php lib/install-deps.php $@
echo ""
echo ""
echo ""
php convert.php $@
