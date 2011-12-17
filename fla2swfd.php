<?php

include(dirname(__FILE__) . '/fla2swf.inc.php');

class fswServer
{
  protected $port;
  protected $host;
  protected $sock;
  protected $single_client = false;
  protected $clients = array();
  protected $client_handlers = array();
  protected $client_handler;

  function __construct($host, $port, $single_client = false)
  {
    $this->host = $host;
    $this->port = $port;
    $this->single_client = $single_client;
  }
  
  function __destructor()
  {
    if(is_resource($this->sock))
      socket_close($this->sock);
  }
  
  function setClientHandler($handler)
  {
    if(!is_object($handler))
      $this->client_handler = new $handler;
    else
      $this->client_handler = $handler;      
  }

  function start()
  {
    if(!fsw_is_port_free($this->port))
      throw new Exception("Port '{$this->port}' seems to be busy");

    // create a streaming socket, of type TCP/IP
    $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    // set the option to reuse the port
    if(!socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1))
      throw new Exception("Could not set option for socket:" . socket_strerror(socket_last_error()));

    // "bind" the socket to the address to "localhost", on port $port
    // so this means that all connections on this port are now our resposibility to send/recv data, disconnect, etc..
    if(!socket_bind($this->sock, $this->host, $this->port))
      throw new Exception("Could not bind to port '{$this->port}' on host '{this->$host}':" . socket_strerror(socket_last_error()));

    // start listen for connections
    if(!socket_listen($this->sock))
      throw new Exception("Could not start listening on port '{$this->port}':" . socket_strerror(socket_last_error()));

    // create a list of all the clients that will be connected to us..
    // add the listening socket to this list
    $this->clients = array($this->sock);

    echo "New server listening on port '{$this->port}' of host '{$this->host}'\n";

    while($this->select())
      usleep(1000);
  }

  private function select()
  {
    // create a copy, so $clients doesn't get modified by socket_select()
    $read = $this->clients;

    // get a list of all the clients that have data to be read from
    // if there are no clients with data, go to next iteration
    if(socket_select($read, $write = NULL, $except = NULL, 0) < 1)
      return true;

    // check if there is a client trying to connect
    if(in_array($this->sock, $read)) 
    {
      if($this->single_client)
      {
        echo "Only one client is allowed, removing old clients(if any)\n";
        foreach($this->clients as $key => $sock)
        {
          if($sock == $this->sock)
            continue;          
          socket_close($sock);          
          unset($this->clients[$key]);        
          unset($this->client_handlers[$sock]);          
          unset($read[$key]);
        } 
      }
      
      // accept the client, and add him to the $clients array
      $this->clients[] = $newsock = socket_accept($this->sock);

      $this->onClientConnect($newsock);

      socket_getpeername($newsock, $ip);
      echo "Client '$ip' connected\n";

      // remove the listening socket from the clients-with-data array
      $key = array_search($this->sock, $read);
      unset($read[$key]);
    }

    // loop through all the clients that have data to read from
    foreach($read as $read_sock) 
    {
      // read until 1024 bytes
      // socket_read while show errors when the client is disconnected, so silence the error messages
      $data = @socket_read($read_sock, 1024, PHP_BINARY_READ);        
      // check if the client is disconnected      
      if($data == "") 
      {
        // remove client for $clients array
        $this->onClientDisconnect($read_sock);
        $idx = array_search($read_sock, $this->clients);
        unset($this->clients[$idx]);
        $ip = null;
        socket_getpeername($read_sock, $ip);
        echo "Client '$ip' disconnected\n";
        // continue to the next client to read from, if any
        continue;
      }

      // check if there is any data
      if(!empty($data)) 
      {       
        // send this to all the clients in the $clients array (except the first one, which is a listening socket)
        foreach($this->clients as $send_sock) 
        {
          // if its the listening sock or the client that we got the message from, go to the next one in the list
          /*if($send_sock == $sock || $send_sock == $read_sock)
            continue;
            */          
          if($send_sock == $this->sock)
            continue;

          //TODO: $data can be read partially thus it should be buffered
          //if($data) $this->buffer[$send_sock] .= $data; //something like this
          $res = $this->onClientData($send_sock, $data);                              
        } // end of broadcast foreach
      }

    } // end of reading foreach
    return true;
  }

  function onClientConnect($socket)
  {    
    if(is_object($this->client_handler))
    {
      $this->client_handlers[$socket] = clone($this->client_handler);
      $this->client_handlers[$socket]->onConnect($socket);
    }
  }

  function onClientDisconnect($socket)
  {
    if(isset($this->client_handlers[$socket]))
      $this->client_handlers[$socket]->onDisconnect($socket);
  }

  function onClientData($socket, $data)
  {
    if(isset($this->client_handlers[$socket]))
      $this->client_handlers[$socket]->onData($socket, $data);
  }
  
  function stop()
  {
    socket_close($this->sock);
  }
} 

