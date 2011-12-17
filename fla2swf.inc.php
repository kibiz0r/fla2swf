<?php

define('FSW_DEFAULT_PORT', 8976);

/**
* -e
* -e <value>
* --long-param
* --long-param=<value>
* --long-param <value>
* <value>
*/
function fsw_parse_argv($params, $noopt = array()) 
{
  $result = array();
  reset($params);
  while (list($tmp, $p) = each($params)) 
  {
    if($p{0} == '-') 
    {
      $pname = substr($p, 1);
      $value = true;
      if($pname{0} == '-') 
      {
        // long-opt (--<param>)
        $pname = substr($pname, 1);
        if(strpos($p, '=') !== false) 
        {
          // value specified inline (--<param>=<value>)
          list($pname, $value) = explode('=', substr($p, 2), 2);
        }
      }
      // check if next parameter is a descriptor or a value
      $nextparm = current($params);
      if(!in_array($pname, $noopt) && $value === true && $nextparm !== false && $nextparm{0} != '-') 
        list($tmp, $value) = each($params);
      $result[$pname] = $value;
    } 
    else
      // param doesn't belong to any option
      $result[] = $p;
  }
  return $result;
}

function fsw_make_tmp_file_name($file_name)
{
  $meta = stream_get_meta_data(tmpfile());
  if(!isset($meta['uri']))
    throw new Exception("Could not get temp directory name");
  $tmp_dir = dirname($meta['uri']);
  $tmp_file = fsw_normalize_path("$tmp_dir/$file_name");
  return $tmp_file;
}

function fsw_normalize_path($path, $unix=true)
{
  //realpath for some reason processes * character :(
  if(strpos($path, '*') === false && ($real = realpath($path)) !== false)
    $path = $real;

  $slash = ($unix ? "/" : "\\");
  $qslash = preg_quote($slash);
  $path = preg_replace("~(\\\\|/)+~", $slash, $path);
  $path = preg_replace("~$qslash\.($qslash|\$)~", $slash, $path);
  return $path;
}

function fsw_is_win()
{
  return !(DIRECTORY_SEPARATOR == '/');
}

function fsw_read_from_stdin()
{
  $read   = array(STDIN);
  $write  = NULL;
  $except = NULL;
  $stdin = '';
  if(false === ($num_changed_streams = stream_select($read, $write, $except, 0))) 
    throw new Exception("Unknown stream select error happened");
  elseif ($num_changed_streams > 0) 
    $stdin = stream_get_contents(STDIN);
  return $stdin;
}

function fsw_udate($format, $utimestamp = null)
{
  if(is_null($utimestamp))
    $utimestamp = microtime(true);

  $timestamp = floor($utimestamp);
  $milliseconds = round(($utimestamp - $timestamp) * 1000000);

  return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
} 

function fsw_log($str)
{
  echo fsw_udate("H:i:s.u") . " : $str\n";
}

function fsw_hex_stream_to_bytes($stream)
{
  return pack('H*', $stream);
}

function fsw_autoguess_host()
{
  if(fsw_is_win())
  {
    exec('ipconfig', $out, $ret);
    foreach($out as $line)
    {
      if(preg_match('~\s+IP-.*:\s+(\d+\.\d+\.\d+\.\d+)~', $line, $m))
        return $m[1];
    }
  }

  return "127.0.0.1";
}

function fsw_is_port_free($port)
{
  return !fsw_is_port_busy($port);
}

function fsw_is_port_busy($port)
{
  if(fsw_is_win())
  {
    exec('netstat -a', $out, $ret);

    foreach($out as $line)
    {
      $line = trim($line);
      if(!$line)
        continue;
      if(preg_match('~^TCP\s+\S+:(\d+).*LISTENING~', $line, $m))
      {
        if($m[1] == $port)
          return true;
      }
    }
    return false;
  }
  else
  {
    exec('netstat -lnt', $out, $ret);

    foreach($out as $line)
    {
      $line = trim($line);
      if(!$line)
        continue;
      $items = preg_split("/\s+/", $line);
      if($items[0] == "tcp" && preg_match("~\S+:(\d+)~", $items[3], $m) && $items[5] = "LISTEN")
      {
        if($m[1] == $port)
          return true;
      }
    }
    return false;
  }
}

