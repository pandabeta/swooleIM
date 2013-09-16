<?php

/**
* HTTP Server
* @author Tianfeng.Han
* @link http://www.swoole.com/
* @package Swoole
* @subpackage net.protocol
*/
include 'Request.php';
class HttpServer
{
    /**
* @var \Swoole\Server
*/
    public $server;
    public $config = array();
    protected $log;

    protected $mime_types;
    protected $static_dir;
    protected $static_ext;
    protected $dynamic_ext;
    protected $document_root;
    protected $deny_dir;

    protected $buffer = array();
    protected $buffer_maxlen = 65535; //最大POST尺寸，超过将写文件

    const SOFTWARE = "Swoole";
    const HTTP_ETX = "\r\n\r\n";
    const HTTP_SPLIT = "\r\n";

    function __construct($config = array())
    {
        define('SWOOLE_SERVER', true);
        $mimes = require( 'Mimes.php' );
        $this->mime_types = array_flip($mimes);
        $this->config = $config;
       
    }

    function setLogger($log)
    {
        $this->log = $log;
    }

    function log($msg, $type = 'INFO')
    {
        $this->log->put($type, $msg);
    }

    function onStart($serv)
    {
    }

    function onShutdown($serv)
    {
        $this->log(self::SOFTWARE . " shutdown");
    }

    function onConnect($serv, $client_id, $from_id)
    {
        $this->log("client[#$client_id@$from_id] connect");
    }

    function onClose($serv, $client_id, $from_id)
    {
        $this->log("client[#$client_id@$from_id] close");
        unset($this->buffer[$client_id]);
    }

    protected function checkData($client_id, $data)
    {
        if (!isset($this->buffer[$client_id])) {
            $this->buffer[$client_id] = $data;
        } else {
            $this->buffer[$client_id] .= $data;
        }
        //HTTP结束符
        if (substr($data, -4, 4) != self::HTTP_ETX) {
            return false;
        }
    }

    /**
* 接收到数据
* @param $client_id
* @param $data
* @return unknown_type
*/
    function onReceive($serv, $client_id, $from_id, $data)
    {
        //检测request data完整性
        //请求不完整，继续等待
        if ($this->checkData($client_id, $data) === false) {
            return true;
        }
        //完整的请求
        $data = $this->buffer[$client_id];
        //解析请求
        $request = $this->parse_request($data);
        if ($request === false) {
            $this->server->close($client_id);
            return false;
        }
        //处理请求，产生response对象
        $response = $this->onRequest($request);
        //发送response
        $this->response($client_id, $response);
        //回收内存
        unset($data);
        $request->unsetGlobal();
        unset($request);
        unset($response);
        //清空buffer
        $this->buffer[$client_id] = "";
        $this->server->close($client_id);
    }

    /**
* 解析form_data格式文件
* @param $part
* @param $request
* @param $cd
* @return unknown_type
*/
    function parse_form_data($part, &$request, $cd)
    {
        $cd = '--' . str_replace('boundary=', '', $cd);
        $form = explode($cd, $part);
        foreach ($form as $f) {
            if ($f === '') continue;
            $parts = explode(self::HTTP_ETX, $f);
            $head = $this->parse_head(explode(self::HTTP_SPLIT, $parts[0]));
            if (!isset($head['Content-Disposition'])) continue;
            $meta = $this->parse_cookie($head['Content-Disposition']);
            if (!isset($meta['filename'])) {
                //checkbox
                if (substr($meta['name'], -2) === '[]') $request->post[substr($meta['name'], 0, -2)][] = trim($parts[1]);
                else $request->post[$meta['name']] = trim($parts[1]);
            }
            else
            {
                $file = trim($parts[1]);
                $tmp_file = tempnam('/tmp', 'sw');
                file_put_contents($tmp_file, $file);
                if (!isset($meta['name'])) $meta['name'] = 'file';
                $request->file[$meta['name']] = array('name' => $meta['filename'],
                    'type' => $head['Content-Type'],
                    'size' => strlen($file),
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $tmp_file);
            }
        }
    }

