<?php
ini_set('apc.enable_cli', 1);
ini_set('apc.stat', 0);

include __DIR__ . '/../vendor/autoload.php';
include 'client.php';

class TodoServConn implements Ratchet\ConnectionInterface {

	/**
	 * Send data to the connection
	 * @param string
	 * @return ConnectionInterface
	 */
	function send($data) {}

	/**
	 * Close the connection
	 */
	function close() {}
}

class TodoMessage implements Ratchet\MessageComponentInterface {
	protected $clients;
	
	public function __construct() {
		$this->clients = new SplObjectStorage;
	}
	
	public function onOpen(Ratchet\ConnectionInterface $conn) {
		$this->clients->attach($conn);
	}
	public function onMessage(Ratchet\ConnectionInterface $from, $msg) {
		$numRecv = count($this->clients) - 1;
		foreach ($this->clients as $client) {
			if ($from !== $client) {
				// The sender is not the receiver, send to each client connected
				$client->send($msg);
			}
		}
	}
	public function onClose(Ratchet\ConnectionInterface $conn) {
		$this->clients->detach($conn);
	}
	public function onError(Ratchet\ConnectionInterface $conn, Exception $e) {
		$conn->close();
	}
}

//-- Config
$config = (array) json_decode(file_get_contents(__DIR__ . '/config.json'));
if (is_file(__DIR__ . '/config.override.json')) {
	$config = array_merge($config, (array) json_decode(file_get_contents(__DIR__ . '/config.override.json')));
}

//-- Template System
$loader = new Twig_Loader_Filesystem(__DIR__ . '/templates');
$twig = new Twig_Environment($loader, array(
	'cache' => __DIR__ . '/../cache',
));
$templates = array();
$templates['page.html'] = $twig->loadTemplate('page.html');
$templates['add.html'] = $twig->loadTemplate('add.html');
$templates['edit.html'] = $twig->loadTemplate('edit.html');

//-- Routes
$routes = array();

$routes['/'] = function ($request, $response) use (&$templates, &$cache, &$config) {
	$cache->lrange('todos', 0, -1, function($todo_ids, $client) use (&$templates, &$request, &$response, &$cache, &$config) {
		$multi = $cache->multiExec();
		foreach ($todo_ids as $id) {
			$multi->hgetall('todos_' . $id);
		}
		$multi->execute(function ($todos, $client) use (&$templates, &$response, &$config) {
			$headers = array('Content-Type' => 'text/html; charset=UTF-8');
			$headers['Access-Control-Allow-Origin'] = 'http://' . $config['address'] . ':' . $config['ws_port'];
			$headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS';
			$response->writeHead(200, $headers);
			$response->end($templates['page.html']->render(array('todos' => $todos)));
		});
	});
};

$routes['/list'] = function ($request, $response) use (&$templates, &$cache, &$config) {
	$cache->lrange('todos', 0, -1, function($todo_ids, $client) use (&$templates, &$request, &$response, &$cache, &$config) {
		$multi = $cache->multiExec();
		foreach ($todo_ids as $id) {
			$multi->hgetall('todos_' . $id);
		}
		$multi->execute(function ($todos, $client) use (&$templates, &$response, &$config) {
			$headers = array('Content-Type' => 'text/html; charset=UTF-8');
			$headers['Access-Control-Allow-Origin'] = 'http://' . $config['address'] . ':' . $config['ws_port'];
			$headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS';
			$response->writeHead(200, $headers);
			$response->end(json_encode(array('todos' => $todos)));
		});
	});
};

$routes['/add'] = function ($request, $response) use (&$templates, &$cache, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['name'])) {
		$id = uniqid();

		//-- Save to redis
		$multi = $cache->multiExec();
		$multi->hmset('todos_' . $id, 'id', $id, 'name', $query['name']);
		$multi->lpush('todos', $id);
		$multi->execute(function ($replies, $client) use (&$response, &$todo_message, &$id, &$query) {
			//-- Send message to everyone
			$json_msg = json_encode(
				array('id' => $id, 'name' => $query['name'])
			);
			$from = new TodoServConn();
			$todo_message->onMessage($from, 'task-add:' . $json_msg);

			//-- GO home!
			$headers = array('Location' => './');
			$response->writeHead(302, $headers);
			$response->end();
		});
		return;
	}

	$headers = array('Content-Type' => 'text/html; charset=UTF-8');
	$response->writeHead(200, $headers);
	$response->end($templates['add.html']->render(array()));
};