function fsw_next_dividable($num, $divider)
{
  return (floor(($num - 1) / $divider) + 1) * $divider;
} 

function fsw_decode_bytes($bytes, $print_ascii = false)
{
  $len = strlen($bytes);
  $data = "Data: \n";
  for($i=0;$i<$len;$i++) 
  {
    $byte = substr($bytes, $i, 1);
    $data .= sprintf("\x%02x", ord($byte)); 
    //$data .= '(' . ord($byte) . ')';

    if($print_ascii)
      $data .= '(' . $byte . ')';
  }
  $data .= "\n(total $len bytes)";
  echo "\n";
  var_dump($data); 
  echo "\n";
}

function fsw_socket_send($socket, $bytes)
{
  if(!is_resource($socket))
    throw new Exception("Passed socket is not a valid resource");
  $len = strlen($bytes);
  $offset = 0;
  while($offset < $len) 
  {
    $sent = socket_write($socket, substr($bytes, $offset), $len - $offset);
    if($sent === false) 
      throw new Exception('Could not write packet into socket. Socket last error: ' . socket_strerror(socket_last_error($socket)));
    $offset += $sent;
  } 
}

function fsw_socket_send_packet($socket, fswByteBuffer $packet)
{
  fsw_socket_send($socket, fsw_get_packet_bytes($packet));
}

function fsw_socket_recv($socket, $size)
{
  if(!is_resource($socket))
    throw new Exception("Passed socket is not a valid resource");
  $bytes = '';
  while($size) 
  {
    $read = socket_read($socket, $size);
    if($read === false)
      throw new Exception('Failed read from socket! Socket last error: '.socket_strerror(socket_last_error($socket)));
    else if($read === "") 
      throw new Exception('Failed read from socket! No more data to read.');
    $bytes .= $read;
    $size -= strlen($read);
  }
  return $bytes;
}

function fsw_socket_recv_packet($socket)
{
  $header = fsw_socket_recv($socket, 4/*bytes*/);
  if($header === false)
    throw new Exception("Could not read header packet from socket");

  $arr = unpack('Nsize', $header);

  if(!isset($arr['size']))
    throw new Exception("Header has invalid format");

  return new fswByteBuffer(fsw_socket_recv($socket, $arr['size']));
}

function fsw_new_session($host, $port, $timeout)
{
  $session = new fswSession(fsw_new_socket($host, $port));
  $session->setSocketRcvTimeout($timeout);//timeout in seconds
  return $session;
}

function fsw_new_socket($host, $port)
{
  if(!is_string($host))
    throw new Exception("Bad host '$host'");

  if(!is_numeric($port))
    throw new Exception("Bad port '$port'");

  $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if($sock === false)
    throw new Exception("Could not create a socket\n");

  socket_set_block($sock);

  if(!@socket_connect($sock, $host, $port))
    throw new Exception("Could not connect to host '{$host}' at port '{$port}'\n");

  return $sock;
} 

class fswByteBuffer
{
  protected $bytes;
  protected $cursor;

  function __construct($bytes = '')
  {
    $this->bytes = $bytes;
    $this->cursor = $bytes;
  }

  function getBytes()
  {
    return $this->bytes;
  }

  function getCursor()
  {
    return $this->cursor;
  }

  function dump($print_ascii = false)
  {
    fsw_decode_bytes($this->bytes, $print_ascii);
  }

  function getSize()
  {
    return strlen($this->bytes);
  }

  function addBytes($bytes)
  {
    $this->bytes .= $bytes;
  }

  function addString($string)
  {
    $this->bytes .= pack('A' . (strlen($string)) . 'x', $string);
  }

  function addPaddedString($string, $network = false)
  {
    if($network)
      $this->addUint32N(strlen($string));
    else
      $this->addUint32(strlen($string));

    $this->bytes .= pack('a' . fsw_next_dividable(strlen($string), 2), $string);
  }

