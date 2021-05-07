<?php
function parseDepsFixup($file) {
	$config = [
		"add"		=> [],
		"delete"	=> [],
		"replace"	=> []
	];
	
	foreach (explode("\n", file_get_contents($file)) as $line) {
		$line = trim($line);
		if (!$line)
			continue;
		
		$parts = preg_split("/\s+/", $line);
		switch ($parts[0]) {
			case "add":
				$config["add"][] = $parts[1];
			break;
			
			case "replace":
				$config["replace"][$parts[1]] = $parts[2];
			break;
			
			case "delete":
				$config["delete"][] = $parts[1];
			break;
			
			default:
				die("Invalid '$file': '$line'\n");
			break;
		}
	}
	
	return $config;
}

function fixPackageInfo($dir) {
	$debian_control = file_get_contents("$dir/DEBIAN/control");
	$debian_control = preg_replace("/^Depends:(.*?)$/im", "Depends: qemu-user | qemu-user-static, qemu-user-binfmt | qemu-user-static", $debian_control);
	$debian_control = preg_replace("/^Architecture:(.*?)$/im", "Architecture: all", $debian_control);
	file_put_contents("$dir/DEBIAN/control", $debian_control);
}

function getDepsPackages($pkg) {
	$new_deps = [];
	
	foreach ($pkg["deps"] as $dep_variants) {
		$found_package = false;
		foreach ($dep_variants as $dep) {
			$search_result = cmdr("apt-cache", "search", "--names-only", "^".preg_quote($dep)."$");
			if (preg_match("/^(\S+)/", $search_result, $m)) {
				$found_package = $m[1];
				break;
			}
		}
		
		if (!$found_package)
			die(implode(" | ", $dep_variants)." - not found\n");
		
		$new_deps[] = $found_package;
	}
	
	return $new_deps;
}

function getDepsPackagesNotInstalled($pkg) {
	$not_installed = [];
	foreach (getDepsPackages($pkg) as $dep) {
		system("dpkg -L ".escapeshellarg($dep)." >/dev/null 2>&1", $ret);
		if ($ret != 0)
			$not_installed[] = $dep;
	}
}

function checkArchitecture($pkg) {
	$host_arch = trim(cmdr("dpkg", "--print-architecture"));
	if ($pkg["arch"] != $host_arch) {
		if ($pkg["arch"] != "any" && $pkg["arch"] != $host_arch)
			die("Package arch '".$pkg["arch"]."', but host arch '$host_arch'\n");
	}
}

function getPackageInfo($file, $deps_fixup = []) {
	$debian_control = cmdr("dpkg-deb", "-f", $file);
	
	$package_arch = "unknown";
	if (preg_match("/^Architecture: (.*?)$/m", $debian_control, $m))
		$package_arch = $m[1];
	
	if (!preg_match("/^Package: (.*?)$/m", $debian_control, $m))
		die("Package without name?\n");
	$package_name = $m[1];
	
	$result = [
		"name"		=> $package_name,
		"arch"		=> $package_arch,
		"deps"		=> []
	];
	
	if (preg_match("/^Depends: (.*?)$/m", $debian_control, $m)) {
		foreach (preg_split("/\s*,\s*/", $m[1]) as $deps) {
			$deps = preg_replace("/\s*\(.*?\)\s*/", "", $deps);
			
			$dep_variants = [];
			foreach (preg_split("/\s*\|\s*/", $deps) as $dep) {
				if ($deps_fixup) {
					if (isset($deps_fixup["replace"][$dep]))
						$dep = $deps_fixup["replace"][$dep];
					
					if (in_array($dep, $deps_fixup["delete"]))
						continue;
				}
				
				$dep_variants[] = $dep;
			}
			
			if ($dep_variants)
				$result["deps"][] = $dep_variants;
		}
	}
	
	if ($deps_fixup) {
		foreach ($deps_fixup["add"] as $dep)
			$result["deps"][] = [$dep];
	}
	
	return $result;
}

