<?php

namespace wesrv;

use Exception;

class srv {
    private $close = false;
    private $tcp = null;
    private $clients = [];
    private $msg = null;
    private $backlog = [];

    function __construct(
            $udp_address = '127.0.0.1',
            $udp_port = 8531,
            $tcp_address = '127.0.0.1',
            $tcp_port = 8531,
            $key = 'some-random-key',
            $backlog_size = 50
        ) {
        $this->backlog_size = $backlog_size;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'close']);
        pcntl_signal(SIGINT, [$this, 'close']);
        try {
            $this->msg = new msg($udp_address, $udp_port, $key);
        } catch (Exception $e) {
            $this->close();
            throw new Exception('Cannot initialize msg');
        }
        if (!socket_bind($this->msg->socket, $udp_address, $udp_port)) { $this->close(); throw new Exception('Unable to bind udp'); }

        $this->tcp = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->tcp) { $this->close(); throw new Exception('Unable to create tcp socket'); }
        if (!socket_bind($this->tcp, $tcp_address, $tcp_port)) { $this->close(); throw new Exception('Unable to bind tcp'); }
        if (!socket_listen($this->tcp)) { $this->close(); throw new Exception('Unable to listen to tcp'); }
    }

    function close() {
        echo 'Closing started' . PHP_EOL;
        $this->close = true;
        $this->msg->close();
        foreach ($this->clients as $k => $client) {
            if ($client) {
                socket_close($client);
            }
            unset($this->clients[$k]);
        }
        if ($this->tcp !== null) {
            socket_close($this->tcp);
        }
        echo 'Closing done' . PHP_EOL;
    }

    function accept() {
        echo 'Accept started' . PHP_EOL;
        $client = socket_accept($this->tcp);
        if ($client) {
            socket_set_nonblock($client);
            $this->clients[] = $client;
        }
        echo 'Accept done' . PHP_EOL;

    }

    function read($socket) {
        echo 'Read started' . PHP_EOL;
        $data = $this->msg->receive($addr, $port);
        if ($data === null) { return; }
        /* if backlog is full, drop oldest message */
        if (count($this->backlog) > $this->backlog_size) { 
            echo 'Drop message' . PHP_EOL;
            array_shift($this->backlog); 
        }
        array_push($this->backlog, $data);
        echo 'Read done' . PHP_EOL;
    }

    function write() {
        echo 'Write started' . PHP_EOL;
        $msg = array_shift($this->backlog);
        echo 'Messgage ' . $msg  . PHP_EOL;
        foreach ($this->clients as $client) {
            socket_write($client, $msg);
        }
        echo 'Write done' . PHP_EOL;
    }

    function end_client($k) {
        $client = $this->clients[$k];
        unset($this->clients[$k]);
        if ($client) {
            socket_close($client);
        }
    }
 
    function run() {
        do {
            $read = array_merge([$this->tcp, $this->msg->socket], $this->clients);
            $n = null;
            $sec = 1;
        
            if (@socket_select($read, $n, $n, $sec) < 1) { continue; }
            foreach ($read as $socket) {
                if ($this->tcp === $socket) {
                    $this->accept();
                    continue;
                }

                if ($this->msg->socket === $socket) {
                    $this->read($socket);
                    continue;
                }

                if (($k = array_search($socket, $this->clients, true)) !== false) {
                    $data = socket_read($socket, 576);
                    if ($data === false || $data === '' || $data === 0) {
                        $this->end_client($k);
                        continue;
                    }
                    
                    continue;
                }
            }
            $this->write();
        } while(!$this->close);
    }
}