  function addPaddedStringN($string)
  {
    $this->addPaddedString($string, true);
  }

  function addInt8($value)
  {
    $this->bytes .= pack('c', $value);
  }
  
  function addUint8($value)
  {
    $this->bytes .= pack('C', $value);
  }

  function addInt16($value)
  {
    $this->bytes .= pack('s', $value);
  }
 
  function addUint16($value)
  {
    $this->bytes .= pack('S', $value);
  }

  function addUint16N($value)
  {
    $this->bytes .= pack('n', $value);
  }

  function addInt32($value)
  {
    $this->bytes .= pack('l', $value);
  }

  function addInt32N($value)
  {
    $this->bytes .= pack('N', $value);
  }

  function addUint32($value)
  {
    $this->bytes .= pack('L', $value);
  }

  function addUint32N($value)
  {
    $this->bytes .= pack('N', $value);
  }

  function addFloat($value)
  {
    $this->bytes .= pack('l', (int)round($value*1000));
  }

  function extractPaddedString($network = false)
  {
    $len = $network ? $this->extractUint32N() : $this->extractUint32();
    $str = $this->extractBytes(fsw_next_dividable($len, 2));
    return substr($str, 0, $len);
  }

  function extractPaddedStringN()
  {
    return $this->extractPaddedString(true);
  }

  function extractBytes($num)
  {
    $bytes = substr($this->cursor, 0, $num);
    $this->cursor = substr($this->cursor, $num);
    return $bytes;
  }

