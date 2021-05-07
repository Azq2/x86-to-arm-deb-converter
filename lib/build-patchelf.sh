#!/bin/bash
SRC=https://github.com/NixOS/patchelf/archive/refs/tags/0.12.tar.gz
HASH=b9d1161e52e2f342598deabf7d85ed24
SUBDIR=patchelf-0.12

echo "Building fresh version of patchelf..."

rm -rf /tmp/patchelf-build
mkdir /tmp/patchelf-build
cd /tmp/patchelf-build

wget "$SRC" -O patchelf.tar.gz || exit 1
echo "$HASH  patchelf.tar.gz" > md5sums

NEW_HASH=$(md5sum patchelf.tar.gz | awk '{print $1}')

if [[ $NEW_HASH != $HASH ]]; then
	echo "Hash mismatch (NEW_HASH=$NEW_HASH, HASH=$HASH)"
	exit 1
fi

tar -xpvf patchelf.tar.gz || exit 1
cd $SUBDIR || exit 1
./bootstrap.sh || exit 1
./configure --prefix=/tmp/usr || exit 1
make || exit 1
make install || exit 1
