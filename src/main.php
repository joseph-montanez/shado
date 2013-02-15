<?php
include __DIR__ . '/../vendor/autoload.php';

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
		
		//echo "New Connections! ({$conn->resourceId})\n";
	
	}
	public function onMessage(Ratchet\ConnectionInterface $from, $msg) {
		$numRecv = count($this->clients) - 1;
		//echo sprintf('Connection %d sending message "%s" to %d other connection%s' . "\n"
		//	, $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's');
		//echo count($this->clients), PHP_EOL;
		foreach ($this->clients as $client) {
			if ($from !== $client) {
				// The sender is not the receiver, send to each client connected
				$client->send($msg);
			}
		}
	}
	public function onClose(Ratchet\ConnectionInterface $conn) {
        // The connection is closed, remove it, as we can no longer send it messages
        $this->clients->detach($conn);

        //echo "Connection {$conn->resourceId} has disconnected\n";
	}
	public function onError(Ratchet\ConnectionInterface $conn, Exception $e) {
        //echo "An error has occurred: {$e->getMessage()}\n";

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

$routes['/'] = function ($request, $response) use (&$templates, &$redis) {
	$redis->lrange('todos', 0, -1, function($todo_ids, $client) use (&$templates, &$request, &$response, &$redis) {
		$multi = $redis->multiExec();
		foreach ($todo_ids as $id) {
			$multi->hgetall('todos_' . $id);
		}
		$multi->execute(function ($todos, $client) use (&$templates, &$response) {
			$headers = array('Content-Type' => 'text/html; charset=UTF-8');
			$response->writeHead(200, $headers);
			$response->end($templates['page.html']->render(array('todos' => $todos)));
		});
	});
};

$routes['/add'] = function ($request, $response) use (&$templates, &$redis, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['name'])) {
		$id = uniqid();

		//-- Save to redis
		$multi = $redis->multiExec();
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

$routes['/edit'] = function ($request, $response) use (&$templates, &$redis, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['update']) && !empty($query['name']) && !empty($query['id'])) {
		$id = $query['id'];
		$redis->hset('todos_' . $id, 'name', $query['name'], function ($replies, $client) use (&$response, &$todo_message, &$id, &$query) {
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
		$redis->hgetall('todos_' . $id, function($todo, $client) use (&$templates, &$response) {
			$headers = array('Content-Type' => 'text/html; charset=UTF-8');
			$response->writeHead(200, $headers);
			$response->end($templates['edit.html']->render(array('todo' => $todo)));
		});
	}
};

$routes['/delete'] = function ($request, $response) use (&$redis, &$todo_message) {
	$query = $request->getQuery();

	if (!empty($query['id'])) {
		$id = $query['id'];
		$multi = $redis->multiExec();
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

$routes['/config.json'] = function ($request, $response) use (&$templates, &$redis, &$config) {
	$headers = array('Content-Type' => 'text/json');
	$response->writeHead(200, $headers);
	$response->end(json_encode($config));
};

//-- Server
$app = function ($request, $response) use (&$routes, &$redis) {
	$path = $request->getPath();

	if (isset($routes[$path])) {
		$routes[$path]($request, $response);
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
$redis = new Predis\Async\Client('tcp://127.0.0.1:6379', $loop);

$http_socket->listen($config['http_port'], $config['address']);
$websocket_socket->listen($config['ws_port'], $config['address']);
$loop->run();
