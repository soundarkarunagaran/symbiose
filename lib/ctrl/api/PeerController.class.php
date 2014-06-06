<?php
namespace lib\ctrl\api;

use \lib\ApiBackController;
use \lib\PeerServer;
use \lib\ApiWebSocketServer;
use \lib\entities\OnlinePeer;
use \lib\entities\OfflinePeer;
use \lib\entities\PeerLink;
use \RuntimeException;

/**
 * Manage peers.
 * @author $imon
 */
class PeerController extends ApiBackController {
	protected static $peerServer;
	protected static $apiServer;

	/**
	 * Bisounours mode. Everybody can communicate with everybody without any restriction.
	 * Warning! Use for test purposes only, this can be dangerous.
	 * @var boolean
	 */
	protected $bisounours = true;

	protected function _getOnlinePeerData(OnlinePeer $peer, $isAttached = false) {
		$peerData = array(
			'registered' => ($peer['userId'] !== null),
			'online' => true,
			'app' => $peer['app']
		);

		if ($isAttached || $this->bisounours) {
			$peerData['userId'] = $peer['userId'];
			$peerData['peerId'] = $peer['id'];
		}

		return $peerData;
	}

	protected function _getOfflinePeerData(OfflinePeer $peer, $isAttached = false) {
		$peerData = array(
			'registered' => true,
			'online' => false,
			'app' => $peer['app']
		);

		if ($isAttached) {
			$peerData['userId'] = $peer['userId'];
		}

		return $peerData;		
	}

	protected function _getPeerData(OnlinePeer $onlinePeer = null, OfflinePeer $offlinePeer = null, $isAttached = false) {
		$userManager = $this->managers()->getManagerOf('user');

		if (empty($onlinePeer) && empty($offlinePeer)) {
			return null;
		}

		$offlinePeerData = array();
		$onlinePeerData = array();
		if (!empty($offlinePeer)) {
			if ($offlinePeer['isPublic']) {
				$isAttached = true;
			}
			$offlinePeerData = $this->_getOfflinePeerData($offlinePeer, $isAttached);
		}
		if (!empty($onlinePeer)) {
			$onlinePeerData = $this->_getOnlinePeerData($onlinePeer, $isAttached);
		}

		$peerData = array_merge($offlinePeerData, $onlinePeerData);

		if (isset($peerData['userId']) && $isAttached) {
			$peerUser = $userManager->getById($peerData['userId']);
			if (!empty($peerUser)) {
				$peerData['user'] = array(
					'username' => $peerUser['username'],
					'realname' => $peerUser['realname'],
				);
			}
		}

		return $peerData;
	}

	protected function _getPeerLinkData(PeerLink $peerLink) {
		$peerLinkData = array(
			'id' => $peerLink['id'],
			'leftPeer' => $peerLink['leftPeer'],
			'rightPeer' => $peerLink['rightPeer'],
			'confirmed' => $peerLink['confirmed']
		);

		return $peerLinkData;		
	}


	public function __construct(Api $app, $module, $action) {
		parent::__construct($app, $module, $action);

		$this->listenEvents();
	}

	// GETTERS

	// Peers

	public function executeListPeers($appName = null) {
		$server = $this->peerServer();
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();
		
		$currentOfflinePeer = null;
		if ($user->isLogged()) {
			$currentOfflinePeer = $manager->getByUserAndApp($user->id(), $appName);
		}

		$peers = array();
		$onlinePeers = $server->listPeers();
		foreach ($onlinePeers as $onlinePeer) {
			$offlinePeer = null;
			$isAttached = false;
			if ($onlinePeer['userId'] !== null && $user->isLogged() && $onlinePeer['userId'] === $user->id()) {
				$isAttached = true;
			} elseif ($onlinePeer['userId'] !== null && !empty($appName)) {
				$offlinePeer = $manager->getByUserAndApp($onlinePeer['userId'], $appName);

				if (!empty($currentOfflinePeer)) {
					$isAttached = $peerLinkManager->confirmedByPeers($currentOfflinePeer['id'], $offlinePeer['id']);
				}
			}

			$peers[] = $this->_getPeerData($onlinePeer, $offlinePeer, $isAttached);
		}

		return $peers;
	}

