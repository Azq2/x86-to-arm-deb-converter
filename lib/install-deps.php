#!/usr/bin/env php
<?php
require_once __DIR__.'/common.php';

$options = array_merge([
	"h"			=> NULL,
	"i"			=> "",
	"d"			=> "",
], getopt("hvi:o:d:"));

if (isset($options["h"]) || !$options["i"]) {
	echo "usage: ".$argv[0]." -i input.deb\n";
	exit(0);
}

$input = $options["i"];

$deps_fixup = [];
if ($options["d"])
	$deps_fixup = parseDepsFixup($options["d"]);

echo "Analyze package...\n";
$pkg = getPackageInfo($input, $deps_fixup);

$to_install = getDepsPackages($pkg);

if ($to_install) {
	echo "Install dependencies...\n";
	echo "DEPS: ".implode(" ", $to_install)."\n";
	call_user_func_array("cmd", array_merge(["apt-get", "install", "-y"], $to_install));
}
