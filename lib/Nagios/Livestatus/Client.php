<?php

namespace Nagios\Livestatus;

use \BadFunctionCallException;
use \InvalidArgumentException;
use \RuntimeException;

class Client
{
    protected $socketType = "unix";
    protected $socketPath = "/var/run/nagios/rw/live";
    protected $socketAddress = "";
    protected $socketPort = "";
    protected $socketTimeout = array();

    protected $socket = null;

    protected $query = null;
    protected $table = null;
    protected $headers = null;
    protected $columns = array();
    protected $outputFormat = null;
    protected $authUser = null;
    protected $limit = null;

    public function __construct(array $conf)
    {
        if (!function_exists("socket_create")) {
            throw new BadFunctionCallException("The PHP function socket_create is not available.");
        }

        foreach ($conf as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            } else {
                throw new InvalidArgumentException("The option '$key' is not recognised.");
            }
        }

        switch ($this->socketType) {
            case "unix":
                if (strlen($this->socketPath) == 0) {
                    throw new InvalidArgumentException("The option socketPath must be supplied for socketType 'unix'.");
                }

                if (!file_exists($this->socketPath) || !is_readable($this->socketPath) || !is_writable($this->socketPath)) {
                    throw new InvalidArgumentException("The supplied socketPath '{$this->socketPath}' is not accessible to this script.");
                }

                break;
            case "tcp":
                if (strlen($this->socketAddress) == 0) {
                    throw new InvalidArgumentException("The option socketAddress must be supplied for socketType 'tcp'.");
                }

                if (strlen($this->socketPort) == 0) {
                    throw new InvalidArgumentException("The option socketPort must be supplied for socketType 'tcp'.");
                }

                break;
            default:
                throw new InvalidArgumentException("Socket Type is invalid. Must be one of 'unix' or 'tcp'.");
        }

