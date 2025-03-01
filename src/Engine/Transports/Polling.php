<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;

class Polling extends Transport
{
    public $name = 'polling';
    public $chunks = '';
    public $shouldClose = null;
    public $writable = false;

    public function onRequest($req)
    {
        $res = $req->res;

        if ('GET' === $req->method) {
            $this->onPollRequest($req, $res);
        } elseif ('POST' === $req->method) {
            $this->onDataRequest($req, $res);
        } else {
            $res->writeHead(500);
            $res->end();
        }
    }

    public function onPollRequest($req, $res)
    {
        if ($this->req) {
            echo('request overlap');
            // assert: this.res, '.req and .res should be (un)set together'
            $this->onError('overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->req = $req;
        $this->res = $res;

        $req->onClose = [$this, 'pollRequestOnClose'];
        $req->cleanup = [$this, 'pollRequestClean'];

        $this->writable = true;
        $this->emit('drain');

        // if we're still writable but had a pending close, trigger an empty send
        if ($this->writable && $this->shouldClose) {
            echo('triggering empty send to append close packet');
            $this->send([['type' => 'noop']]);
        }
    }

    public function pollRequestOnClose()
    {
        $this->onError('poll connection closed prematurely');
        $this->pollRequestClean();
    }

    public function pollRequestClean()
    {
        if (isset($this->req)) {
            $this->req->res = null;
            $this->req->onClose = $this->req->cleanup = null;
            $this->req = $this->res = null;
        }
    }

    public function onDataRequest($req, $res)
    {
        if (isset($this->dataReq)) {
            // assert: this.dataRes, '.dataReq and .dataRes should be (un)set together'
            $this->onError('data request overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->dataReq = $req;
        $this->dataRes = $res;
        $req->onClose = [$this, 'dataRequestOnClose'];
        $req->onData = [$this, 'dataRequestOnData'];
        $req->onEnd = [$this, 'dataRequestOnEnd'];
    }

    public function dataRequestCleanup()
    {
        $this->chunks = '';
        $this->dataReq->res = null;
        $this->dataReq->onClose = $this->dataReq->onData = $this->dataReq->onEnd = null;
        $this->dataReq = $this->dataRes = null;
    }

    public function dataRequestOnClose()
    {
        $this->dataRequestCleanup();
        $this->onError('data request connection closed prematurely');
    }

    public function dataRequestOnData($req, $data)
    {
        $this->chunks .= $data;
    }

    public function dataRequestOnEnd()
    {
        $this->onData($this->chunks);

        $headers = [
            'Content-Type' => 'text/html',
            'Content-Length' => 2,
            'X-XSS-Protection' => '0',
        ];

        $this->dataRes->writeHead(200, '', $this->headers($this->dataReq, $headers));
        $this->dataRes->end('ok');
        $this->dataRequestCleanup();
    }

    public function onData($data)
    {
        $packets = Parser::decodePayload($data);
        if (isset($packets['type'])) {
            if ('close' === $packets['type']) {
                $this->onClose();
                return false;
            } else {
                $packets = [$packets];
            }
        }

        foreach ($packets as $packet) {
            $this->onPacket($packet);
        }
    }

    public function onClose()
    {
        if ($this->writable) {
            // close pending poll request
            $this->send([['type' => 'noop']]);
        }
        parent::onClose();
    }

    public function send($packets)
    {
        $this->writable = false;
        if ($this->shouldClose) {
            echo('appending close packet to payload');
            $packets[] = ['type' => 'close'];
            call_user_func($this->shouldClose);
            $this->shouldClose = null;
        }
        $data = Parser::encodePayload($packets, $this->supportsBinary);
        $this->write($data);
    }

    public function write($data)
    {
        $this->doWrite($data);
        if (! empty($this->req->cleanup)) {
            call_user_func($this->req->cleanup);
        }
    }

    public function doClose($fn)
    {
        if (! empty($this->dataReq)) {
            $this->dataReq->destroy();
        }

        if ($this->writable) {
            $this->send([['type' => 'close']]);
            call_user_func($fn);
        } else {
            $this->shouldClose = $fn;
        }
    }
}
