<?php

namespace wesrv;

use Exception;

class msg {
    public $socket = null;
    private $address;
    private $port;
    private $key;

    function __construct(string $address = '127.0.0.1', int $port = 8531, string $key = 'some-random-key') {
        $this->address = $address;
        $this->port = $port;
        $this->key = $key;       

        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$this->socket) { throw new Exception('Socket creation failed'); }
        socket_set_nonblock($this->socket);
    }

    function close() {
        if ($this->socket !== null) {
            socket_close($this->socket);
        }
        $this->socket = null;
    }

    function send($msg) {
        $length = strlen($msg);
        $sig = $this->sign($msg, $length);
        if ($length + 22 > 576) { error_log('Packet too big, not sending'); return false; }
        $ret = socket_sendto($this->socket, $this->pack($msg, $sig, $length), $length + 22, 0, $this->address, $this->port);
        if ($ret === false) { error_log('Error sending packet.'); return false; }
        if ($ret !== $length + 22) { error_log('Partial packet sent.'); return false; }
        return true;
    }

    function receive (&$address, &$port) {
        $packet = null;
        socket_recvfrom($this->socket, $packet, 576, 0, $address, $port);
        $content = $this->unpack($packet);
        if ($content === false) { return null; }
        if ($content['dgst'] !== $this->sign($content['msg'])) { return null; }
        return $content['msg'];
    }

    function sign ($msg) {
        $sig = hash_hmac('sha1', $msg, $this->key, true);
        return $sig;
    }

    function pack ($msg, $sig, $l) {
        return pack('n', $l) . $sig . $msg;
     }
     
     function unpack($packet) {
        $l = unpack('n', $packet);
        if (!(strlen($packet) === $l[1] + 22)) { return false; }
        $dgst = substr($packet, 2, 20);
        $msg = substr($packet, 22, $l[1]);
        return ['length' => $l, 'msg' => $msg, 'dgst' => $dgst];
     }
     

}