<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';
require_once __DIR__ . '/AddQueue.php';

// See AddQueue.php header for the in-walls routing justification.
// Edit path is "delete + re-add", mirroring Queues.class.php doConfigPageInit
// case "edit". queues_get() supplies the merged current state so unspecified
// fields are preserved.
class UpdateQueue extends AbstractTool {
	public function name() { return 'fm_update_queue'; }
	public function description() { return 'Update an existing call queue in place. Params: account (required). Any subset of name, strategy, timeout, retry, maxwait, fail_destination, mohclass, password, prefix, alertinfo, wrapuptime, weight, joinempty, leavewhenempty, announce_position, announce_holdtime, recording is merged in. members, if supplied, replaces the full member list (array of extension numbers or {ext, penalty} objects). Every setting you do NOT name is read back from the queue and preserved — including the ones this tool has no parameter for (service level, member delay, announce frequencies, autofill, ring-in-use, agent/join announcements, call confirm, monitoring, auto-pause). Requires confirm:true.'; }

	public function validate($params) {
		if (empty($params['account'])) return 'Parameter "account" is required';
		if (!preg_match('/^\d{1,11}$/', (string)$params['account'])) return 'Parameter "account" must be 1-11 digits';
		$err = AddQueue::validateCore($params, true);
		return $err === '' ? true : $err;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Coerce queues_get() output into the args shape writeQueue() expects.
	//
	// Only two kinds of field belong here:
	//   1. the positional args of queues_add(), which must always be supplied;
	//   2. fields the caller actually named.
	// Everything else is deliberately LEFT OUT so applyRequest()/writeQueue() fall
	// back to the queue's current value. Naming a field here that the caller did
	// not pass is how the old version silently rewrote it — e.g. (int)'none' on a
	// retry of "none" quietly became 0.
	private function buildMerged(array $current, array $params) {
		$merged = [
			'account'          => $params['account'],
			'name'             => isset($params['name']) ? trim((string)$params['name']) : (string)($current['name'] ?? ''),
			'password'         => isset($params['password']) ? (string)$params['password'] : (string)($current['password'] ?? ''),
			'prefix'           => isset($params['prefix']) ? (string)$params['prefix'] : (string)($current['prefix'] ?? ''),
			'fail_destination' => isset($params['fail_destination']) ? (string)$params['fail_destination'] : (string)($current['goto'] ?? ''),
			'alertinfo'        => isset($params['alertinfo']) ? (string)$params['alertinfo'] : (string)($current['alertinfo'] ?? ''),
			'maxwait'          => isset($params['maxwait']) ? (int)$params['maxwait'] : (int)($current['maxwait'] ?? 0),
		];

		// Integer-typed fields: cast only what the caller supplied.
		foreach (['timeout', 'retry', 'wrapuptime', 'weight'] as $f) {
			if (isset($params[$f])) $merged[$f] = (int)$params[$f];
		}
		// Pass-through fields: forward only what the caller supplied.
		foreach (['strategy', 'joinempty', 'leavewhenempty', 'announce_position',
		          'announce_holdtime', 'recording', 'mohclass'] as $f) {
			if (isset($params[$f])) $merged[$f] = $params[$f];
		}

		return $merged;
	}

	// Diff for chat preview. Only includes fields the caller named.
	private function buildDiff(array $current, array $params) {
		$diff = [];
		$pairs = [
			['name', 'name', 'name'],
			['password', 'password', 'password'],
			['prefix', 'prefix', 'prefix'],
			['fail_destination', 'goto', 'fail destination'],
			['alertinfo', 'alertinfo', 'alertinfo'],
			['maxwait', 'maxwait', 'maxwait'],
			['strategy', 'strategy', 'strategy'],
			['timeout', 'timeout', 'timeout'],
			['retry', 'retry', 'retry'],
			['wrapuptime', 'wrapuptime', 'wrapuptime'],
			['weight', 'weight', 'weight'],
			['joinempty', 'joinempty', 'joinempty'],
			['leavewhenempty', 'leavewhenempty', 'leavewhenempty'],
			['announce_position', 'announce-position', 'announce_position'],
			['announce_holdtime', 'announce-holdtime', 'announce_holdtime'],
			['recording', 'recording', 'recording'],
			['mohclass', 'music', 'mohclass'],
		];
		foreach ($pairs as [$pkey, $ckey, $label]) {
			if (!array_key_exists($pkey, $params)) continue;
			$old = (string)($current[$ckey] ?? '');
			$new = (string)$params[$pkey];
			if ($old !== $new) {
				$oldSan = $this->frogman->sanitizeForChat($old);
				$newSan = $this->frogman->sanitizeForChat($new);
				$diff[] = "{$label}: `{$oldSan}` → `{$newSan}`";
			}
		}
		return $diff;
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$account = (string)$params['account'];

		// Load current state via the canonical reader.
		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_get')) return ['error' => 'queues_get() not available — Queues module not loaded'];
		$current = queues_get($account);
		if (empty($current)) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` not found"];
		}

		// Members: preserve current if not supplied, full-replace otherwise.
		$currentMembers = is_array($current['member'] ?? null) ? array_values($current['member']) : [];
		$membersSupplied = isset($params['members']);
		if ($membersSupplied) {
			if (!is_array($params['members'])) return ['error' => '"members" must be an array'];
			$newMembers = [];
			foreach ($params['members'] as $i => $m) {
				$err = '';
				$norm = AddQueue::normalizeMember($m, $err);
				if ($norm === null) return ['error' => "members[{$i}]: {$err}"];
				$newMembers[] = $norm;
			}
		} else {
			$newMembers = $currentMembers;
		}

		$merged = $this->buildMerged($current, $params);
		$merged['members'] = $newMembers;

		$diff = $this->buildDiff($current, $params);
		$membersChanged = $membersSupplied && (
			count($newMembers) !== count($currentMembers) ||
			array_values(array_unique($newMembers)) !== array_values(array_unique($currentMembers))
		);
		if ($membersChanged) {
			$diff[] = 'members: ' . count($currentMembers) . ' → ' . count($newMembers) . ' member(s)';
		}

		$accountSan = $this->frogman->sanitizeForChat($account);
		$nameSan = $this->frogman->sanitizeForChat((string)($current['name'] ?? ''));

		if (empty($diff)) {
			return ['dry_run' => true, 'message' => "No changes detected for queue `{$accountSan}` `{$nameSan}`."];
		}

		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would update queue `{$accountSan}` `{$nameSan}`:\n• " . implode("\n• ", $diff) . "\n\nReply yes to confirm."];
		}

		// del + re-add (canonical edit path — Queues.class.php case "edit" line 166-167).
		if (!function_exists('queues_del')) return ['error' => 'queues_del() not available'];
		$prior = $_REQUEST;
		$_REQUEST['action'] = 'edit';
		try {
			queues_del($account);
		} finally {
			$_REQUEST = $prior;
		}

		// $current is what makes this an edit rather than a re-create: every field
		// the caller didn't name is read back out of it instead of being reset.
		AddQueue::writeQueue($this->freepbx, $merged, $current);

		return ['dry_run' => false, 'message' => "✅ Queue `{$accountSan}` `{$nameSan}` updated (" . count($diff) . " field(s) changed).", 'account' => $account, 'needs_reload' => true];
	}
}
