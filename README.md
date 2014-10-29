Nagios MK Livestatus Client
===========================

This package implements a PHP OO client for talking to the MK Livestatus
Nagios Event Broker.

This implementation is based on Lars Michelsen's
[LivestatusSlave](http://nagios.larsmichelsen.com/livestatusslave/).

Requirements
------------

* PHP 5.3.1+
* Sockets enabled
* JSON enabled

Usage
-----

``` php
<?php

use Nagios\Livestatus\Client;

$options = array(
    'socketType' => 'tcp',
    'socketAddress' => '10.253.14.22',
    'socketPort' => '6557',
);

$client = new Client($options);

$response = $client
    ->get('hosts')
    ->column('host_name')
    ->column('state')
    ->execute();

foreach ($response as $host) {
    print $host[0] . ": " . $host[1] . "\n";
}

$client->command(
	array(
		'ACKNOWLEDGE_SVC_PROBLEM',
		'example.com',
		'some service', 2, 0, 1,
		'username', 'Example comment'));
```

Installation
------------

In composer add a dependancy on `aashley/nagios-livestatus-client`