  function extractInt8()
  {
    $arr = @unpack('cv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 1 byte from the cursor");
    $this->cursor = substr($this->cursor, 1);
    return $arr['v'];
  }
  
  function extractUint8()
  {
    $arr = @unpack('Cv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 1 byte from the cursor");
    $this->cursor = substr($this->cursor, 1);
    return $arr['v'];
  }

  function extractInt16()
  {
    $arr = @unpack('sv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 2 bytes from the cursor");
    $this->cursor = substr($this->cursor, 2);
    return $arr['v'];
  }
  
  function extractInt16N()
  {
    $arr = @unpack('nv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 2 bytes from the cursor");
    $this->cursor = substr($this->cursor, 2);
    $tmp = unpack('sv', pack('S', $arr['v']));
    return $tmp['v'];
  }
  
  function extractUint16($network = false)
  {
    $arr = @unpack(($network ? 'n' : 'S') . 'v', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 2 bytes from the cursor");
    $this->cursor = substr($this->cursor, 2);
    return $arr['v'];
  }

  function extractUint16N()
  {
    return $this->extractUint16(true);
  }

  function extractInt32()
  {
    $arr = @unpack('lv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 4 bytes from the cursor");
    $this->cursor = substr($this->cursor, 4);
    return $arr['v'];
  }
  
  function extractInt32N()
  {
    $arr = @unpack('Nv', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 4 bytes from the cursor");
    $this->cursor = substr($this->cursor, 4);
    $tmp = unpack('lv', pack('L', $arr['v']));
    return $tmp['v'];
  }
  
  function extractUint32($network = false)
  {
    $arr = @unpack(($network ? 'N' : 'L') . 'v', $this->cursor);
    if($arr === false)
      throw new Exception("Could not unpack 4 bytes from the cursor");
    $this->cursor = substr($this->cursor, 4);

    //fix for PHP unsupporting unsigned int32
    if($arr['v'] < 0)
      return sprintf('%u', $arr['v'])*1.0;
    else
      return $arr['v'];
  }

  function extractUint32N()
  {
    return $this->extractUint32(true);
  }

  function extractFloat($precision = 1)
  {
    $arr = @unpack('lv', $this->cursor);
    $this->cursor = substr($this->cursor, 4);
    
    if($precision)
      return round($arr['v']/1000, $precision);
    else
      return $arr['v']/1000;
  }

  function reset()
  {
    $this->cursor = $this->bytes;
  }
}

function fsw_get_packet_bytes(fswByteBuffer $packet)
{
  $header = new fswByteBuffer();
  $header->addUint32N($packet->getSize());

  return $header->getBytes() . $packet->getBytes();
}

class fswSession
{
  protected static $default_recv_packet_timeout = 5000;
  protected static $default_options = array(
              array(SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0)),
              array(SOL_SOCKET, SO_SNDTIMEO, array('sec' => 5, 'usec' => 0))
              );

  protected $socket;
  protected $buffer = '';
  protected $exclude_filter = array();
  protected $include_filter = array();
  protected $filtered = array();
  protected $forbidden = array();

  function __construct($socket)
  {
    $this->socket = $socket;
    foreach(self::$default_options as $opt)
      $this->setSocketOption($opt);
  }

  static function setDefaultRecvPacketTimeout($timeout)
  {
    self::$default_recv_packet_timeout = $timeout;
  }

  static function setDefaultSocketOptions($options)
  {
    self::$default_options = $options;
  }

  function setSocketOption($option)
  {
    return socket_set_option($this->socket, $option[0], $option[1], $option[2]);
  }

  function setSocketRcvTimeout($sec, $microsec = 0)
  {
    $this->setSocketOption(array(SOL_SOCKET, SO_RCVTIMEO, array('sec' => $sec, 'usec' => $microsec)));
  }

  function setSocketSndTimeout($sec, $microsec = 0)
  {
    $this->setSocketOption(array(SOL_SOCKET, SO_SNDTIMEO, array('sec' => $sec, 'usec' => $microsec)));
  }

  function getSocket()
  {
    return $this->socket;
  }

  function exists()
  {
    return is_resource($this->socket);
  }

  function close()
  {
    if(is_resource($this->socket))
      socket_close($this->socket);
    else
      throw new Exception("Socket is not valid");
  }

  function send($bytes)
  {
    fsw_socket_send($this->socket, $bytes);
  }

  function recv($size)
  {
    return fsw_socket_recv($this->socket, $size);
  }

  function sendPacket(fswByteBuffer $packet)
  {
    fsw_socket_send_packet($this->socket, $packet);
  }

  function recvPacket($timeout = null)
  {
    $time_start = microtime(true) * 1000;
    do
    {
      $packet = fsw_socket_recv_packet($this->socket);
      if($timeout && $timeout < (microtime(true) * 1000 - $time_start))
        throw new Exception("Message recieval timeout expired");
    } 
    while($packet === null);

    return $packet;
  }

  function isClosed()
  {
    if(!$this->socket)
      return true;

    $clients = array($this->socket);
    for($i=0;$i<10;$i++)
    {
      if(!is_resource($this->socket))
        return true;

      $read = $clients;
      if(socket_select($read, $write = NULL, $except = NULL, 0) < 1)
      {
        usleep(300);
        continue;    
      }

      foreach($read as $read_sock) 
      {
        //TODO: read data should be placed into buffer
        //$data = @socket_read($read_sock, 1, PHP_BINARY_READ);
        $data = @socket_read($read_sock, 10*1024);
        if($data === "")
          return true;
        $this->buffer .= $data;
      }
    }
    return false;
  }
}

function fsw_start_daemon($cmd, $title = null)
{
  if(fsw_is_win())
  {
    $cmd = "start " . ($title ? " \"$title\" " : "") . " /MIN $cmd";
    echo "$cmd\n";
    $ret = pclose(popen($cmd, "r"));
    if($ret != 0)
      throw new Exception("Could not startup '$cmd'");
  }
  else
  {
	  $cmd = "screen -d -m " . ($title ? "-S \"$title\" " : "" ) . $cmd;
    echo "$cmd\n";
    system($cmd, $ret);
    if($ret != 0)
      throw new Exception("Could not startup '$cmd'");
  }
}

function fsw_start_fla2swfd($host, $port)
{
  if(fsw_is_win() && !fsw_is_port_busy($port))
  {
    $cwd = getcwd();
    chdir(dirname(__FILE__));
    fsw_start_daemon("php fla2swfd.php --host=$host --port=$port", "fla2swfd");
    chdir($cwd);
    //giving it some time for a start
    sleep(1);
  }
}
