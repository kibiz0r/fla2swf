<?php

include(dirname(__FILE__) . '/fla2swf.inc.php');

function usage($extra = '')
{
	$txt = <<<EOD
Usage:
  fla2swf.php [OPTIONS] <fla_file> <swf_file> [<fla2_file> <swf2_file>, ...]

Options:
  --host     - fla2swfd host(by default tries the first ip returned from ipconfig)
  --port     - fla2swfd port(8976 by default)
  --force=1  - force the publish process(i.e don't stop the publishing if the last 
               modification time of the resulting .swf file is newer than of the 
               source .fla file)
  --noauto=1 - don't try to spawn the fla2swfd daemon automatically

Arguments:
  <fla_file> <swf_file> - pairs of .fla => .swf files. Amount of such pairs is unlimited

EOD;

  if($extra)
    echo "$extra\n";
  echo $txt;
}

$FLA2SWF_RAW = array();
$FORCE = false;
$NOAUTO = false;
$AUTO_HOST = fsw_autoguess_host();
$HOST = $AUTO_HOST;
$PORT = FSW_DEFAULT_PORT;

array_shift($argv);
foreach(fsw_parse_argv($argv) as $key => $value)
{
  if(is_numeric($key))
  {
    if(((int)$key % 2) == 0)
      $fla_prev = $value;
    else
      $FLA2SWF_RAW[] = array($fla_prev, $value);
  }
  else if($key{0} == 'D')
  {
    $def = substr($key, 1);
    if($def)
    {
      echo("Defining '$def' as '$value'\n");
      define("$def", $value);
    }
  }
  else
  {
    switch($key)
    {
      case 'host':
        $HOST = $value;
        break;
      case 'port':
        $PORT = $value;
        break;
      case 'force':
        $FORCE = true;
        break;
      case 'noauto':
        $NOAUTO = true;
        break;
    }
  }
}

if(!$FLA2SWF_RAW)
{
  usage("No fla/swf files specified");
  exit(1);
}

//filtering raw output
$FLA2SWF = array();
foreach($FLA2SWF_RAW as $item)
{
  $FLA = $item[0];
  $SWF = $item[1];

  //skipping export for targets which are not obsolete
  if(!$FORCE && is_file($FLA) && is_file($SWF) && filemtime($SWF) > filemtime($FLA))
  {
    echo "Skipping $FLA => $SWF, target is newer than source\n";
    continue;
  }

  $FLA2SWF[] = array($FLA, $SWF);
}

foreach($FLA2SWF as $item)
{
  $FLA = $item[0];
  $SWF = $item[1];
  if(!file_exists($FLA))
  {
    usage("No such fla file '$FLA'");
    exit(1);
  }

  if(strtolower(end(explode('.', $SWF))) != 'swf')
  {
    usage("Can't export fla file '$FLA' to non-swf file '$SWF'");
    exit(1);
  }
}

if(!$FLA2SWF)
  exit(0);

if(!$NOAUTO && $HOST == $AUTO_HOST)
  fsw_start_fla2swfd($HOST, $PORT);

echo "Connecting to '$HOST' at port '$PORT'...\n";
$sess = fsw_new_session($HOST, $PORT, 500/*timeout*/);

echo "Sending request...\n";

$out = new fswByteBuffer();
$out->addUint32N(sizeof($FLA2SWF));

foreach($FLA2SWF as $item)
{
  $FLA = $item[0];

  $contents = file_get_contents($FLA);
  if($contents === false)
    throw new Exception("No such a file '$file'");
  $out->addPaddedStringN($contents);
}

$sess->sendPacket($out);
echo "Waiting for reply...\n";
$in = $sess->recvPacket();

$error = $in->extractUint32N();
$error_msg = $in->extractPaddedStringN();
if($error != 0)
  throw new Exception("Remote error: $error_msg");

$total_files = $in->extractUint32N();
if(sizeof($FLA2SWF) != $total_files)
  throw new Exception("Number of returned files doesn't match(should be " . sizeof($FLA2SWF) . " but was $total_files)");

foreach($FLA2SWF as $item)
{
  $FLA = $item[0];
  $SWF = $item[1];
  $contents = $in->extractPaddedStringN();

  echo("$FLA(" . filesize($FLA)  . " bytes) -> $SWF(" . strlen($contents) . " bytes)\n");

  if(!file_put_contents($SWF, $contents))
    throw new Exception("Could not write to $SWF file");
}
