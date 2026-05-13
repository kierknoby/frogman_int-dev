<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetVoicemail extends AbstractTool {
	public function name() { return 'fm_get_voicemail'; }
	public function description() { return 'Get voicemail box details and message count. Params: ext (required).'; }
	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		// Defense in depth: $ext is interpolated into a filesystem path
		// (/var/spool/asterisk/voicemail/default/{ext}) later in execute().
		// Voicemail BMO's getMailbox() throws first for non-existent boxes
		// today, but a numeric-only check is one line of cheap insurance
		// against a future BMO behavior change letting a non-numeric value through.
		if (!preg_match('/^\d+$/', (string)$params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function execute($params, $context) {
		$ext = $params['ext'];
		$box = $this->freepbx->Voicemail->getMailbox($ext);
		if (empty($box)) throw new \Exception("Voicemail box {$ext} not found");
		// Count messages via filesystem
		$spooldir = '/var/spool/asterisk/voicemail/default/' . $ext;
		$newCount = is_dir($spooldir . '/INBOX') ? count(glob($spooldir . '/INBOX/msg*.txt')) : 0;
		$oldCount = is_dir($spooldir . '/Old') ? count(glob($spooldir . '/Old/msg*.txt')) : 0;
		$box['new_messages'] = $newCount;
		$box['old_messages'] = $oldCount;
		return $box;
	}
}
