<?php

namespace Nagios\Livestatus\Test;

require __DIR__.'/include.php';

use PHPUnit_Framework_TestCase;
use Nagios\Livestatus\Client;

class NagiosLivestatusClientTest extends PHPUnit_Framework_TestCase
{
    public function createTcpClient()
    {
        $options = array(
            'socketType' => 'tcp',
            'socketAddress' => '10.248.14.22',
            'socketPort' => '6557'
        );

        $client = new Client($options);

        return $client;
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidSocketType()
    {
        $options = array(
            'socketType' => 'foo',
        );

        $client = new Client($options);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSocketTypeUnixNoPath()
    {
        $options = array(
            'socketType' => 'unix',
            'socketPath' => ''
        );

        $client = new Client($options);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSocketTypeTcpNoAddress()
    {
        $options = array(
            'socketType' => 'tcp',
            'socketAddress' => '',
        );

        $client = new Client($options);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testSocketTypeTcpNoPort()
    {
        $options = array(
            'socketType' => 'tcp',
            'socketAddress' => '10.253.14.22',
            'socketPort' => ''
        );

        $client = new Client($options);
    }

    public function testGetHosts()
    {
        $response = $this->createTcpClient()
            ->get('hosts')
            ->execute();

        $this->assertGreaterThanOrEqual(2, count($response), "No hosts where returned by the search");
        $this->assertEquals("accept_passive_checks", $response[0][0], "First column of header row not as expected");
    }

    public function testGetHostsAssoc()
    {
        $response = $this->createTcpClient()
            ->get('hosts')
            ->executeAssoc();

        $this->assertGreaterThanOrEqual(1, count($response), "No hosts where returned by the search");
        $this->assertNotEquals("accept_passive_checks", $response[0][0], "Header row still in response");
        $this->assertArrayHasKey("accept_passive_checks", $response[0], "Associative keys not set");
    }

    public function testGetHostColumns()
    {
        $response = $this->createTcpClient()
            ->get('hosts')
            ->column('host_name')
            ->column('host_alias')
            ->execute();

        $this->assertGreaterThanOrEqual(2, count($response), "No hosts where returned by the search");
        $this->assertCount(2, $response[0], "Incorrect number of columns returned");
    }

    public function testGetHostColumnsAssoc()
    {
        $response = $this->createTcpClient()
            ->get('hosts')
            ->column('host_name')
            ->column('host_alias')
            ->executeAssoc();

        $this->assertGreaterThanOrEqual(2, count($response), "No hosts where returned by the search");
        $this->assertCount(4, $response[0], "Incorrect number of columns returned");
        $this->assertArrayHasKey("host_name", $response[0], "host_name column not available");
        $this->assertArrayHasKey("host_alias", $response[0], "host_alias column not available");
    }

    public function testGetHostFilter()
    {
        $allHosts = $this->createTcpClient()
            ->get('hosts')
            ->execute();

        $filteredHosts = $this->createTcpClient()
            ->get('hosts')
            ->filter('state = 2')
            ->execute();

        $this->assertGreaterThanOrEqual(2, count($allHosts), "No hosts where returned by the search");
        $this->assertNotEquals(count($allHosts), count($filteredHosts), "Filter returned same hosts list");
    }
}
