#!/bin/bash
export LC_ALL=C
export HOME=/tmp

cd /opt

hostname localhost

echo "Update chroot..."

apt-get update || exit 1
apt-get full-upgrade -y || exit 1
apt-get install -y php-cli gcc g++ gdb binutils sudo wget automake autoconf autotools-dev hashalot || exit 1

php lib/install-deps.php $@ || exit 1

if [[ ! -x /usr/local/bin/patchelf ]]; then
	sudo -E -u nobody lib/build-patchelf.sh || exit 1
	if [[ -x /tmp/usr/bin/patchelf ]]; then
		cp /tmp/usr/bin/patchelf /usr/local/bin/patchelf
		chmod +x /usr/local/bin/patchelf
	else
		echo "/tmp/usr/bin/patchelf - not found"
		exit 1
	fi
fi

echo ""
echo ""
echo ""
sudo -E -u nobody php convert.php $@
