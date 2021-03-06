<?php

require_once 'config.php';

class UnhostedAccount {
	private $userAddress, $userName, $userDomain, $pwd;
	function __construct($userAddress, $pwd) {
		$this->userAddress = $userAddress;
		list($this->userName, $this->userDomain) = explode("@", $userAddress);
		$this->pwd = $pwd;
	}
	private function createUserDir() {
		$userDomainDir = UnhostedSettings::davDir . $this->userDomain . '/';
		$userDir = $userDomainDir . strtolower($this->userName);
		if(is_dir($userDir)) {
			return false;
		}
		if(!file_exists($userDomainDir)) {
			mkdir($userDomainDir);
		}
		if(!file_exists($userDir)) {
			mkdir($userDir);
		}
		file_put_contents($userDir."/.htpasswd", sha1($this->pwd));
		return true;
	}
	private function createDav($scope) {
		$token = base64_encode(mt_rand());
		$davDir = UnhostedSettings::davDir . "{$this->userDomain}/{$this->userName}/".$scope;
		`if [ ! -d $davDir ] ; then mkdir $davDir ; fi`;
		`echo "<LimitExcept OPTIONS HEAD GET>" > $davDir/.htaccess`;
		`echo "  AuthType Basic" >> $davDir/.htaccess`;
		`echo "  AuthName \"http://unhosted.org/spec/dav/0.1\"" >> $davDir/.htaccess`;
		`echo "  Require valid-user" >> $davDir/.htaccess`;
		`echo "  AuthUserFile $davDir/.htpasswd" >> $davDir/.htaccess`;
		`echo "</LimitExcept>" >> $davDir/.htaccess`;
		`htpasswd -bc $davDir/.htpasswd {{$this->userAddress} $token`;
		return $token;
	}
	private function createWallet($davBaseUrl, $davToken, $cryptoPwd, $dataScope) {
		$wallet = json_encode(array(
			"userAddress" => $userAddress,
			"davBaseUrl" => $davBaseUrl,
			"davAuth" => base64_encode($userAddress .':'. $davToken),
			"cryptoPwd" => $cryptoPwd
			));
		$davDomainDir = UnhostedSettings::davDir . $this->userDomain ."/";
		$davUserDir = $davDomainDir . $this->userName . "/";
		$davDir = $davUserDir . $dataScope;
		if(!file_exists($davDomainDir)) {
			mkdir($davDomainDir);
		}
		if(!file_exists($davUserDir)) {
			mkdir($davUserDir);
		}
		if(!file_exists($davDir)) {
			mkdir($davDir);
		}
		file_put_contents($davDir.'/wallet_'.sha1($this->pwd), $wallet);
		return $wallet;
	}
	public function getWallet($dataScope) {
		$davDir = UnhostedSettings::davDir . "{$this->userDomain}/{$this->userName}/".$dataScope;
		if(file_exists($davDir.'/wallet_'.sha1($this->pwd))) {
			return file_get_contents($davDir.'/wallet_'.sha1($this->pwd));
		} else {
			return false;
		}
	}
	public function registerHosted() {
		$this->createUserDir();
		$davToken = $this->createDav(UnhostedSettings::protocol . '://' . UnhostedSettings::domain . '/');
		return $this->createWallet(UnhostedSettings::protocol . '://' . UnhostedSettings::domain . '/', $davToken, null, UnhostedSettings::domain);
	}
	public function registerWallet($davBaseUrl, $davToken, $dataScope) {
		$cryptoPwd = sha1(mt_rand());
		return $this->createWallet($davBaseUrl, $davToken, $cryptoPwd, $dataScope);
	}
	public function addApp($dataScope) {
		$pwdFile = UnhostedSettings::davDir . "{$this->userDomain}/{$this->userName}/.htpasswd";
		if(file_exists($pwdFile) && sha1($this->pwd)==file_get_contents($pwdFile)) {
			return $this->createDav($dataScope);
		}
		return null;
	}
}
