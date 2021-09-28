<?php
if (!defined('DOKU_INC')) { die(); }

/**
 * DokuWiki Plugin matrixnotifier (Helper Component)
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 *
 * @author ?
 * @author Wilhelm/ JPTV.club
 */

class helper_plugin_matrixnotifier extends \dokuwiki\Extension\Plugin
{
	var $_event = null;
	var $_event_type = array(
		'E' => 'edit',
		'e' => 'edit minor',
		'C' => 'create',
		'D' => 'delete',
		'R' => 'revert',  /* TODO: we don't seem to support this. */
	);
	var $_summary = null;
	var $_payload = null;

	public function setPayload($payload)
	{
		$this->_payload = $payload;
	}

	public function attic_write($filename)
	{
		return (strpos($filename, 'data/attic') !== false);
	}

	public function valid_namespace()
	{
		global $INFO;
		$validNamespaces = $this->getConf('namespaces');
		if (!empty($validNamespaces))
		{
			$validNamespacesArr = explode(',', $validNamespaces);
			$thisNamespaceArr   = explode(':', $INFO['namespace']);
			return in_array($thisNamespaceArr[0], $validNamespacesArr);
		}
		return true;
	}

	public function set_event($event)
	{
		$this->_opt = print_r($event, true);
		$changeType = $event->data['changeType'];
		$event_type = $this->_event_type[$changeType];

		$summary = $event->data['summary'];
		if (!empty($summary))
		{
			$this->_summary = $summary;
		}

		if (($event_type == 'create') && ($this->getConf('notify_create') == 1))
		{
			$this->_event = 'create';
			return true;
		}
		elseif (($event_type == 'edit') && ($this->getConf('notify_edit') == 1))
		{
			$this->_event = 'edit';
			return true;
		}
		elseif (($event_type == 'edit minor') && ($this->getConf('notify_edit') == 1) && ($this->getConf('notify_edit_minor') == 1))
		{
			$this->_event = 'edit minor';
			return true;
		}
		elseif (($event_type == 'delete') && ($this->getConf('notify_delete') == 1))
		{
			$this->_event = 'delete';
			return true;
		}

		return false;
	}

	public function set_payload_text($event)
	{
		global $conf;
		global $lang;
		global $INFO;

		$user = strip_tags($INFO['userinfo']['name']);
		$link = $this->_get_url($event, null);
		$page = strip_tags($event->data['id']);

		$data = [
			'create'     => ['loc_title' => 't_created',   'loc_event' => 'e_created',   'emoji' => '📄'],
			'edit'       => ['loc_title' => 't_updated',   'loc_event' => 'e_updated',   'emoji' => '📝'],
			'edit minor' => ['loc_title' => 't_minor_upd', 'loc_event' => 'e_minor_upd', 'emoji' => '📝'],
			'delete'     => ['loc_title' => 't_removed',   'loc_event' => 'e_removed',   'emoji' => "\u{1F5D1}"],  # Note: wastebasket emoji
		];

		$d          = $data[$this->_event];
		$title      = strip_tags($this->getLang($d['loc_title']));
		$event      = $user.' '.$this->getLang($d['loc_event']);

		$descr_raw  = $title.' · '.$event.' "'.$page.'" ('.$link.')';
		$descr_html = $d['emoji'].' <strong>'.$title.'</strong> · '.$event.' &quot;<a href="'.$link.'">'.$page.'</a>&quot;';

		if (($this->_event != 'delete') && ($this->_event != 'create'))
		{
			$oldRev = $INFO['meta']['last_change']['date'];

			if (!empty($oldRev))
			{
				$diffURL     = $this->_get_url($event, $oldRev);
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

	private function _get_url($event = null, $rev = null)
	{
		global $ID;
		global $conf;

		// $oldRev = $event->data['oldRevision'];
		$page   = $event->data['id'];

		if ((($conf['userewrite'] == 1) || ($conf['userewrite'] == 2)) && $conf['useslash'] == true)
		{
			$page = str_replace(":", "/", $page);
		}

		switch($conf['userewrite'])
		{
			case 0:
				$url = DOKU_URL."doku.php?id={$page}";
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
			switch($conf['userewrite'])
			{
				case 0:
					$url .= "&do=diff&rev={$rev}";
					break;
				case 1:
				case 2:
					$url .= "?do=diff&rev={$rev}";
					break;
			}
		}

		return $url;
	}

	public function submit_payload()
	{
		global $conf;

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
			 *  Note: entirely untested
			 */
			$proxy = $conf['proxy'];
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
					$proxyAuth = $proxy['user'].':'.conf_decodeString($proxy['port']);
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
	
	public function shouldBeSend($filename)
	{
		if($this->attic_write($filename))
		{
			return false;
		}

		if(!$this->valid_namespace())
		{
			return false;
		}

		return true;
	}

	public function sendUpdate($event)
	{
		if ($this->shouldBeSend($event->data['file']))
		{
			if ($this->set_event($event))
			{
				$this->set_payload_text($event);
				$this->submit_payload();
			}
		}
	}
}