function analyzeElfs($analyzer, $path) {
	$directory = new \RecursiveDirectoryIterator($path);
	$iterator = new \RecursiveIteratorIterator($directory);
	
	$elfs = [];
	foreach ($iterator as $info) {
		if ($info->isFile() && isElf($info->getPathname())) {
			$analyzer->analyze($info->getPathname());
			$elfs[] = realpath($info->getPathname());
		}
	}
	
	return $elfs;
}

function patchElf($dir, $path, $libs, $replace_libs) {
	$stdout = trim(cmdr("patchelf", "--print-needed", $path));
	$needed_libs = $stdout ? explode("\n", $stdout) : [];
	
	$not_found = [];
	
	foreach ($needed_libs as $needed_lib) {
		if (isset($libs[$needed_lib])) {
			echo "  $needed_lib -> ".str_replace($dir, "<PKG>", $libs[$needed_lib])."\n";
			
			if (isset($replace_libs[$needed_lib]))
				cmdr("patchelf", "--replace-needed", $needed_lib, $replace_libs[$needed_lib], $path);
		} else {
			echo "  $needed_lib - NOT FOUND\n";
			$not_found[] = $needed_lib;
		}
	}
	
	if (stripos($path, ".so") === false) {
		$interpreter = trim(cmdr("patchelf", "--print-interpreter", $path));
		if ($interpreter) {
			if (isset($replace_libs[$interpreter])) {
				echo "  $interpreter -> ".$libs[basename($interpreter)]."\n";
				cmdr("patchelf", "--set-interpreter", $replace_libs[basename($interpreter)], $path);
			} else {
				echo "  $interpreter - NOT FOUND\n";
				$not_found[] = $interpreter;
			}
		}
	}
	
	return $not_found;
}

function isElf($file) {
	$fp = fopen($file, "r");
	$magic = fread($fp, 4);
	fclose($fp);
	return $magic === "\x7f\x45\x4c\x46";
}

function getLibDirs($prefix) {
	$stdout = cmdr("ldconfig", "-p");
	preg_match_all("/=> (\/\S+)/i", $stdout, $m);
	
	$dirs = [
		"$prefix/lib",
		"$prefix/lib64",
		"$prefix/lib32",
		"$prefix/usr/local/lib"
	];
	foreach ($m[1] as $file)
		$dirs[$prefix.dirname($file)] = true;
	
	return $dirs;
}

function cmdr() {
	$args = array_map(function ($v) { return escapeshellarg($v); }, func_get_args());
	$cmd = implode(" ", $args);
	
	$ret = -1;
	ob_start();
	passthru("LC_ALL=C ".$cmd, $ret);
	$result = ob_get_clean();
	
	if ($ret != 0)
		die("Command failed, code: $ret, cmd: $cmd\n");
	
	return $result;
}

function cmd() {
	$args = array_map(function ($v) { return escapeshellarg($v); }, func_get_args());
	$cmd = implode(" ", $args);
	
	system("LC_ALL=C ".$cmd, $ret);
	
	if ($ret != 0)
		die("Command failed, code: $ret, cmd: $cmd\n");
	
	return $ret;
}

class LibsAnalyzer {
	public $paths = [];
	public $libs = [];
	public $ld_paths = [];
	
	public function __construct($paths) {
		$this->ld_paths = $paths;
	}
	
	public function analyze($file) {
		if (isset($this->paths[$file]))
			return;
		
		$this->paths[$file] = true;
		
		$src = shell_exec("LD_LIBRARY_PATH=".implode(":", $this->ld_paths)." ldd ".escapeshellarg($file));
		preg_match_all("/([\/][\S]+)/", $src, $m);
		
		foreach ($m[1] as $lib) {
			if (file_exists($lib)) {
				$this->libs[$lib] = true;
				$this->analyze($lib);
			}
		}
	}
	
	public function getLibs() {
		return array_keys($this->libs);
	}
}
