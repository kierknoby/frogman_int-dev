<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddQueue.php';

// Persistent queue-member add. Distinct from fm_queue_add_agent which adds via
// AMI QueueAdd (live-only, lost on Asterisk restart). This tool writes the
// member into queues_details so it survives reload + restart.
//
// FreePBX itself has no "add one member" function — the canonical edit path is
// queues_del + queues_add with the full member list. We follow that pattern.
class AddQueueMember extends AbstractTool {
	public function name() { return 'fm_add_queue_member'; }
	public function description() { return 'Add an extension as a persistent static member of a queue (survives reload/restart). Params: queue (required, queue number), ext (required, extension number), penalty (optional, 0-99, default 0). Requires confirm:true. For live-only/runtime add via AMI use fm_queue_add_agent instead.'; }
	public function validate($params) {
		if (empty($params['queue'])) return 'Parameter "queue" is required';
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d{1,11}$/', (string)$params['queue'])) return 'Parameter "queue" must be 1-11 digits';
		if (!preg_match('/^\d{1,11}$/', (string)$params['ext'])) return 'Parameter "ext" must be 1-11 digits';
		if (isset($params['penalty']) && (!preg_match('/^\d+$/', (string)$params['penalty']) || (int)$params['penalty'] > 99)) return 'Parameter "penalty" must be 0-99';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$account = (string)$params['queue'];
		$ext = (string)$params['ext'];
		$penalty = (int)($params['penalty'] ?? 0);

		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_get')) return ['error' => 'queues_get() not available — Queues module not loaded'];
		$current = queues_get($account);
		if (empty($current)) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` not found"];
		}

		$members = is_array($current['member'] ?? null) ? array_values($current['member']) : [];

		// Duplicate detection — same extension already a static member regardless of penalty.
		foreach ($members as $m) {
			if (AddQueue::extractMemberExt($m) === $ext) {
				$accountSan = $this->frogman->sanitizeForChat($account);
				$extSan = $this->frogman->sanitizeForChat($ext);
				return ['error' => "Extension `{$extSan}` is already a static member of queue `{$accountSan}`. Use fm_update_queue to change penalty."];
			}
		}

		$newMember = "Local/{$ext}@from-queue/n,{$penalty}";
		$accountSan = $this->frogman->sanitizeForChat($account);
		$extSan = $this->frogman->sanitizeForChat($ext);
		$nameSan = $this->frogman->sanitizeForChat((string)($current['name'] ?? ''));

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would add extension `{$extSan}` to queue `{$accountSan}` `{$nameSan}` (penalty {$penalty}). Queue will be rewritten (FreePBX-canonical del+add edit pattern).\n\nReply yes to confirm.", 'queue' => $account, 'ext' => $ext, 'penalty' => $penalty];
		}

		$members[] = $newMember;

		$merged = UpdateQueueMemberHelper::buildMergedFromCurrent($current, $account);
		$merged['members'] = $members;

		if (!function_exists('queues_del')) return ['error' => 'queues_del() not available'];
		$prior = $_REQUEST;
		$_REQUEST['action'] = 'edit';
		try {
			queues_del($account);
		} finally {
			$_REQUEST = $prior;
		}
		AddQueue::writeQueue($this->freepbx, $merged, $current);

		return ['dry_run' => false, 'message' => "✅ Extension `{$extSan}` added to queue `{$accountSan}` `{$nameSan}` (penalty {$penalty}). Queue now has " . count($members) . " static member(s).", 'queue' => $account, 'ext' => $ext, 'penalty' => $penalty, 'needs_reload' => true];
	}
}

// Shared helper for member add/remove tools. Builds the queues_add() args bag
// from a queues_get() snapshot. Kept in this file rather than on AddQueue to
// avoid bloating that class with edit-only plumbing.
class UpdateQueueMemberHelper {
	// Only the positional args of queues_add() belong here. Everything else is
	// left out on purpose so writeQueue($freepbx, $merged, $current) reads it back
	// out of $current — changing a queue's membership must not touch its settings.
	// (Re-listing fields here re-introduced the old clobber: (int) on a retry of
	// "none" became 0, and an inheriting MoH was pinned to "default".)
	public static function buildMergedFromCurrent(array $current, $account) {
		return [
			'account'          => (string)$account,
			'name'             => (string)($current['name'] ?? ''),
			'password'         => (string)($current['password'] ?? ''),
			'prefix'           => (string)($current['prefix'] ?? ''),
			'fail_destination' => (string)($current['goto'] ?? ''),
			'alertinfo'        => (string)($current['alertinfo'] ?? ''),
			'maxwait'          => (int)($current['maxwait'] ?? 0),
		];
	}
}
