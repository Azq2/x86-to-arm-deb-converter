#!/usr/bin/env php
<?php
require_once __DIR__.'/lib/common.php';

$options = array_merge([
	"h"			=> NULL,
	"i"			=> "",
	"o"			=> "",
	"d"			=> "",
	"r"			=> "bionic",
	"repo"		=> "http://archive.ubuntu.com/ubuntu/",
	"keyring"	=> "/usr/share/keyrings/ubuntu-archive-keyring.gpg",
	"script"	=> "/usr/share/debootstrap/scripts/sid"
], getopt("hvi:o:r:d:", ["repo:", "keyring:", "script:"]));

if (isset($options["h"]) || !$options["i"] || !$options["o"]) {
	echo "usage: ".$argv[0]." -i input.deb -o output.deb\n";
	exit(0);
}

$input = $options["i"];
$output = $options["o"];
$rev = $options["r"];

echo "Analyze package...\n";
$pkg = getPackageInfo($input);

if ($pkg["arch"] == "all")
	die("Package allready works on any arch (arch=".$pkg["arch"].")");

$chroot_dir = __DIR__."/chroot-".$options["r"]."-".$pkg["arch"];

if (!file_exists("$chroot_dir/usr/bin/bash")) {
	if (!file_exists($chroot_dir))
		cmd("mkdir", "-p", $chroot_dir);
	
	echo "Setup chroot [arch=".$pkg["arch"].", rev=$rev, repo=".$options["repo"]."]...\n";
	cmd("debootstrap", "--keyring=".$options["keyring"], "--variant=minbase", "--arch=".$pkg["arch"], $rev, $chroot_dir, $options["repo"], $options["script"]);
}

$FILES = [
	"lib/setup.sh",
	"lib/common.php",
	"lib/install-deps.php",
	"convert.php"
];

foreach ($FILES as $f) {
	$dir = dirname($f);
	
	if ($dir) {
		if (!file_exists("$chroot_dir/opt/$dir"))
			cmd("mkdir", "-p", "$chroot_dir/opt/$dir");
	}
	
	cmd("cp", __DIR__."/".$f, "$chroot_dir/opt/$f");
	cmd("chmod", "+x", "$chroot_dir/opt/$f");
}

$sources = [
	"deb ".$options["repo"]." $rev main restricted universe multiverse",
	"deb ".$options["repo"]." $rev-updates main restricted universe multiverse",
	"deb ".$options["repo"]." $rev-backports main restricted universe multiverse"
];

file_put_contents("$chroot_dir/etc/apt/sources.list", implode("\n", $sources));

$deps_fixup = "";
if ($options["d"]) {
	cmd("cp", $options["d"], "$chroot_dir/opt/d.txt");
	$deps_fixup = "/opt/d.txt";
}

cmd("cp", $input, "$chroot_dir/opt/input.deb");
cmd("rm", "-rf", "$chroot_dir/opt/output.deb");
cmd("unshare", "-u", "chroot", $chroot_dir, "/opt/lib/setup.sh", "-i", "/opt/input.deb", "-o", "/opt/output.deb", "-d", $deps_fixup, "-c");

if (file_exists("$chroot_dir/opt/output.deb")) {
	cmd("mv", "$chroot_dir/opt/output.deb", $output);
	echo "\nDone! Your new package: $output\n";
}
