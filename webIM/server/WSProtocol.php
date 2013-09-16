<?php
include 'HttpServer.php';
//include 'Request.php';
include 'Response.php';

abstract class WSProtocol extends HttpServer
{
	const OPCODE_CONTINUATION_FRAME = 0x0;
	const OPCODE_TEXT_FRAME = 0x1;
	const OPCODE_BINARY_FRAME = 0x2;
	const OPCODE_CONNECTION_CLOSE = 0x8;
	const OPCODE_PING = 0x9;
	const OPCODE_PONG = 0xa;

	const CLOSE_NORMAL = 1000;
	const CLOSE_GOING_AWAY = 1001;
	const CLOSE_PROTOCOL_ERROR = 1002;
	const CLOSE_DATA_ERROR = 1003;
	const CLOSE_STATUS_ERROR = 1005;
	const CLOSE_ABNORMAL = 1006;
	const CLOSE_MESSAGE_ERROR = 1007;
	const CLOSE_POLICY_ERROR = 1008;
	const CLOSE_MESSAGE_TOO_BIG = 1009;
	const CLOSE_EXTENSION_MISSING = 1010;
	const CLOSE_SERVER_ERROR = 1011;
	const CLOSE_TLS = 1015;

	const WEBSOCKET_VERSION = 13;
	/**
	 * GUID.
	 *
	 * @const string
	 */
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	public $ws_list = array();
	public $connections = array();
	public $max_connect = 10000;
	/**
	 * Do the handshake.
	 *
	 * @param Swoole\Request $request
	 * @param Swoole\Response $response
	 * @throws \Exception
	 * @return bool
	 */
	public function doHandshake( Request $request, Response $response)
	{
		if (!isset($request->head['Sec-WebSocket-Key'])) {
			$this->log('Bad protocol implementation: it is not RFC6455.');
			return false;
		}
		
		$key = $request->head['Sec-WebSocket-Key'];
		if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $key) || 16 !== strlen(base64_decode($key)))
		{
			$this->log('Header Sec-WebSocket-Key: $key is illegal.');
			return false;
		}
		/**
		 * @TODO
		 * ? Origin;
		 * ? Sec-WebSocket-Protocol;
		 * ? Sec-WebSocket-Extensions.
		 */
		$response->send_http_status(101);
		$response->addHeader(array(
				'Upgrade' => 'websocket',
				'Connection' => 'Upgrade',
				'Sec-WebSocket-Accept' => base64_encode(sha1($key . static::GUID, true)),
				'Sec-WebSocket-Version' => self::WEBSOCKET_VERSION,
		));
		return true;
	}

	function cleanBuffer()
	{

	}
	function cleanConnection()
	{

	}
	abstract function onMessage($client_id, $message);
	
	/**
	 * Read a frame.
	 * @access public
	 * @throw \Exception
	*/
	public function onReceive($server, $client_id, $from_id, $data)
	{
		 $this->log("client_id=$client_id|from_id=$from_id");
		//建立连接
		if(!isset($this->connections[$client_id]))
		{
			$request = $this->parse_request($data);
			if ($request === false)
			{
				$this->log( "onReceive: close client_id = $client_id" );
				$this->server->close($client_id);
				return false;
			}
			$response = new Response();
			$this->doHandshake($request, $response);
			$this->response($client_id, $response);

			$conn = array('header' => $request->head, 'time' => time());
			$this->connections[$client_id] = $conn;

			if(count($this->connections) > $this->max_connect)
			{
				$this->cleanConnection();
			}
		   $this->log("websocket connected client_id = $client_id");
		}
		//缓存区没有数据
		else if(empty($this->ws_list[$client_id]))
		{
			$ws = $this->parse_wsframe($data);
			$this->opcodeSwitch($client_id, $ws);
			// $this->log("opcodeSwitch client_id = $client_id");
		}
		else
		{
			// $this->log("wait_data client_id = $client_id");
			$ws = $this->ws_list[$client_id];
			$ws['message'] .= $data;
			$message_len = strlen($ws['message']);
			if($ws['length'] == $message_len)
			{
				$this->onMessage($client_id, $ws);
				
				//$this->send( $client_id,  $ws['message'] );
				unset($this->ws_list[$client_id]);
			}
			else if(strlen($ws['message']) > $message_len)
			{
				$this->log("ws message too long $client_id");
				//TODO 出错了
			}
		}
	}
	
	function parse_wsframe($data)
	{
		//websocket头
		$ws = array();
		$data_offset = 0;
		//第一个字节 fin:1 rsv1:1 rsv2:1 rsv3:1 opcode:4
		$handle = ord($data[$data_offset]);
		$ws['fin'] = ($handle >> 7) & 0x1;
		$ws['rsv1'] = ($handle >> 6) & 0x1;
		$ws['rsv2'] = ($handle >> 5) & 0x1;
		$ws['rsv3'] = ($handle >> 4) & 0x1;
		$ws['opcode'] = $handle & 0xf;
		$data_offset++;

		//第二个字节 mask:1 length:7
		$handle = ord($data[$data_offset]);
		$ws['mask'] = ($handle >> 7) & 0x1;
		//0-125
		$ws['length'] = $handle & 0x7f;
		$length = &$ws['length'];
		$data_offset++;

		if(0x0 !== $ws['rsv1'] || 0x0 !== $ws['rsv2'] || 0x0 !== $ws['rsv3'])
		{
			$this->close(self::CLOSE_PROTOCOL_ERROR);
			return false;
		}
		if(0 === $length)
		{
			$ws['message'] = '';
			return $ws;
		}
		//126使用short作为长度
		elseif(0x7e === $length)
		{
			//2字节
			$handle = unpack('nl', substr($data, $data_offset, 2));
			$data_offset += 2;
			$length = $handle['l'];
		}
		//127使用int64作为长度
		elseif(0x7f === $length)
		{
			//8字节
			$handle = unpack('N*l', substr($data, $data_offset, 8));
			$data_offset += 8;
			$length = $handle['l2'];
			if($length > 0x7fffffffffffffff)
			{
				$this->log('Message is too long.');
				return false;
			}
		}
		if(0x0 === $ws['mask'])
		{
			$ws['finish'] = true;
			$ws['message'] = substr($data, $data_offset, $length);
			return $ws;
		}
		else
		{
			//int32
			$maskN = array_map('ord', str_split(substr($data, $data_offset, 4)));
			$data_offset += 4;
			$message = substr($data, $data_offset, $length);
			$maskC = 0;
			for($j = 0, $_length = $length; $j < $_length; ++$j)
			{
				$message[$j] = chr(ord($message[$j]) ^ $maskN[$maskC]);
				$maskC = ($maskC + 1) % 4;
			}
			$ws['message'] = $message;
			//数据包完整
			$ws['finish'] = (strlen($ws['message']) == $length);
			return $ws;
		}
	}
	/**
	 * Write a frame.
	 *
	 * @access public
	 * @param string $message Message.
	 * @param int $opcode Opcode.
	 * @param bool $end Whether it is the last frame of the message.
	 * @return int
	 */
	public function newFrame ($message, $opcode = self::OPCODE_TEXT_FRAME, $end = true )
	{
		$fin = true === $end ? 0x1 : 0x0;
		$rsv1 = 0x0;
		$rsv2 = 0x0;
		$rsv3 = 0x0;
		$mask = 0x1;
		$length = strlen($message);
		$out = chr(
				($fin << 7)
				| ($rsv1 << 6)
				| ($rsv2 << 5)
				| ($rsv3 << 4)
				| $opcode
		);

		if(0xffff < $length)
			$out .= chr(0x7f) . pack('NN', 0, $length);
		elseif(0x7d < $length)
		$out .= chr(0x7e) . pack('n', $length);
		else
			$out .= chr($length);

		$out .= $message;
		return $out;
	}

	/**
	 * Send a message.
	 *
	 * @access public
	 * @param string $message Message.
	 * @param int $opcode Opcode.
	 * @param bool $end Whether it is the last frame of the message.
	 * @return void
	 */
	public function send($client_id, $message, $opcode = self::OPCODE_TEXT_FRAME, $end = true)
	{
		if((self::OPCODE_TEXT_FRAME === $opcode or self::OPCODE_CONTINUATION_FRAME === $opcode) and false === (bool) preg_match('//u', $message))
		{
			$this->log('Message “%s” is not in UTF-8, cannot send it.', 2, 32 > strlen($message) ? substr($message, 0, 32) . '…' : $message);
		}
		else
		{
			$out = $this->newFrame($message, $opcode, $end);
			$this->server->send($client_id, $out);
		}
	}
	
	
	function opcodeSwitch($client_id, $ws)
	{
		switch($ws['opcode'])
		{
			//数据指令
			case self::OPCODE_BINARY_FRAME:
			case self::OPCODE_TEXT_FRAME:
				if(0x1 === $ws['fin'])
				{
					$this->onMessage($client_id, $ws);
				
				}
				else
				{
					$this->ws_list[$client_id] = &$ws;
				}
				break;
				//心跳
			case self::OPCODE_PING:
				$message = &$ws['message'];
				if(0x0 === $ws['fin'] or 0x7d < $ws['length'])
				{
					$this->close($client_id, self::CLOSE_PROTOCOL_ERROR);
					break;
				}
				$this->connections[$client_id]['time'] = time();
				$this->send($client_id, $message, self::OPCODE_PONG, true);
				break;
			case self::OPCODE_PONG:
				if(0 === $ws['fin'])
				{
					$this->close($client_id, self::CLOSE_PROTOCOL_ERROR);
				}
				break;
				//客户端关闭连接
			case self::OPCODE_CONNECTION_CLOSE:
				$length = &$frame['length'];
				if(1 === $length || 0x7d < $length)
				{
					$this->close($client_id, self::CLOSE_PROTOCOL_ERROR);
					break;
				}
				$code = self::CLOSE_NORMAL;
				$reason = null;
				if(0 < $length)
				{
					$message = &$frame['message'];
					$_code = unpack('nc', substr($message, 0, 2));
					$code = &$_code['c'];

					if(1000 > $code || (1004 <= $code && $code <= 1006) || (1012 <= $code && $code <= 1016) || 5000 <= $code)
					{
						$this->close($client_id, self::CLOSE_PROTOCOL_ERROR);
						break;
					}

					if(2 < $length)
					{
						$reason = substr($message, 2);
						if(false === (bool) preg_match('//u', $reason)) {
							$this->close($client_id, self::CLOSE_MESSAGE_ERROR);

							break;
						}
					}
				}
				$this->close($client_id, self::CLOSE_NORMAL);
				break;
			default:
				$this->close($client_id, self::CLOSE_PROTOCOL_ERROR);
		}
	}
	
	
	function onConnect($serv, $client_id, $from_id)
	{
		 $this->log("connected client_id = $client_id");
	}
	
	
	
	function onClose($serv, $client_id, $from_id)
	{
		$this->onOffline( $serv, $client_id, $from_id );
	    $this->log("closeing client_id = $client_id");
		unset($this->ws_list[$client_id], $this->connections[$client_id]);
	}
	/**
	 * Close a connection.
	 *
	 * @access public
	 * @param int $code
	 * @param string $reason Reason.
	 * @return void
	 */
	public function close($client_id, $code = self::CLOSE_NORMAL, $reason = '')
	{
		$this->send($client_id, pack('n', $code).$reason, self::OPCODE_CONNECTION_CLOSE);
		$this->server->close($client_id);
	}
}