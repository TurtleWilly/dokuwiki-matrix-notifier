<?php
if (!defined('DOKU_INC')) { die(); }

/**
 * DokuWiki Plugin matrixnotifier (Helper Component)
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 *
 * @author Wilhelm/ JPTV.club
 */

class helper_plugin_matrixnotifier extends \dokuwiki\Extension\Plugin
{
	private $_event   = null;
	private $_summary = null;
	private $_payload = null;

	private function valid_namespace()
	{
		global $INFO; /* TODO: yikes! */

		$validNamespaces = $this->getConf('namespaces');
		if (!empty($validNamespaces))
		{
			$validNamespacesArr = array_map('trim', explode(',', $validNamespaces));
			$thisNamespaceArr   = explode(':', $INFO['namespace']);

			return in_array($thisNamespaceArr[0], $validNamespacesArr);
		}

		return true;
	}

	private function check_event($event)
	{
		$this->_opt = print_r($event, true); /* TODO: what is this required for exactly? */

		$summary = $event->data['summary'];
		if (!empty($summary))
		{
			$this->_summary = $summary;
		}

		$etype = $event->data['changeType'];
		if (($etype == 'C') && ($this->getConf('notify_create') == 1))
		{
			$this->_event = 'create';
			return true;
		}
		elseif (($etype == 'E') && ($this->getConf('notify_edit') == 1))
		{
			$this->_event = 'edit';
			return true;
		}
		elseif (($etype == 'e') && ($this->getConf('notify_edit') == 1) && ($this->getConf('notify_edit_minor') == 1))
		{
			$this->_event = 'edit minor';
			return true;
		}
		elseif (($etype == 'D') && ($this->getConf('notify_delete') == 1))
		{
			$this->_event = 'delete';
			return true;
		}
		/*
		elseif (($etype == 'R') && ($this->getConf('notify_revert') == 1))
		{
			$this->_event = 'revert';
			return true;
		}
		*/

		return false;
	}

	private function update_payload($event)
	{
		global $INFO; /* TODO: yikes! -> pageinfo() ? */

		$user = strip_tags($INFO['userinfo']['name']);
		$link = $this->compose_url($event, null);
		$page = strip_tags($event->data['id']);

		$data = [
			'create'     => ['loc_title' => 't_created',   'loc_event' => 'e_created',   'emoji' => '📄'],
			'edit'       => ['loc_title' => 't_updated',   'loc_event' => 'e_updated',   'emoji' => '📝'],
			'edit minor' => ['loc_title' => 't_minor_upd', 'loc_event' => 'e_minor_upd', 'emoji' => '📝'],
			'delete'     => ['loc_title' => 't_removed',   'loc_event' => 'e_removed',   'emoji' => "\u{1F5D1}"],  # Note: wastebasket emoji
		];

		$d          = $data[$this->_event];
		$title      = strip_tags($this->getLang($d['loc_title']));
		$useraction = $user.' '.$this->getLang($d['loc_event']);

		$descr_raw  = $title.' · '.$useraction.' "'.$page.'" ('.$link.')';
		$descr_html = $d['emoji'].' <strong>'.$title.'</strong> · '.$useraction.' &quot;<a href="'.$link.'">'.$page.'</a>&quot;';

		if (($this->_event != 'delete') && ($this->_event != 'create'))
		{
			$oldRev = $INFO['meta']['last_change']['date'];

			if (!empty($oldRev))
			{
				$diffURL     = $this->compose_url($event, $oldRev);
				$descr_raw  .= ' ('.$this->getLang('compare').': '.$diffURL.')'; 
				$descr_html .= ' (<a href="'.$diffURL.'">'.$this->getLang('compare').'</a>)';
			}
		}

		if (($this->_event != 'delete') && $this->getConf('notify_show_summary'))
		{
			$summary = strip_tags($this->_summary);

			if ($summary)
			{
				$descr_raw  .= ' · '.$this->getLang('l_summary').': '.$summary;
				$descr_html .= ' · '.$this->getLang('l_summary').': <i>'.$summary.'</i>';
			}
		}

		$this->_payload = array(
			'msgtype'        => 'm.text',
			'body'           => $descr_raw,
			'format'         => 'org.matrix.custom.html',
			'formatted_body' => $descr_html,
		);
	}

	private function compose_url($event = null, $rev = null)
	{
		$page       = $event->data['id'];
		$userewrite = $this->getConf('userewrite');

		if ((($userewrite == 1) || ($userewrite == 2)) && $this->getConf('useslash') == true)
		{
			$page = str_replace(":", "/", $page);
		}

		switch($userewrite)
		{
			case 0:
				$url = DOKU_URL."doku.php?id={$page}";  /* TODO: DOKU_URL usage */
				break;
			case 1:
				$url = DOKU_URL.$page;
				break;
			case 2:
				$url = DOKU_URL."doku.php/{$page}";
				break;
		}

		if ($rev != null)
		{
			switch($userewrite)
			{
				case 0:
					$url .= "&do=diff&rev={$rev}"; break;
				case 1:
				case 2:
					$url .= "?do=diff&rev={$rev}";
					break;
			}
		}

		return $url;
	}

	private function submit_payload()
	{
		$homeserver  = $this->getConf('homeserver');
		$roomid      = $this->getConf('room');
		$accesstoken = $this->getConf('accesstoken');

		if (!($homeserver && $roomid && $accesstoken))
		{
			/* TODO: error handling, should dump some information about bad config to a log?
			 */
			return;
		}

		$homeserver = rtrim(trim($homeserver), '/');
		$endpoint = $homeserver.'/_matrix/client/r0/rooms/'.$roomid.'/send/m.room.message/'.uniqid('docuwiki', true).'-'.md5(strval(random_int(0, PHP_INT_MAX)));

		$json_payload = json_encode($this->_payload);
		if (!is_string($json_payload))
		{
			return;
		}

		$ch = curl_init($endpoint);
		if ($ch)
		{
			/*  Use a proxy, if defined
			 *
			 *  Note: still entirely untested, was full of very obvious bugs, so nobody
			 *        has ever used this succesfully anyway
			 */
			$proxy = $this->getConf('proxy');
			if (!empty($proxy['host']))
			{
				/* configure proxy address and port
				 */
				$proxyAddress = $proxy['host'].':'.$proxy['port'];
				curl_setopt($ch, CURLOPT_PROXY,          $proxyAddress);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        	
				/* include username and password if defined
				 */
				if (!empty($proxy['user']) && !empty($proxy['pass']))
				{
					$proxyAuth = $proxy['user'].':'.conf_decodeString($proxy['pass']);
					curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyAuth );
				}
			}
        	
			/* Submit Payload
			 */
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'Content-type: application/json',
				'Content-length: '.strlen($json_payload),
				'User-agent: DocuWiki Matrix Notifier Plugin',  /* TODO: add some version information here? */
				'Authorization: Bearer '.$accesstoken,
				'Cache-control: no-cache',
			));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_exec($ch);
        	
			curl_close($ch);
		}
	}
	
	public function sendUpdate($event)
	{
		if((strpos($event->data['file'], 'data/attic') === false) && $this->valid_namespace() && $this->check_event($event))
		{
			$this->update_payload($event);
			$this->submit_payload();
		}
	}
}
