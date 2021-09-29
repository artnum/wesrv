<?php

namespace wesrv;

use Exception;

class client {
    private $socket = null;
    function __construct($address = '127.0.0.1', $port = 8531, $key = 'some-random-key') {
        ignore_user_abort(true);
        $this->key = $key;
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket)  { throw new Exception('Unable create socket');}
        if (!socket_connect($this->socket, $address, $port)) { throw new Exception('Unable to connect'); }
        socket_set_nonblock($this->socket);
    }

    function event ($type, $data) {
        if (is_string($data)) {
            printf("event: %s\ndata: %s\n\n", $type, $data);
        } else {
            printf("event: %s\ndata: %s\n\n", $type, json_encode($data));
        }
        @ob_end_flush();
        flush();
    }

    function watch() {
        $run = true;
        $count = 0;
        do {
            $sec = 1;
            $n = null;
            $read = [$this->socket];
            if (socket_select($read, $n, $n, $sec) === false) { $run = false; }
            else {
                $count++;
                foreach ($read as $r) {
                    $data = socket_read($r, 576);
                    if ($data === false || $data === 0 || $data === '') { $run = false; break; }
                    if (substr($data, 0, 7) === 'auth://') {
                        echo 'Auth Request : ' . $data . PHP_EOL;
                        $sig = hash_hmac('sha1', substr($data, 7), $this->key, false);
                        socket_write($r, 'auth://' . $sig);
                        continue;
                    }
                    echo 'Message : "' . $data . '"' . PHP_EOL;
                }
            }
            if (connection_status() !== 0) { $run = false; }
        } while($run);
        socket_close($this->socket);
    }

    function run () {
        header('Cache-Control: no-cache', true);
        header('Content-Type: text/event-stream', true);
        $this->event('begin', ['time' => (new \DateTime())->format('c')]);
        $run = true;
        $count = 0;
        do {
            $sec = 1;
            $n = null;
            $read = [$this->socket];
            if (socket_select($read, $n, $n, $sec) === false) { $run = false; }
            else {
                $count++;
                foreach ($read as $r) {
                    $data = socket_read($r, 576);
                    if ($data === false || $data === 0 || $data === '') { $run = false; break; }
                    if (substr($data, 0, 7) === 'auth://') {
                        $sig = hash_hmac('sha1', substr($data, 7), $this->key, false);
                        socket_write($r, 'auth://' . $sig);
                        continue;
                    }
                    $this->event('message', $data);
                }

                if ($count > 10) {
                    $this->event('ping', ['time' => (new \DateTime())->format('c')]);
                    $count = 0;
                }
            }
            if (connection_status() !== 0) { $run = false; }
        } while($run);
        $this->event('end', ['time' => (new \DateTime())->format('c')]);
        socket_close($this->socket);
    }
}