        $this->reset();
    }

    public function get($table)
    {
        if (!is_string($table)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->table = $table;
        return $this;
    }

    public function column($column)
    {
        if (!is_string($column)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->columns[] = $column;
        return $this;
    }

    public function headers($boolean)
    {
        if (!is_bool($boolean)) {
            throw new InvalidArgumentException("A boolean must be supplied.");
        }

        if ($boolean === true) {
            $this->headers = "on";
        } else {
            $this->headers = "off";
        }

        return $this;
    }


    public function columns(array $columns)
    {
        if (!is_array($columns)) {
            throw new InvalidArgumentException("An array must be supplied.");
        }

        $this->columns = $columns;
        return $this;
    }

    public function filter($filter)
    {
        if (!is_string($filter)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->query .= "Filter: " . $filter . "\n";
        return $this;
    }

    public function stat($stat)
    {
        return $this->stats($stat);
    }

    public function stats($stats)
    {
        if (!is_string($stats)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->query .= "Stats: " . $stats . "\n";
        return $this;
    }

    public function statsAnd($statsAnd)
    {
        if (!is_int($statsAnd)) {
            throw new InvalidArgumentException("An integer must be supplied.");
        }

        $this->query .= "StatsAnd: " . $statsAnd . "\n";
        return $this;
    }

    public function statsNegate()
    {
        $this->query .= "StatsNegate:\n";
        return $this;
    }

    public function lor($orLines)
    {
        return $this->logicalOr($orLines);
    }

    public function logicalOr($orLines)
    {
        if (!is_int($orLines)) {
            throw new InvalidArgumentException("An integer must be supplied.");
        }

        $this->query .= "Or: " . $orLines . "\n";
        return $this;
    }

    public function logicalAnd($andLines)
    {
        if (!is_int($andLines)) {
            throw new InvalidArgumentException("An integer must be supplied.");
        }

        $this->query .= "And: " . $andLines . "\n";
        return $this;
    }

    public function negate()
    {
        $this->query .= "Negate:\n";
        return $this;
    }

    public function parameter($parameter)
    {
        if (!is_string($parameter)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        if (trim($parameter) === "") {
            return $this;
        }

        $this->query .= $this->checkEnding($parameter);
        return $this;
    }

    public function outputFormat($outputFormat)
    {
        if (!is_string($outputFormat)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->outputFormat = $outputFormat;
        return $this;
    }

    public function limit($limit)
    {
        if (!is_int($limit)) {
            throw new InvalidArgumentException("An integer must be supplied.");
        }

        $this->limit = "Limit: " . $limit . "\n";
        return $this;
    }

    public function authUser($authUser)
    {
        if (!is_string($parameter)) {
            throw new InvalidArgumentException("A string must be supplied.");
        }

        $this->authUser = "AuthUser: " . $authUser . "\n";
        return $this;
    }

    public function execute($query = null)
    {
        $this->openSocket();

        $response = $this->runQuery($query);

        $this->closeSocket();

        return $response;
    }

    public function executeAssoc()
    {
        $this->openSocket();

        $response = $this->runQuery();

        if (count($this->columns) > 0) {
            $headers = $this->columns;
        } else {
            $headers = array_shift($response);
        }

        $cols = count($headers);
        $rows = count($response);
        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                $response[$i][$headers[$j]] = $response[$i][$j];
            }
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

    public function buildRequest($request = null)
    {
        // Check if request was supplied
        if (!is_null($request)) {
            $request = $this->checkEnding($request);
        } else {
            $request = "GET " . $this->table . "\n";

            if ($this->columns) {
                $request .= "Columns: " . implode(" ", $this->columns) . "\n";
                if ($this->headers) {
                    $request .= "ColumnHeaders: " . $this->headers . "\n";
                }
            }

            if (!is_null($this->query)) {
                $request .= $this->query;
            }

            if (!is_null($this->outputFormat)) {
                $request .= "OutputFormat: " . $this->outputFormat . "\n";
            }

            if (!is_null($this->authUser)) {
                $request .= $this->authUser;
            }

            if (!is_null($this->limit)) {
                $request .= $this->limit;
            }
        }

        $request .= "ResponseHeader: fixed16\n";
        $request .= "\n";

        return $request;
    }

    protected function openSocket()
    {
        if (!is_null($this->socket)) {
            // Assume socket still good and continue
            return;
        }

        if ($this->socketType === "unix") {
            $this->socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        } elseif ($this->socketType === "tcp") {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        }

        if (!$this->socket) {
            $this->socket = null;
            throw new RuntimeException("Could not create socket.");
        }

        if ($this->socketType === "unix") {
            $result = socket_connect($this->socket, $this->socketPath);
        } elseif ($this->socketType === "tcp") {
            $result = socket_connect($this->socket, $this->socketAddress, $this->socketPort);
        }

        if (!$result) {
            $this->closeSocket();
            throw new RuntimeException("Unable to connect to socket.");
        }

        if ($this->socketType === "tcp") {
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
        $this->query = null;
        $this->table = "hosts";
        $this->headers = "off";
        $this->columns = array();
        $this->outputFormat = "json";
        $this->authUser = null;
        $this->limit = null;
    }

    protected function readSocket($length)
    {
        $offset = 0;
        $socketData = "";

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

    protected function checkEnding($string)
    {
        if ($string[strlen($string)-1] !== "\n") {
            $string .= "\n";
        }

        return $string;
    }

    protected function runQuery($query = null)
    {
        $query = $this->buildRequest($query);

        // Send the query to MK Livestatus
        socket_write($this->socket, $query);

        // Read 16 bytes to get the status code and body size
        $header = $this->readSocket(16);

        $status = substr($header, 0, 3);
        $length = intval(trim(substr($header, 4, 11)));

        $response = $this->readSocket($length);

        // Check for errors. A 200 reponse means request was OK.
        // Any other response is a failure.
        if ($status != "200") {
            throw new RuntimeException("Error response from Nagios MK Livestatus: " . $response);
        }

        if ($this->outputFormat === "json") {
            $response = json_decode(utf8_encode($response));
        }

        if (is_null($response)) {
            throw new RuntimeException("The response was invalid.");
        }

        return $response;
    }
}
