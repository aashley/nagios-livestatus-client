<?php

namespace Nagios\Livestatus;

use \BadFunctionCallException;
use \InvalidArgumentException;
use \RuntimeException;

class Client
{
    protected $socketType = 'unix';
    protected $socketPath = '/var/run/nagios/rw/live';
    protected $socketAddress = '';
    protected $socketPort = '';
    protected $socketTimeout = array();

    protected $socket = null;

    protected $table = null;
    protected $columns = array();
    protected $filters = array();
    protected $parameters = array();
    protected $stats = array();

    public function __construct(array $conf)
    {
        if (!function_exists('socket_create')) {
            throw new BadFunctionCallException("The PHP function socket_create is not available.");
        }

        foreach ($conf as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            } else {
                throw new InvalidArgumentException("The option '$key' is not recognised");
            }
        }

        switch ($this->socketType) {
        case 'unix':
            if (strlen($this->socketPath) == 0) {
                throw new InvalidArgumentException("The option socketPath must be supplied for socketType 'unix'");
            }
            if (!file_exists($this->socketPath) || !is_readable($this->socketPath) || !is_writable($this->socketPath)) {
                throw new InvalidArgumentException("The supplied socketPath '{$this->socketPath}' is not accessible to this script.");
            }
            break;

        case 'tcp':
            if (strlen($this->socketAddress) == 0) {
                throw new InvalidArgumentException("The option socketAddress must be supplied for socketType 'tcp'");
            }
            if (strlen($this->socketPort) == 0) {
                throw new InvalidArgumentException("The option socketPort must be supplied for socketType 'tcp'");
            }
            break;

        default:
            throw new InvalidArgumentException('Socket Type is invalid. Must be one of unix or tcp');
        }

        $this->reset();
    }

    public function get($table)
    {
        $this->table = $table;
        return $this;
    }

    public function column($column)
    {
        $this->columns[] = $column;
        return $this;
    }

    public function columns(array $columns)
    {
        $this->columns = $columns;
        return $this;
    }

    public function filter($filter)
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function parameter($parameter)
    {
        $this->parameters[] = $parameter;
        return $this;
    }

    public function stat($stat)
    {
        $this->stats[] = $stat;
        return $this;
    }

    public function execute($query = null)
    {
        $this->openSocket();

        // Check if query was supplied or needs to be built
        if (is_null($query)) {
            $query = $this->buildRequest();
        }

        // Add necessary data to query
        $query .= "OutputFormat: json\n";
        $query .= "ResponseHeader: fixed16\n";
        $query .= "\n";

        // Send the query to MK Livestatus
        socket_write($this->socket, $query);

        // Read 16 bytes to get the status code and body size
        $read = $this->readSocket(16);

        $status = substr($read, 0, 3);
        $length = intval(trim(substr($read, 4, 11)));

        $read = $this->readSocket($length);

        // Check for errors. 200 means request was OK. anything else
        // fail.
        if ($status != '200') {
            throw new RuntimeException("Error response from Nagios MK Livestatus: " . $read);
        }

        $response = json_decode(utf8_encode($read));

        if (is_null($response)) {
            throw new RuntimeException("The response was invalid.");
        }

        $this->closeSocket();

        return $response;
    }

    public function command(array $command)
    {
        $this->openSocket();

        $fullcommand = sprintf("COMMAND [%lu] %s\n", time(), implode(';', $command));
        socket_write($this->socket, $fullcommand);
        $this->closeSocket();
    }

    public function reset()
    {
        $this->closeSocket();
    }

    protected function buildRequest()
    {
        $request = "GET " . $this->table . "\n";

        if (count($this->columns) > 0) {
            $request .= "Columns: " . implode(" ", $this->columns) . "\n";
        }

        foreach ($this->filters as $filter) {
            $request .= "Filter: " . $filter . "\n";
        }

        foreach ($this->parameters as $parameter) {
            $request .= $parameter . "\n";
        }

        foreach ($this->stats as $stat) {
            $request .= "Stats: " . $stat . "\n";
        }

        return $request;
    }

    protected function openSocket()
    {
        if (!is_null($this->socket)) {
            // Assume socket still good and continue
            return;
        }

        if ($this->socketType === 'unix') {
            $this->socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        } elseif ($this->socketType === 'tcp') {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        }

        if (!$this->socket) {
            $this->socket = null;
            throw new RuntimeException("Could not create socket");
        }

        if ($this->socketType === 'unix') {
            $result = socket_connect($this->socket, $this->socketPath);
        } elseif ($this->socketType === 'tcp') {
            $result = socket_connect($this->socket, $this->socketAddress, $this->socketPort);
        }

        if (!$result) {
            $this->closeSocket();
            throw new RuntimeException("Unable to connect to socket");
        }

        if ($this->socketType === 'tcp') {
            socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);
        }

        if ($this->socketTimeout) {
            socket_set_option($this->socket, SOCK_STREAM, SO_RCVTIMEO, $this->socketTimeout);
            socket_set_option($this->socket, SOCK_STREAM, SO_SNDTIMEO, $this->socketTimeout);
        }
    }

    protected function closeSocket()
    {
        if (is_resource($this->socket)) {
            socket_close($this->socket);
        }
        $this->socket = null;
        $this->table = 'hosts';
        $this->columns = array();
        $this->filters = array();
        $this->parameters = array();
        $this->stats = array();
    }

    protected function readSocket($length)
    {
        $offset = 0;
        $socketData = '';

        while ($offset < $length) {
            if (false === ($data = socket_read($this->socket, $length - $offset))) {
                throw new RuntimeException(
                    "Problem reading from socket: "
                    . socket_strerror(socket_last_error($this->socket))
                );
            }

            $dataLen = strlen($data);
            $offset += $dataLen;
            $socketData .= $data;

            if ($dataLen == 0) {
                break;
            }
        }

        return $socketData;
    }
}
