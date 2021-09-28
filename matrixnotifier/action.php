<?php
/**
 * DokuWiki Plugin Matrix Notifier (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @author ?
 * @author Wilhelm/ JPTV.club
 */
if (!defined('DOKU_INC')) { die (); }

class action_plugin_matrixnotifier extends \dokuwiki\Extension\ActionPlugin
{
	public function register(Doku_Event_Handler $controller)
	{
		$controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, '_handle');
	}

	public function _handle(Doku_Event $event, $param)
	{
		$helper = plugin_load('helper', 'matrixnotifier');
		
		if ($helper->attic_write($event->data['file']))
		{
			return;
		}

		if (!$helper->valid_namespace())
		{
			return;
		}

		if(!$helper->set_event($event))
		{
			return;
		}

		$helper->set_payload_text($event);
		$helper->submit_payload();
	}
}
