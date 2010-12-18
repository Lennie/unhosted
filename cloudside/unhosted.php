<?php
ini_set('display_errors', TRUE);
require_once 'config.php';
class UnhostedJsonParser {
	function checkWriteCaps($chan, $WriteCaps) {
		//hard coded read => write caps, change these to codes that only you know!:
		$chans = array(//r => w:
			'7db31' => '0249e',
			'140d9' => '0e09a',
			'b3108' => 'a13b4',
			'fabf8' => '32960',
			'f56b6' => '93541',
			'b569c' => '7a981',
			'cf2bb' => '7d2f0',
			'98617' => 'e1608',
			);
		return ($WriteCaps==$chans[$chan]);
	}
	function checkFieldsPresent($arr, $fields) {
		foreach($fields as $fieldName => $exceptionText) {
			if(!isset($arr[$fieldName])) {
				throw new Exception($exceptionText);
			}
		}
	}
	function parseInput($backend, $POST, $referer) {
		$this->checkFieldsPresent($POST, array(
			'protocol' => 'please add a "protocol" key to your POST',
			'cmd' => 'please add "cmd" key to your POST',
			));
		if($POST['protocol'] != 'UJ/0.1') { 
			throw new Exception('please use "UJ/0.1" as the protocol');
		}
		try {
			$cmd = json_decode($POST['cmd'], TRUE);//in JSON, associative arrays are objects; ", TRUE" is for forcing cast from StdClass to assoc array.
		} catch(Exception $e) {
			throw new Exception('the "cmd" key in your POST does not seem to be valid JSON');
		}
		$this->checkFieldsPresent($cmd, array('method' => 'please define a method inside your command'));

		switch($cmd['method']) {
		case 'SET':
			$this->checkFieldsPresent($POST, array(
				'WriteCaps' => 'The SET command requires WriteCaps in the POST',
				'PubSign' => 'Please provide a PubSign so that your subscriber can check that this SET command really comes from you'
				));
			$this->checkFieldsPresent($cmd, array(
				'chan' => 'Please specify which channel you want to publish on',
				'keyPath' => 'Please specify which key path you\'re setting',
				'value' => 'Please specify a value for the key you\'re setting',
				));
			if(!$this->checkWriteCaps($cmd['chan'], $POST['WriteCaps'])) {
				throw new Exception('Channel password is incorrect.');
			}
			return $backend->doSET(
				$cmd['chan'], 
				$referer['host'], 
				$cmd['keyPath'], 
				json_encode(array('cmd'=>$cmd, 'PubSign'=>$POST['PubSign']))
				);
		case 'GET':
			$this->checkFieldsPresent($cmd, array(
				'chan' => 'Please specify which channel you want to get a (key, value)-pair from',
				'keyPath' => 'Please specify which key path you\'re getting',
				));
			return $backend->doGET(
				$cmd['chan'],
				$referer['host'],
				$cmd['keyPath']
				);
		case 'SEND':
			if(!isset($POST['PubSign'])) {
				$POST['PubSign'] = null;
			}				
			$this->checkFieldsPresent($cmd, array(
				'chan' => 'Please specify which channel you want to send your message to',
				'keyPath' => 'Please specify which key path you\'re setting',
				'value' => 'Please specify a value for the key you\'re setting',
				));
			return $backend->doSEND(
				$cmd['chan'],
				$referer['host'],
				$cmd['keyPath'],
				json_encode(array('cmd'=>$cmd, 'PubSign'=>$POST['PubSign']))
				);
		case 'RECEIVE':
			$this->checkFieldsPresent($POST, array(
				'WriteCaps' => 'The RECEIVE command requires WriteCaps in the POST',
				));
			$this->checkFieldsPresent($cmd, array(
				'chan' => 'Please specify which channel you want to retrieve messages from',
				'keyPath' => 'Please specify which key path you\'re getting',
				'delete' => 'Please specify whether you also want to delete the entries you retrieve',
				));
			if(!$this->checkWriteCaps($cmd['chan'], $POST['WriteCaps'])) {
				throw new Exception('Channel password is incorrect.');
			}
			return $backend->doRECEIVE(
				$cmd['chan'],
				$referer['host'],
				$cmd['keyPath'],
				$cmd['delete']
				);
		default:
			throw new Exception('undefined method');
		}
	}
}

