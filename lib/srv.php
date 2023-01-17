<?php
namespace wesrv;
require('const.php');

use Exception;

class srv {
    private $close = false;
    private $tcp = null;
    private $clients = [];
    private $clientsAuth = [];
    private $msg = null;
    private $backlog = [];
    private $key = 'some-random-key';
    private $lastClientKey = 0;
    private $backlog_size = 50;

    function __construct(
            $udp_address = '127.0.0.1',
            $udp_port = 8531,
            $tcp_address = '127.0.0.1',
            $tcp_port = 8531,
            $key = 'some-random-key',
            $backlog_size = 50
        ) {
        $this->backlog_size = $backlog_size;
        $this->key = $key;
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
    }

    function accept() {
        $client = socket_accept($this->tcp);
        if ($client) {
            socket_set_nonblock($client);
            $k = $this->lastClientKey++;
            $this->clients[$k] = $client;
            $authPayload = base64_encode(random_bytes(40));
            if (socket_write($client, 'auth://' . $authPayload . WMSG_SEPARATOR) !== false) {
                $this->clientsAuth[$k] = [ 'payload' => $authPayload, 'auth' => false, 'ctime' => time() ];
            }
        }
    }

    function read() {
        $data = $this->msg->receive($addr, $port);
        if ($data === null) { return; }
        /* if backlog is full, drop oldest message */
        if (count($this->backlog) > $this->backlog_size) { 
            echo 'Drop message' . PHP_EOL;
            array_shift($this->backlog); 
        }
        array_push($this->backlog, $data . WMSG_SEPARATOR);
    }

    function clean_unauth_client () {
        $now = time();
        foreach ($this->clients as $k => $_) {
            if (!isset($this->clientsAuth[$k])) { $this->end_client($k); continue; }
            if ($this->clientsAuth[$k]['auth']) { continue; }
            if ($now - $this->clientsAuth[$k]['ctime'] > 10) {
                echo 'Client ' . $k . ' waited too long for auth' . PHP_EOL;
                $this->end_client($k);
            }
        }
    }

    function write() {
        $msg = array_shift($this->backlog);
        foreach ($this->clients as $k => $client) {
            socket_write($client, $msg);
        }
    }

    function end_client($k) {
        if (empty($this->clients[$k])) { return; }
        $client = $this->clients[$k];
        unset($this->clients[$k]);
        if (!empty($this->clientsAuth[$k])) { unset($this->clientsAuth[$k]); }
        if ($client) {
            socket_close($client);
        }
    }
 
    function run() {
        do {
            $read = array_merge([$this->tcp, $this->msg->socket], $this->clients);
            $n = null;
            $sec = 1;
        
            if (@socket_select($read, $n, $n, $sec) < 1) {
                $this->clean_unauth_client();
                continue; 
            }
            foreach ($read as $socket) {
                if ($this->tcp === $socket) {
                    $this->accept();
                    continue;
                }

                if ($this->msg->socket === $socket) {
                    $this->read();
                    continue;
                }

                if (($k = array_search($socket, $this->clients, true)) !== false) {
                    $data = socket_read($socket, 576);
                    if ($data === false || $data === '' || $data === 0) {
                        $this->end_client($k);
                        continue;
                    }

                    if (substr($data, 0, 7) === 'auth://') {
                        if (!isset($this->clientsAuth[$k])) { $this->end_client($k); continue; }
                        if ($this->clientsAuth[$k]['auth']) { continue; }
                        if (hash_hmac('sha1', $this->clientsAuth[$k]['payload'], $this->key, false) === trim(substr($data, 7))) {
                            $this->clientsAuth[$k]['auth'] = true;
                            echo 'Client ' . $k . ' auth done' . PHP_EOL;
                        } else {
                            $this->end_client($k);
                        }
                    }
                    
                    continue;
                }
            }
            $this->write();
        } while(!$this->close);
    }
}