<?php
namespace Shabb\Apc;

class Client {
  public $loop;
	public $address;
	public function __construct($address, $loop) {
		$this->loop = $loop;
		$this->address = $address;
		$that = $this;

		//-- Load Cache
		if (is_file($this->address) && is_readable($this->address)) {
			apc_bin_loadfile($this->address);
		}

		//-- Save cache every so often
		$this->loop->addPeriodicTimer(5, function () use ($that) {
			apc_bin_dumpfile(array(), null, $that->address);
		});
	}

	public function lrange($key, $from, $to, $fn) {
		$list = array();
		if ($to === -1) {
			$to = null;
		}
		if (apc_exists($key)) {
			$value = apc_fetch($key);

			if ($to === null) {
				$list = array_slice($value, $from);
			} else {
				$list = array_slice($value, $from, $to);
			}

		}
		$fn($list, null);
	}

	public function hgetall($key, $fn) {
		$value = null;
		if (apc_exists($key)) {
			$value = apc_fetch($key);
		}
		$fn($value, null);
	}

	public function hmset($key /* ... */) {
		$args = func_get_args();
		array_shift($args); //-- delete $key
		$fn = array_pop($args); //-- get callback

		$hash = array();
		if (apc_exists($key)) {
			$hash = apc_fetch($key);
		}

		for ($i = 0; $i < count($args); $i += 2) {
			$hash[$args[$i]] = $args[$i + 1];
		}

		apc_store($key, $hash);

		$fn($hash, null);
	}

	public function hset($key, $name, $value, $fn) {
		$this->hmset($key, $name, $value, $fn);
	}

	public function lpush($key, $value, $fn) {
		$list = array();
		if (apc_exists($key)) {
			$list = apc_fetch($key);
		}
		$list []= $value;
		apc_store($key, $list);
		$fn($list, null);
	}

	public function multiExec() {
		return new Multi($this);
	}
}

class Multi {
	public $client;
	public $cmds = array();
	public function __construct($client) {
		$this->client = $client;
	}

	public function hgetall($key) {
		$this->cmds []= array(array($this->client, 'hgetall'), array($key));
	}

	public function hmset($key /* ... */) {
		$args = func_get_args();
		$this->cmds []= array(array($this->client, 'hmset'), $args);
	}

	public function lpush($key, $value) {
		$this->cmds []= array(array($this->client, 'lpush'), array($key, $value));
	}

	public function execute($fn) {
		$results = array();
		foreach ($this->cmds as $cmd) {
			$cmd[1] []= function ($result) use (&$results) {
				$results []= $result;
			};
			call_user_func_array($cmd[0], $cmd[1]);
		}

		$fn($results, null);
	}
}

?>
