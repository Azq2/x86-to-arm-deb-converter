#!/usr/bin/env php
<?php
require_once __DIR__.'/lib/common.php';

$options = array_merge([
	"h"		=> NULL,
	"c"		=> NULL,
	"i"		=> "",
	"o"		=> "",
	"d"		=> ""
], getopt("chvi:d:o:q:"));

if (isset($options["h"]) || !$options["i"] || !$options["o"]) {
	echo "usage: ".$argv[0]." -i input.deb -o output.deb\n";
	exit(0);
}

$input = $options["i"];
$output = $options["o"];

$deps_fixup = [];
if ($options["d"])
	$deps_fixup = parseDepsFixup($options["d"]);

echo "Analyze package...\n";
$pkg = getPackageInfo($input, $deps_fixup);
checkArchitecture($pkg);
$to_install = getDepsPackagesNotInstalled($pkg);

if ($pkg["arch"] == "all")
	die("Package allready works on any arch (arch=".$pkg["arch"].")");

if ($to_install) {
	echo "To install packages:\n";
	echo "  sudo apt install ".implode(" ", $to_install)."\n";
	exit;
}

$unpacked_dir = "/tmp/deb-unpack-".md5($input);

echo "Unpack package...\n";
cmd("rm", "-rf", $unpacked_dir);
cmd("mkdir", "-p", $unpacked_dir);
cmd("dpkg-deb", "-R", $input, $unpacked_dir);

$extlib_dir = "/usr/local/lib/ext-".$pkg["arch"]."-".$pkg["name"];

echo "Analyze all ELF's...\n";
$analyzer = new LibsAnalyzer(getLibDirs($unpacked_dir));
$all_elfs = analyzeElfs($analyzer, $unpacked_dir);

$libs = [];
$external_libs = [];
$replace_libs = [];

foreach ($analyzer->getLibs() as $lib) {
	if (strpos($lib, $unpacked_dir) !== 0) {
		$external_libs[basename($lib)] = $lib;
		$replace_libs[basename($lib)] = $extlib_dir."/".basename($lib);
	} else {
		$replace_libs[basename($lib)] = substr($lib, strlen($unpacked_dir));
	}
	$libs[basename($lib)] = $lib;
}

echo "Copy all external libs...\n";
cmd("mkdir", "-p", $unpacked_dir.$extlib_dir);
foreach ($external_libs as $lib => $lib_path) {
	cmd("cp", $lib_path, $unpacked_dir.$replace_libs[$lib]);
	$all_elfs[] = realpath($unpacked_dir.$replace_libs[$lib]);
}

$all_elfs = array_unique($all_elfs);

echo "Patch all ELF's...\n";
$not_found_libs = [];
foreach ($all_elfs as $elf) {
	$elf_name = substr($elf, strlen($unpacked_dir));
	echo "patch: $elf_name\n";
	foreach (patchElf($unpacked_dir, $elf, $libs, $replace_libs) as $needed_lib)
		$not_found_libs[$elf_name][] = $needed_lib;
}

if ($not_found_libs) {
	echo "\n";
	echo "SOME LIBS NOT FOUND:\n";
	foreach ($not_found_libs as $elf => $libs) {
		echo "  $elf\n";
		foreach ($libs as $lib)
			echo "    ".$lib."\n";
	}
	echo "\n";
}

echo "Update debian/control...\n";
fixPackageInfo($unpacked_dir);

echo "Build package...\n";
cmd("fakeroot", "dpkg-deb", "-b", $unpacked_dir, $output);

if (!isset($options["c"]))
	echo "\nDone! Your new package: $output\n";