$routes['/edit'] = function ($request, $response) use (&$templates, &$cache, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['update']) && !empty($query['name']) && !empty($query['id'])) {
		$id = $query['id'];
		$cache->hset('todos_' . $id, 'name', $query['name'], function ($replies, $client) use (&$response, &$todo_message, &$id, &$query) {
			//-- Send message to everyone
			$json_msg = json_encode(
				array('id' => $id, 'name' => $query['name'])
			);
			$from = new TodoServConn();
			$todo_message->onMessage($from, 'task-update:' . $json_msg);

			$headers = array('Location' => './');
			$response->writeHead(302, $headers);
			$response->end();
		});
		return;
	}

	if (!empty($query['id'])) {
		$id = $query['id'];
		$cache->hgetall('todos_' . $id, function($todo, $client) use (&$templates, &$response) {
			$headers = array('Content-Type' => 'text/html; charset=UTF-8');
			$response->writeHead(200, $headers);
			$response->end($templates['edit.html']->render(array('todo' => $todo)));
		});
	}
};

$routes['/delete'] = function ($request, $response) use (&$cache, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['id'])) {
		$id = $query['id'];
		$multi = $cache->multiExec();
		$multi->lrem('todos', -1, $id);
		$multi->del('todos_' . $id);
		$multi->execute(function ($replies, $client) use (&$response, &$todo_message, &$id) {
			//-- Send message to everyone
			$json_msg = json_encode(array('id' => $id));
			$from = new TodoServConn();
			$todo_message->onMessage($from, 'task-delete:' . $json_msg);

			//-- Go Home
			$headers = array('Location' => './');
			$response->writeHead(302, $headers);
			$response->end();
		});
	}
};

$routes['/config.json'] = function ($request, $response) use (&$templates, &$cache, &$config) {
	$headers = array('Content-Type' => 'text/json');
	$response->writeHead(200, $headers);
	$response->end(json_encode($config));
};

$routes['/static/...'] = function ($request, $response, $params) use (&$templates, &$cache, &$config) {
	$content_type = 'text/plain';
	$data = '';
	if (stristr($params[1], '.js') !== false) {
		$content_type = 'text/javascript';
	}
	else if (stristr($params[1], '.html') !== false) {
		$content_type = 'text/html';
	}
	else if (stristr($params[1], '.css') !== false) {
		$content_type = 'text/css';
	}
	else if (stristr($params[1], '.gif') !== false) {
		$content_type = 'image/gif';
	}
	else if (stristr($params[1], '.png') !== false) {
		$content_type = 'image/png';
	}
	else if (substr($params[1], -1) === '/' || $params[1] === '') {
		$content_type = 'text/html';
		$list = scandir(__DIR__ . '/static/' . $params[1]);
		foreach ($list as $i => $file) {
			if (is_dir(__DIR__ . '/static/' . $params[1] . '/' . $file)) {
				$file .= '/';
			}
			$data .= "<a href='$file'>$file</a><br/>";
		}
	}

	if ($data === '') {
		$data = file_get_contents(__DIR__ . '/static/' . $params[1]);
	}

	$headers = array('Content-Type' => $content_type);
	$response->writeHead(200, $headers);
	$response->end($data);
};

//-- Server
$app = function ($request, $response) use (&$routes, &$cache) {
	$path = $request->getPath();
	if (isset($routes[$path])) {
		$routes[$path]($request, $response);
	}
	else {
		foreach ($routes as $pattern => $route) {
			$is_regex = false; //substr($pattern, 0, 1) === '/' and substr($pattern, -1) === '/';
			if ((stristr($pattern, ':') !== false or stristr($pattern, '?') !== false or stristr($pattern, '...') !== false) and !$is_regex) {
				$pattern = str_replace('/', '\/', $pattern);
				$pattern = str_replace('...', '(.*+)', $pattern);
				$pattern = str_replace('?', '', $pattern);
				$pattern = preg_replace('/\:([%A-Za-z0-9\-_\.]+)/', '(?P<${1}>[%A-Za-z0-9\-_\.+]+)', $pattern);
				$pattern = '/^' . $pattern . '\/?$/';
				$is_regex = true;
			}
			if ($is_regex) {
				$matched = @preg_match($pattern, $path, $params);
				if ($matched) {
					$route($request, $response, $params);
					break;
				}
			}
		}
		if (!isset($matched) || (isset($matched) && ($matched === 0 || $matched === false))) {
			$response->writeHead(404, array());
			$response->end("");
		}
	}
};

$todo_message = new TodoMessage();
$todo_ws = new Ratchet\WebSocket\WsServer($todo_message);

$loop = React\EventLoop\Factory::create();
$http_socket = new React\Socket\Server($loop);
$websocket_socket = new React\Socket\Server($loop);
$websocket = new Ratchet\Server\IoServer($todo_ws, $websocket_socket, $loop);
$http = new React\Http\Server($http_socket);
$http->on('request', $app);


if (!class_exists('Predis\Async\Client')) {
	$cache = new Shabb\Apc\Client('file.data', $loop);
} else {
	$cache = new Predis\Async\Client('tcp://127.0.0.1:6379', $loop);
}

$http_socket->listen($config['http_port'], $config['address']);
$websocket_socket->listen($config['ws_port'], $config['address']);
$loop->run();