    /**
* 头部解析
* @param $headerLines
* @return unknown_type
*/
    function parse_head($headerLines)
    {
        $header = array();
        foreach ($headerLines as $k => $head) {
            $head = trim($head);
            if (empty($head)) continue;
            list($key, $value) = explode(':', $head);
            $header[trim($key)] = trim($value);
        }
        return $header;
    }

    /**
* 解析Cookies
* @param $cookies
* @return unknown_type
*/
    function parse_cookie($cookies)
    {
        $_cookies = array();
        $blocks = explode(";", $cookies);
        foreach ($blocks as $cookie) {
            list ($key, $value) = explode("=", $cookie);
            $_cookies[trim($key)] = trim($value, "\r\n \t\"");
        }
        return $_cookies;
    }

    /**
* 解析http请求
* @param $data
* @return \Swoole\Request or bool
*/
    function parse_request($data)
    {
        $parts = explode(self::HTTP_ETX, $data, 2);
        // parts[0] = HTTP头;
        // parts[1] = HTTP主体，GET请求没有body
        $headerLines = explode(self::HTTP_SPLIT, $parts[0]);
        // HTTP协议头,方法，路径，协议[RFC-2616 5.1]
        $_http_method = explode(' ', $headerLines[0], 3);
        //错误的协议
        if(count($_http_method) < 3) return false;
        $request = new Request();
        list($request->meta['method'], $request->meta['uri'], $request->meta['protocol']) = $_http_method;
        //$this->log($headerLines[0]);
        //错误的HTTP请求
        if (empty($request->meta['method']) or empty($request->meta['uri']) or empty($request->meta['protocol']))
        {
            return false;
        }
        unset($headerLines[0]);
        //解析Head
        $request->head = $this->parse_head($headerLines);
        $url_info = parse_url($request->meta['uri']);
        $request->meta['path'] = $url_info['path'];
        if (isset($url_info['fragment'])) $request->meta['fragment'] = $url_info['fragment'];
        if (isset($url_info['query'])) {
            parse_str($url_info['query'], $request->get);
        }
        //POST请求,有http body
        if ($request->meta['method'] === 'POST')
        {
            $cd = strstr($request->head['Content-Type'], 'boundary');
            if (isset($request->head['Content-Type']) and $cd !== false)
            {
                $this->parse_form_data($parts[1], $request, $cd);
            }
            else parse_str($parts[1], $request->post);
        }
        //解析Cookies
        if (!empty($request->head['Cookie']))
        {
            $request->cookie = $this->parse_cookie($request->head['Cookie']);
        }
        return $request;
    }

    /**
* 发送响应
* @param $client_id
* @param \Swoole\Response $response
* @return unknown_type
*/
    function response($client_id, $response)
    {
        if (!isset($response->head['Date'])) $response->head['Date'] = gmdate("D, d M Y H:i:s T");
        if (!isset($response->head['Server'])) $response->head['Server'] = self::SOFTWARE;
        if (!isset($response->head['KeepAlive'])) $response->head['KeepAlive'] = 'off';
        if (!isset($response->head['Connection'])) $response->head['Connection'] = 'close';
        if (!isset($response->head['Content-Length'])) $response->head['Content-Length'] = strlen($response->body);

        $out = $response->head();
        $out .= $response->body;
        $this->server->send($client_id, $out);
    }

    function http_error($code, $response, $content = '')
    {

        $response->send_http_status($code);
        $response->head['Content-Type'] = 'text/html';
        //$response->body = \Swoole\Error::info(\Swoole\Response::$HTTP_HEADERS[$code], "<p>$content</p><hr><address>" . self::SOFTWARE . " at {$this->server->host} Port {$this->server->port}</address>");
    }

    /**
* 处理请求
* @param $request
* @return unknown_type
*/
    function onRequest($request)
    {
        $response = new Response();
        //请求路径
        if ($request->meta['path'][strlen($request->meta['path']) - 1] == '/') {
            $request->meta['path'] .= $this->config['request']['default_page'];
        }
        if($this->doStaticRequest($request, $response))
        {
             //pass
        }
        /* 动态脚本 */
        elseif (isset($this->dynamic_ext[$request->ext_name]) or empty($ext_name))
        {
            $this->process_dynamic($request, $response);
        }
        else
        {
            $this->http_error(404, $response, "Http Not Found({($request->meta['path']})");
        }
        return $response;
    }

  

}