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
	CONST __PLUGIN_VERSION__ = '1.3';
	
	private $_event   = null;
	private $_summary = null;
	private $_payload = null;

	private function valid_namespace()
	{
		$validNamespaces = $this->getConf('namespaces');
		if (!empty($validNamespaces))
		{
			$validNamespacesArr = array_map('trim', explode(',', $validNamespaces));
			$thisNamespaceArr   = explode(':', $GLOBALS['INFO']['namespace']);

			return in_array($thisNamespaceArr[0], $validNamespacesArr);
		}

		return true;
	}

	private function check_event($event)
	{
		$etype = $event->data['changeType'];

		if (($etype == 'C') && ($this->getConf('notify_create') == 1))
		{
			$this->_event = 'create';
		}
		elseif (($etype == 'E') && ($this->getConf('notify_edit') == 1))
		{
			$this->_event = 'edit';
		}
		elseif (($etype == 'e') && ($this->getConf('notify_edit') == 1) && ($this->getConf('notify_edit_minor') == 1))
		{
			$this->_event = 'edit minor';
		}
		elseif (($etype == 'D') && ($this->getConf('notify_delete') == 1))
		{
			$this->_event = 'delete';
		}
		/*
		elseif (($etype == 'R') && ($this->getConf('notify_revert') == 1))
		{
			$this->_event = 'revert';
			return true;
		}
		*/
		else
		{
			return false;
		}

		$summary = $event->data['summary'];
		if (!empty($summary))
		{
			$this->_summary = $summary;
		}

		return true;
	}

	private function update_payload($event)
	{
		$user = $GLOBALS['INFO']['userinfo']['name'];
		if (empty($user))
		{
			$user = sprintf($this->getLang('anonymous'), gethostbyaddr($_SERVER['REMOTE_ADDR'])); /* TODO: do we need to handle fail safe? */
		}
		
		$link = $this->compose_url($event, null);
		$page = $event->data['id'];

		$data = [
			'create'     => ['loc_title' => 't_created',   'loc_event' => 'e_created',   'emoji' => '📄'],
			'edit'       => ['loc_title' => 't_updated',   'loc_event' => 'e_updated',   'emoji' => '📝'],
			'edit minor' => ['loc_title' => 't_minor_upd', 'loc_event' => 'e_minor_upd', 'emoji' => '📝'],
			'delete'     => ['loc_title' => 't_removed',   'loc_event' => 'e_removed',   'emoji' => "\u{1F5D1}"],  /* 'Wastebasket' emoji */
		];

		$d          = $data[$this->_event];
		$title      = $this->getLang($d['loc_title']);
		$useraction = $user.' '.$this->getLang($d['loc_event']);

		$descr_raw  = $title.' · '.$useraction.' "'.$page.'" ('.$link.')';
		$descr_html = $d['emoji'].' <strong>'.htmlspecialchars($title).'</strong> · '.htmlspecialchars($useraction).' &quot;<a href="'.$link.'">'.htmlspecialchars($page).'</a>&quot;';

		if (($this->_event != 'delete') && ($this->_event != 'create'))
		{
			$oldRev = $GLOBALS['INFO']['meta']['last_change']['date'];

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
		$userewrite = $GLOBALS['conf']['userewrite']; /* 0 = no rewrite, 1 = htaccess, 2 = internal */

		if ((($userewrite == 1) || ($userewrite == 2)) && $GLOBALS['conf']['useslash'] == true)
		{
			$page = str_replace(":", "/", $page);
		}

		$url = sprintf(['%sdoku.php?id=%s', '%s%s', '%sdoku.php/%s'][$userewrite], DOKU_URL, $page);

		if ($rev != null)
		{
			$url .= ('&??'[$userewrite])."do=diff&rev={$rev}";
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
			error_log('matrixnotifer: At least one of the required configuration options \'homeserver\', \'room\', or \'accesstoken\' is not set.');
			return;
		}

		$homeserver = rtrim(trim($homeserver), '/');
		$endpoint = $homeserver.'/_matrix/client/r0/rooms/'.rawurlencode($roomid).'/send/m.room.message/'.uniqid('docuwiki', true).'-'.md5(strval(random_int(0, PHP_INT_MAX)));
		

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
			$proxy = $GLOBALS['conf']['proxy'];
			if (!empty($proxy['host']))
			{
				// configure proxy address and port
				$proxyAddress = $proxy['host'].':'.$proxy['port'];
				curl_setopt($ch, CURLOPT_PROXY,          $proxyAddress);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        	
				// include username and password if defined
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
				'User-agent: DocuWiki Matrix Notifier Plugin '.self::__PLUGIN_VERSION__,
				'Authorization: Bearer '.$accesstoken,
				'Cache-control: no-cache',
			));
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			/* kludge, temp. fix for Let's Encrypt madness.
			 */
			if($this->getConf('nosslverify'))
			{
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			}
			
			$r = curl_exec($ch);

			if ($r === false)
			{
				error_log('matrixnotifier: curl_exec() failure <'.strval(curl_error($ch)).'>');
			}
			
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