class fswNetHandler
{
  private $data;
  private $size_to_read;
  private $on_new_packet;

  function __construct($on_new_packet)
  {
    $this->data = '';
    $this->size_to_read = -1;

    if(!is_callable($on_new_packet))
      throw new Exception("Bad new packet handler");

    $this->on_new_packet = $on_new_packet;
  }

  function onConnect($socket){}

  function onDisconnect($socket){}

  function onData($socket, $data)
  {
    //first time
    if($this->size_to_read == -1)
    {
      $size_str = substr($data, 0, 4);
      $arr = unpack('Nsize', $size_str);
      if(!isset($arr['size']))
        throw new Exception("Header has invalid format");
      $this->size_to_read = $arr['size'];
      //var_dump($this->size_to_read);
      $this->data = substr($data, 4);
    }
    else
      $this->data .= $data;

    //packet is ready
    if($this->size_to_read != -1 && strlen($this->data) >= $this->size_to_read)
    {
      $in = new fswByteBuffer($this->data); 

      call_user_func_array($this->on_new_packet, array($socket, $in));

      $this->size_to_read = -1;
      $this->data = '';
    }

  }
}

function usage($extra = '')
{
	$txt = <<<EOD
Usage:
  fla2swfd.php <port> [host]

EOD;

  if($extra)
    echo "$extra\n";
  echo $txt;
}

function fsw_process_client_request($socket, fswByteBuffer $in)
{
  $out = new fswByteBuffer();

  try
  {
    $files = array();
    $total_files = $in->extractUint32N();
    for($i=0;$i<$total_files;++$i)
    {
      $file_contents = $in->extractPaddedStringN();

      $tmp_fla_file = fsw_make_tmp_file_name(mt_rand(1, 1000) . "_$i.fla");
      $tmp_swf_file = $tmp_fla_file . '.swf';

      if(!file_put_contents($tmp_fla_file, $file_contents))
        throw new Exception("Could not write to file '$tmp_file'");

      $files[] = array($tmp_fla_file, $tmp_swf_file);
    }

    $status_file = fsw_make_tmp_file_name(mt_rand(1, 100) . ".out");
    if(is_file($status_file))
      unlink($status_file);
    $error_file = fsw_make_tmp_file_name(mt_rand(1, 100) . ".err");
    if(is_file($error_file))
      unlink($error_file);

    $jsfl = fsw_make_exporting_jsfl($files, $status_file, $error_file);
    fsw_exec_jsfl($jsfl, $status_file);

    //TODO: parse some useful info from these files?
    @unlink($status_file);
    @unlink($error_file);

    $out->addUint32N(0);//error
    $out->addPaddedStringN('');//error descr
    $out->addUint32N(sizeof($files));//number of files
    foreach($files as $item)
    {
      $fla_file = $item[0];
      $swf_file = $item[1];
      $swf_contents = file_get_contents($swf_file);
      if($swf_contents === false)
        throw new Exception("Exporting from '$fla_file' to '$swf_file' failed");
      $out->addPaddedStringN($swf_contents);
    }
  }
  catch(Exception $e)
  {
    $out->addUint32N(1);//error
    $out->addPaddedStringN($e->getMessage());//error descr
  }

  fsw_socket_send_packet($socket, $out);

  //cleanup
  foreach($files as $item)
  {
    $fla_file = $item[0];
    $swf_file = $item[1];

    if(is_file($fla_file))
    {
      echo "Removing tmp file $fla_file(" . filesize($fla_file) . ")\n";
      unlink($fla_file);
    }

    if(is_file($swf_file))
    {
      echo "Removing tmp file $swf_file(" . filesize($swf_file) . ")\n";
      unlink($swf_file);
    }
  }
}