class StorageBackend {
	private $mysql = null;
	private $dbSpec = array(//in real life you would never create the tables from a php-array, nor use three blobs as the primary key of a table, but this is only a demo:
			"CREATE TABLE IF NOT EXISTS `entries` (`chan` varchar(255), `app` varchar(255), `keyPath` varchar(255), `save` blob, PRIMARY KEY (`chan`, `app`, `keyPath`))",
			"CREATE TABLE IF NOT EXISTS `messages` (`chan` blob, `app` blob, `keyPath` blob, `save` blob)");
	private function query($sql) {
		if($this->mysql === null) {
			$this->mysql = mysqli_connect($GLOBALS['dbHost'],$GLOBALS['dbUser'],$GLOBALS['dbPwd']);
			if($this->mysql === FALSE) {
				throw new Exception("DB CONNECT ERROR");
			}
			if($this->mysql->select_db($GLOBALS['dbName']) === FALSE) {
				$this->mysql->query("CREATE DATABASE IF NOT EXISTS `".$GLOBALS['dbName']."`");
				if($this->mysql->select_db($GLOBALS['dbName']) === FALSE) {
					throw new Exception("DB CREATION FAILURE :".$this->mysql->error);
				}
				foreach($this->dbSpec as $query) {
					if($this->mysql->query($query) === FALSE) {
						throw new Exception("TABLE CREATION FAILURE :".$this->mysql->error);
					}
				}
			}
		}
		$r = $this->mysql->query($sql);
		if($r === FALSE) {
			throw new Exception("DB ERROR: ".$this->mysql->error);
		}
		return $r;
	}
	private function queryVal($sql) {
		$r = $this->query($sql);
		$ret = array();
		$row = mysqli_fetch_row($r);
		if($row) {
			return $row[0];
		} else {
			return null;
		}
		return $ret;
	}
	private function queryArr($sql) {
		$r = $this->query($sql);
		$ret = '[';
		while($row = mysqli_fetch_row($r)) {
			if(strlen($ret)>1) {
				$ret .= ',';
			}
			$ret .= $row[0];
		}
		return $ret.']';
	}
	function doSET($chan, $app, $keyPath, $save) {
		$this->query("INSERT INTO `entries` (`chan`, `app`, `keyPath`, `save`) VALUES ('$chan', '$app', '$keyPath', '$save') ON DUPLICATE KEY UPDATE `save`='$save';");
		return '"OK"';
	}
	function doGET($chan, $app, $keyPath) {
		return $this->queryVal("SELECT `save` FROM `entries` WHERE `chan`='$chan' AND `app`='$app' AND `keyPath`='$keyPath';");
	}
	function doSEND($chan, $app, $keyPath, $save) {
		$this->query("INSERT INTO `messages` (`chan`, `app`, `keyPath`, `save`) VALUES ('$chan', '$app', '$keyPath', '$save');");
		return '"OK"';
	}
	function doRECEIVE($chan, $app, $keyPath, $andDelete) {
		$ret = $this->queryArr("SELECT `save` FROM `messages` WHERE `chan`='$chan' AND `app`='$app' AND `keyPath`='$keyPath';");
		if($andDelete) {
			$this->query("DELETE FROM `messages` WHERE `chan`='$chan' AND `app`='$app' AND `keyPath`='$keyPath';");
		}
		return $ret;
	}
}


//MAIN:
header('Content-Type: text/html');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
$unhostedJsonParser = new UnhostedJsonParser();
$storageBackend = new StorageBackend();
if(count($_POST) == 0) {//handle as HTTP GET request
	$referer = "";
	$req = $_GET['p'];
	$parts = explode('/', $req);
	$referer = array("host"=>array_shift($parts));
	$chan = array_shift($parts);
	$keyPath = implode('/', $parts);
	$POST = array(
			"protocol"=>"UJ/0.1",
			"cmd"=>json_encode(
				array(
					"method"=>"GET",
					"chan"=>$chan,
					"keyPath"=>$keyPath,
				)
			),
		);
	//now process it:
	try {
		$res = $unhostedJsonParser->parseInput($storageBackend, $POST, $referer);
		$resObj = json_decode($res, TRUE);
		echo $resObj["cmd"]["value"];
	} catch (Exception $e) {
		echo "ERROR:\n" . $e->getMessage() . "\n";
	}
} else {//handle as UJ/0.1 over HTTP POST
	if(!isset($_SERVER['HTTP_REFERER'])) {
		die("This url is an unhosted JSON storage, and only works over CORS-AJAX. Please access using the unhosted JS library (www.unhosted.org).");
	}
	$referer = parse_url($_SERVER['HTTP_REFERER']);
	$POST = $_POST;
	//now process it:
	try {
		$res = $unhostedJsonParser->parseInput($storageBackend, $POST, $referer);
		echo $res;
	} catch (Exception $e) {
		echo "ERROR:\n" . $e->getMessage() . "\n";
	}
}