	public function executeGetPeer($peerId) {
		$server = $this->peerServer();

		$onlinePeer = $server->getPeer($peerId);
		$peerData = $this->_getPeerData($onlinePeer, null, true);

		if (empty($peerData)) {
			throw new RuntimeException('Cannot find peer with id "'.$peerId.'"', 404);
		}

		return $peerData;
	}

	public function executeGetPeerByUserAndApp($userId, $appName) {
		$server = $this->peerServer();
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		$offlinePeer = $manager->getByUserAndApp($userId, $appName);

		$isAttached = false;
		if ($user->isLogged()) {
			if ($user->id() === $offlinePeer['userId']) {
				$isAttached = true;
			} else {
				$currentOfflinePeer = $manager->getByUserAndApp($user->id(), $appName);
				if (!empty($currentOfflinePeer)) {
					$isAttached = $peerLinkManager->confirmedByPeers($currentOfflinePeer['id'], $offlinePeer['id']);
				}
			}
		}

		$peerData = $this->_getPeerData(null, $offlinePeer, $isAttached);

		if (empty($peerData)) {
			throw new RuntimeException('Cannot find peer with user "'.$userId.'" and app "'.$appName.'"', 404);
		}

		return $peerData;
	}

	// Peers links

	public function executeListPeersLinks($appName = null) {
		try {
			$server = $this->peerServer(); //TODO: works without peer server
		} catch (\Exception $e) {
			$server = null;
		}
		
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot list peers links: you\'re not logged in', 401);
		}

		$userPeers = array();
		if (empty($appName)) {
			$userPeers = $manager->listByUser($user->id());
		} else {
			$userPeers = array($manager->getByUserAndApp($user->id(), $appName));
		}
		
		$list = array();

		foreach ($userPeers as $userPeer) {
			$links = $peerLinkManager->listByPeer($userPeer['id']);
			foreach ($links as $peerLink) {
				$list[] = $this->_getPeerLinkData($peerLink);
			}
		}

		return $list;
	}

	public function executeListLinkedPeers($appName = null) {
		try {
			$server = $this->peerServer(); //TODO: works without peer server
		} catch (\Exception $e) {
			$server = null;
		}
		
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot list peers links: you\'re not logged in', 401);
		}

		$userPeers = array();
		if (empty($appName)) {
			$userPeers = $manager->listByUser($user->id());
		} else {
			$userPeers = array($manager->getByUserAndApp($user->id(), $appName));
		}
		
		$list = array();

		foreach ($userPeers as $userPeer) {
			$links = $peerLinkManager->listByPeer($userPeer['id']);
			foreach ($links as $peerLink) {
				$offlinePeerId = ($peerLink['rightPeer'] === $userPeer['id']) ? $peerLink['leftPeer'] : $peerLink['rightPeer'];

				$offlinePeer = $manager->getById($offlinePeerId);
				if (empty($offlinePeer)) {
					continue;
				}

				$isAttached = $peerLink['confirmed'];

				$onlinePeers = array(null);
				if (!empty($server)) {
					$onlinePeers = $server->listPeersByUser($offlinePeer['userId']);
				}

				foreach ($onlinePeers as $onlinePeer) {
					if (!empty($onlinePeer) && !empty($appName) && $onlinePeer['app'] != $appName) {
						continue;
					}

					$peerData = $this->_getPeerData($onlinePeer, $offlinePeer, $isAttached);
					if (!in_array($peerData, $list)) { //Avoid duplicates
						$list[] = $peerData;
					}
				}
			}
		}

		return $list;
	}

	// Events
	
	protected function listenEvents() {
		$peerServer = $this->peerServer();

		$peerServer->on('peer.updated', array($this, 'onPeerUpdated'));
		$peerServer->on('peer.deleted', array($this, 'onPeerUpdated'));
	}

	public function onPeerUpdated(OnlinePeer $peer) {
		try {
			$apiServer = $this->apiServer();
		} catch (\Exception $e) {
			return;
		}

		$apiServer->publish('peer.list.'.$peer['app'], array(
			'list' => $this->executeListPeers($peer['app'])
		));
	}

	// SETTERS

	// Peers
	
	public function executeAttachPeer($peerId, $appName = null) {
		$server = $this->peerServer();
		$user = $this->app()->user();

		$onlinePeer = $server->getPeer($peerId);
		if (empty($onlinePeer)) {
			throw new RuntimeException('Cannot find peer with id "'.$peerId.'"', 404);
		}

		if ($user->isLogged()) {
			$onlinePeer['userId'] = $user->id();
		}
		if (!empty($appName)) {
			$onlinePeer['app'] = $appName;
		}
		
		$server->updatePeer($onlinePeer);
	}

	public function executeRegisterPeer(array $peerData) {
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot register a new peer: you\'re not logged in', 401);
		}

		$peerData = array(
			'userId' => $user->id(),
			'isPublic' => (isset($peerData['isPublic']) && $peerData['isPublic']) ? true : false,
			'app' => (string) $peerData['app']
		);

		$peer = new OfflinePeer($peerData);

		$peerLinkManager->insert($peer);
	}

	// Peers links

	public function executeRequestPeerLink($peerId, $appName) {
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot request a peer link: you\'re not logged in', 401);
		}

		$userPeer = $manager->getByUserAndApp($user->id(), (string) $appName);

		if (empty($userPeer)) {
			throw new RuntimeException('Cannot find a peer associated with your account, please register a new peer first', 404);
		}

		$peerLinkData = array(
			'leftPeer' => $userPeer['id'],
			'rightPeer' => (int) $peerId,
			'confirmed' => false
		);
		$peerLink = new PeerLink($peerLinkData);

		$peerLinkManager->insert($peerLink);
	}

	public function executeConfirmPeerLink($peerLinkId) {
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot request a peer link: you\'re not logged in', 401);
		}

		$userPeer = $manager->getByUserAndApp($user->id(), (string) $appName);

		if (empty($userPeer)) {
			throw new RuntimeException('Cannot find a peer associated with your account, please register a new peer first', 404);
		}

		$peerLink = $peerLinkManager->getById((int) $peerLinkId);

		if (empty($peerLink) || $peerLink['rightPeer'] != $userPeer['id']) {
			throw new RuntimeException('Cannot find a peer link with id "'.$peerLinkId.'"', 404);
		}

		$peerLink['confirmed'] = true; // Confirm the link

		$peerLinkManager->update($peerLink);
	}

	public function executeRevokePeerLink($peerLinkId) {
		$manager = $this->managers()->getManagerOf('peer');
		$peerLinkManager = $this->managers()->getManagerOf('peerLink');
		$user = $this->app()->user();

		if (!$user->isLogged()) {
			throw new RuntimeException('Cannot request a peer link: you\'re not logged in', 401);
		}

		$userPeer = $manager->getByUserAndApp($user->id(), (string) $appName);

		if (empty($userPeer)) {
			throw new RuntimeException('Cannot find a peer associated with your account, please register a new peer first', 404);
		}

		$peerLink = $peerLinkManager->getById((int) $peerLinkId);

		if (empty($peerLink) || ($peerLink['leftPeer'] != $userPeer['id'] && $peerLink['rightPeer'] != $userPeer['id'])) {
			throw new RuntimeException('Cannot find a peer link with id "'.$peerLinkId.'"', 404);
		}

		$peerLinkManager->delete($peerLink['id']);
	}

	// PEER SERVER

	public static function setPeerServer(PeerServer &$peerServer) {
		self::$peerServer = $peerServer;
	}

	public static function setApiServer(ApiWebSocketServer &$apiServer) {
		self::$apiServer = $apiServer;
	}

	protected function peerServer() {
		if (empty(self::$peerServer)) {
			throw new RuntimeException('Cannot contact PeerServer from PeerController, please try again');
		}

		return self::$peerServer;
	}

	protected function apiServer() {
		if (empty(self::$apiServer)) {
			throw new RuntimeException('Cannot contact ApiWebSocketServer from PeerController, please try again');
		}

		return self::$apiServer;
	}
}