function fsw_make_exporting_jsfl($fla2swf, $status_file, $errors_file, $close_flash = false)
{
  $jsfl = '';

  $jsfl .= "fl.compilerErrors.clear();\n";
  $jsfl .= "fl.outputPanel.clear();\n";

  foreach($fla2swf as $item)
  {
    $fla = $item[0];
    $swf = $item[1];
    $fla = fsw_normalize_path($fla, true);
    $swf = fsw_normalize_path($swf, true);

    $jsfl .= "fl.openDocument(\"file:///$fla\");\n";
    $jsfl .= "var ppath = \"file:///$fla.xml\";\n";
    $jsfl .= "fl.getDocumentDOM().exportPublishProfile(ppath);\n";
    $jsfl .= "var xml = FLfile.read(\"file:///$fla.xml\");";
   // replace the publish path for swf
    $jsfl .= "var from = xml.indexOf(\"<flashFileName>\");\n";
    $jsfl .= "var to = xml.indexOf(\"</flashFileName>\");\n";
    $jsfl .= "var delta = xml.substring(from, to);\n";
    $jsfl .= "xml = xml.split(delta).join(\"<flashFileName>$swf\");\n";
    $jsfl .= "FLfile.write(ppath, xml);\n";
    $jsfl .= "fl.getDocumentDOM().importPublishProfile(ppath);\n";
    // save and publish the fla
    $jsfl .= "fl.saveDocument(fl.getDocumentDOM());\n";
    $jsfl .= "fl.getDocumentDOM().publish();\n";
    $jsfl .= "FLfile.remove(ppath);\n";

    $jsfl .= "fl.closeDocument(fl.getDocumentDOM(), false);\n";

    //TODO: the most clean version which doesn't seem to be working
    //$jsfl .= "var doc = fl.openDocument(\"file:///$fla\");\n";
    //$jsfl .= "doc.exportSWF(\"file:///$swf\", true);\n";
    //$jsfl .= "doc.close(false);\n";
  }

  $status_file = fsw_normalize_path($status_file, true);
  $errors_file = fsw_normalize_path($errors_file, true);

  $jsfl .= "fl.outputPanel.save(\"file:///$status_file\");\n";
  $jsfl .= "fl.compilerErrors.save(\"file:///$errors_file\");\n";

  if($close_flash)
    $jsfl .= "flash.quit()\n";

  return $jsfl;
}

function fsw_exec_jsfl($jsfl, $status_file)
{
  $tmp_file = fsw_make_tmp_file_name(mt_rand(0, 1000) . "_tmp.jsfl");
  if(file_put_contents($tmp_file, $jsfl) === false)
    throw new Exception("Could not write to file '$tmp_file'");

  $cwd = getcwd();
  chdir(dirname($tmp_file));
  pclose(popen("open " . basename($tmp_file), "r"));
  chdir($cwd);

  echo("Executing jsfl script...\n");
  //waiting for operation to complete
  while(!file_exists($status_file))
    usleep(1000);

  unlink($tmp_file);
}

$HOST = fsw_autoguess_host();
$PORT = FSW_DEFAULT_PORT;

array_shift($argv);
foreach(fsw_parse_argv($argv) as $key => $value)
{
  switch($key)
  {
    case 'host':
      $HOST = $value;
      break;
    case 'port':
      $PORT = $value;
      break;
  }
}

$server = new fswServer($HOST, $PORT);
$handler = new fswNetHandler('fsw_process_client_request');
$server->setClientHandler($handler);
$server->start();


