<?php

$version = trim(file_get_contents(dirname(__FILE__) . '/VERSION'));
$build_name = "fla2swf-$version"; 
$build_dir = dirname(__FILE__) . "/build";

system("rm -rf $build_dir/$build_name");
mkdir("$build_dir/$build_name", 0777, true);

system("cp -a * $build_dir/$build_name");
system("rm -rf $build_dir/$build_name/build");
system("rm -rf $build_dir/$build_name/make.php");
system("cd $build_dir && tar czf $build_name.tgz $build_name");
system("cd $build_dir && zip -r -9 $build_name.zip $build_name");
