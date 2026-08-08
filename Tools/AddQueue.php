<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

// Routing surface for queue writes:
//   1. $freepbx->Queues->X(): only read methods (listQueues, getQueuesDetails,
//      getDynMembersOfQueue, parseQueueVal). No facade for write.
//   2. REST API /queues: GET endpoints only; PUT exists only for /members/{id}.
//      No POST for queue create, no DELETE for queue removal.
//   3. queues_add() / queues_del() in queues/functions.inc/geters_seters.php:
//      what FreePBX itself uses — Queues.class.php doConfigPageInit calls them
//      directly on form submit. Edit path is "del + re-add" (same as Core's
//      editUser/editDevice pattern).
//   4. Direct DB writes: forbidden by Frogman Hard Rule 3.
// We're on rung 3 because rungs 1 and 2 don't exist. If queues_add changes
// shape, the GUI form handler breaks identically, so this is de facto in-walls.
// Reads stay on the BMO facade.
//
// queues_add() reads many config fields directly from $_REQUEST, so applyRequest()
// below pre-populates them with sensible defaults; caller restores prior $_REQUEST.
class AddQueue extends AbstractTool {
	public function name() { return 'fm_add_queue'; }
	public function description() { return 'Create a call queue. Params: account (required, queue number, digits), name (required, queue description), strategy (optional, one of ringall|leastrecent|fewestcalls|random|rrmemory|linear|wrandom, default ringall), timeout (optional seconds per member ring, default 15), retry (optional seconds between attempts, default 5), maxwait (optional max queue wait seconds, 0 unlimited, default 0), fail_destination (optional dialplan goto when timed out / no agents, e.g. "from-did-direct,1001,1"), mohclass (optional, default "default"), members (optional, array of extension numbers OR objects {ext, penalty} OR "ext:penalty" strings; resolved to Local/<ext>@from-queue/n,<penalty>), password (optional PIN), prefix (optional CID prefix), alertinfo (optional SIP Alert-Info), wrapuptime (optional, default 0), weight (optional, default 0), joinempty (optional yes|no, default yes), leavewhenempty (optional yes|no, default no), announce_position (optional yes|no, default no), announce_holdtime (optional yes|no, default no), recording (optional one of dontcare|yes|no|force|never|always|onlyincoming, default dontcare). Requires confirm:true.'; }

	const VALID_STRATEGIES = ['ringall','leastrecent','fewestcalls','random','rrmemory','linear','wrandom'];
	const VALID_RECORDING = ['dontcare','yes','no','force','never','always','onlyincoming'];

	public function validate($params) {
		$err = self::validateCore($params, false);
		return $err === '' ? true : $err;
	}

	// Field validation shared with UpdateQueue (which makes every field optional).
	// $partial = true skips account/name required checks.
	public static function validateCore(array $params, $partial) {
		if (!$partial) {
			if (empty($params['account'])) return 'Parameter "account" is required (queue number)';
			if (empty($params['name'])) return 'Parameter "name" is required';
		}
		if (isset($params['account']) && !preg_match('/^\d{1,11}$/', (string)$params['account'])) return 'Parameter "account" must be 1-11 digits';
		if (isset($params['name']) && !preg_match('/^[a-zA-Z0-9_\-\s.]{1,80}$/', (string)$params['name'])) return 'Parameter "name" must be 1-80 chars, [a-zA-Z0-9_\-\s.] only';
		if (isset($params['strategy']) && !in_array($params['strategy'], self::VALID_STRATEGIES, true)) return 'Parameter "strategy" must be one of: ' . implode('|', self::VALID_STRATEGIES);
		foreach (['timeout','retry','maxwait','wrapuptime','weight'] as $f) {
			if (isset($params[$f]) && !preg_match('/^\d+$/', (string)$params[$f])) return "Parameter \"{$f}\" must be a non-negative integer";
		}
		if (!empty($params['password']) && !preg_match('/^\d{1,15}$/', (string)$params['password'])) return 'Parameter "password" must be 1-15 digits';
		foreach (['joinempty','leavewhenempty','announce_position','announce_holdtime'] as $f) {
			if (isset($params[$f]) && !in_array($params[$f], ['yes','no'], true)) return "Parameter \"{$f}\" must be 'yes' or 'no'";
		}
		if (isset($params['recording']) && !in_array($params['recording'], self::VALID_RECORDING, true)) return 'Parameter "recording" must be one of: ' . implode('|', self::VALID_RECORDING);
		// Control-char reject for fields that flow into the generated
		// extensions_additional.conf or SIP headers. Defense in depth — FreePBX
		// has its own conf sanitization layer, but the upstream guard is cheap.
		foreach (['prefix','alertinfo','fail_destination','mohclass'] as $f) {
			if (isset($params[$f]) && preg_match('/[\r\n\0]/', (string)$params[$f])) return "Parameter \"{$f}\" contains disallowed control characters";
		}
		if (isset($params['members'])) {
			if (!is_array($params['members'])) return 'Parameter "members" must be an array';
			foreach ($params['members'] as $i => $m) {
				$err = '';
				if (self::normalizeMember($m, $err) === null) return "members[{$i}]: {$err}";
			}
		}
		return '';
	}

	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Resolve a member spec into the "Local/EXT@from-queue/n,PENALTY" string
	// queues_details expects. Accepts:
	//   - "101"              → Local/101@from-queue/n,0
	//   - "101:1"            → Local/101@from-queue/n,1
	//   - ["ext"=>101]       → Local/101@from-queue/n,0
	//   - ["ext"=>101,"penalty"=>2] → Local/101@from-queue/n,2
	//   - "PJSIP/101,0"      → passed through (raw form trusted after control-char check)
	// Returns null on validation failure with $err populated. Static so the
	// member CRUD tools can reuse.
	public static function normalizeMember($spec, &$err) {
		$err = '';
		$ext = ''; $pen = 0;
		if (is_array($spec)) {
			$ext = (string)($spec['ext'] ?? '');
			$pen = (int)($spec['penalty'] ?? 0);
		} else {
			$s = (string)$spec;
			if (preg_match('#^(Local|PJSIP|SIP|IAX2|Agent|ZAP|DAHDI)/#', $s)) {
				if (preg_match('/[\r\n\0;]/', $s)) { $err = 'control chars not allowed in member string'; return null; }
				return $s;
			}
			if (strpos($s, ':') !== false) {
				[$ext, $pen] = explode(':', $s, 2);
			} elseif (strpos($s, ',') !== false) {
				[$ext, $pen] = explode(',', $s, 2);
			} else {
				$ext = $s;
			}
			$pen = (int)$pen;
		}
		$ext = trim((string)$ext);
		if (!preg_match('/^\d{1,11}$/', $ext)) { $err = "extension \"{$ext}\" must be 1-11 digits"; return null; }
		if ($pen < 0 || $pen > 99) { $err = "penalty \"{$pen}\" must be 0-99"; return null; }
		return "Local/{$ext}@from-queue/n,{$pen}";
	}

	// Extract the extension number from a stored member string for chat-summary
	// rendering. Returns "" if the shape is unrecognized.
	public static function extractMemberExt($memberStr) {
		if (preg_match('#^[A-Za-z]+/(\d+)#', (string)$memberStr, $m)) return $m[1];
		return '';
	}

	// Every $_REQUEST key queues_add() reads, as
	//   request key => [queues_get() key it round-trips from, literal default, tool param name]
	// Authoritative source: queues/functions.inc/geters_seters.php queues_add().
	// The queues_get() key is often spelled differently from the $_REQUEST key
	// (announcefreq → announce-frequency), which is exactly why a naive merge
	// silently drops fields.
	private static $requestMap = [
		'strategy'            => ['strategy',                    'ringall',  'strategy'],
		'timeout'             => ['timeout',                     '15',       'timeout'],
		'retry'               => ['retry',                       '5',        'retry'],
		'wrapuptime'          => ['wrapuptime',                  '0',        'wrapuptime'],
		'weight'              => ['weight',                      '0',        'weight'],
		'maxlen'              => ['maxlen',                      '0',        'maxlen'],
		'joinempty'           => ['joinempty',                   'yes',      'joinempty'],
		'leavewhenempty'      => ['leavewhenempty',              'no',       'leavewhenempty'],
		'announceposition'    => ['announce-position',           'no',       'announce_position'],
		'announceholdtime'    => ['announce-holdtime',           'no',       'announce_holdtime'],
		'announcefreq'        => ['announce-frequency',          '0',        'announce_frequency'],
		'min-announce'        => ['min-announce-frequency',      '15',       'min_announce_frequency'],
		'pannouncefreq'       => ['periodic-announce-frequency', '0',        'periodic_announce_frequency'],
		'recording'           => ['recording',                   'dontcare', 'recording'],
		'reportholdtime'      => ['reportholdtime',              'no',       'reportholdtime'],
		'autopause'           => ['autopause',                   'no',       'autopause'],
		'autopausedelay'      => ['autopausedelay',              '0',        'autopausedelay'],
		'autopausebusy'       => ['autopausebusy',               'no',       'autopausebusy'],
		'autopauseunavail'    => ['autopauseunavail',            'no',       'autopauseunavail'],
		'servicelevel'        => ['servicelevel',                '60',       'servicelevel'],
		'memberdelay'         => ['memberdelay',                 '0',        'memberdelay'],
		'timeoutrestart'      => ['timeoutrestart',              'no',       'timeoutrestart'],
		'timeoutpriority'     => ['timeoutpriority',             'app',      'timeoutpriority'],
		'skip_joinannounce'   => ['skip_joinannounce',           '',         'skip_joinannounce'],
		'answered_elsewhere'  => ['answered_elsewhere',          '0',        'answered_elsewhere'],
		'penaltymemberslimit' => ['penaltymemberslimit',         '0',        'penaltymemberslimit'],
		'rvolume'             => ['rvolume',                     '',         'rvolume'],
		'rvol_mode'           => ['rvol_mode',                   '',         'rvol_mode'],
		'rtone'               => ['rtone',                       '0',        'rtone'],
	];

	// queues_add() also reads these, but the create path deliberately leaves them
	// unset so FreePBX/$amp_conf supplies the default. On the update path we set
	// them ONLY when the queue already has a value, so an edit doesn't reset them.
	private static $preserveOnly = [
		'eventwhencalled'   => 'eventwhencalled',
		'eventmemberstatus' => 'eventmemberstatus',
		'announcemenu'      => 'announcemenu',
		'callback'          => 'callback',
	];

	// Pre-populate $_REQUEST with the fields queues_add() reads directly.
	// Returns the prior $_REQUEST so the caller can restore it after the call.
	// Static so UpdateQueue + member tools can reuse without subclassing.
	//
	// $current is queues_get() output on the UPDATE path and empty on create.
	// Resolution order is: explicit param > current value > literal default. With
	// $current empty every field collapses to the literal, so create behaviour is
	// byte-for-byte what it was before this parameter existed.
	public static function applyRequest(array $params, array $current = []) {
		$prior = $_REQUEST;
		$_REQUEST['action'] = $params['_action'] ?? 'add';

		foreach (self::$requestMap as $reqKey => [$curKey, $default, $paramKey]) {
			if (array_key_exists($paramKey, $params)) {
				$val = $params[$paramKey];
			} elseif (array_key_exists($curKey, $current) && $current[$curKey] !== null) {
				$val = $current[$curKey];
			} else {
				$val = $default;
			}
			$_REQUEST[$reqKey] = is_string($val) ? $val : (string)$val;
		}

		// autofill is the one field queues_add() stores as
		// (!empty($_REQUEST['autofill'])) ? 'yes' : 'no' — the literal string 'no'
		// is truthy there and would be written back as 'yes'. Blank it to mean no.
		$autofill = $params['autofill'] ?? ($current['autofill'] ?? 'yes');
		$_REQUEST['autofill'] = ($autofill === 'no' || $autofill === '' || $autofill === '0') ? '' : 'yes';

		foreach (self::$preserveOnly as $reqKey => $curKey) {
			if (array_key_exists($curKey, $current) && $current[$curKey] !== null && $current[$curKey] !== '') {
				$_REQUEST[$reqKey] = (string)$current[$curKey];
			}
		}

		// MoH: 'inherit' makes queues_add skip the music row entirely. A queue with
		// no music keyword is inheriting, so preserve that rather than pinning it
		// to an explicit 'default'.
		if (array_key_exists('mohclass', $params)) {
			$_REQUEST['music'] = (string)$params['mohclass'];
		} elseif (array_key_exists('music', $current)) {
			$_REQUEST['music'] = (string)$current['music'];
		} else {
			$_REQUEST['music'] = empty($current) ? 'default' : 'inherit';
		}

		return $prior;
	}

	// Core writer used by Add + Update + member tools. All inputs already
	// validated by the caller. Pre-populates $_REQUEST around queues_add().
	//
	// $current is queues_get() output on the UPDATE path, empty on create. The
	// positional args below are the second place a queue's settings can be lost:
	// they are NOT read from $_REQUEST, so hardcoding them reset agent/join
	// announcements, call confirm and monitoring on every edit. Each now falls
	// back to the current value, and to the original literal when $current is
	// empty — so create is unchanged.
	public static function writeQueue($freepbx, array $args, array $current = []) {
		$freepbx->Modules->loadFunctionsInc('queues');
		if (!function_exists('queues_add')) throw new \Exception('queues_add() not available — Queues module not loaded');

		// cur(key, literal): current value if the queue has one, else the literal.
		$cur = function ($key, $literal) use ($current) {
			if (!array_key_exists($key, $current) || $current[$key] === null || $current[$key] === '') return $literal;
			return (string)$current[$key];
		};

		// queues_add() derives ringinuse from cwignore (2 or 3 => ringinuse 'no'),
		// so carrying cwignore through is what preserves ringinuse. There is no way
		// to set ringinuse independently through this path.
		$agentannounce = $cur('agentannounce_id', null);
		$joinannounce  = $cur('joinannounce_id', null);

		// dynmembers must be an array — queues_add()'s '' default crashes array_unique.
		$dynmembers = (isset($current['dynmembers']) && is_array($current['dynmembers'])) ? $current['dynmembers'] : [];

		$prior = self::applyRequest($args, $current);
		try {
			queues_add(
				$args['account'],
				$args['name'],
				(string)($args['password'] ?? ''),
				(string)($args['prefix'] ?? ''),
				(string)($args['fail_destination'] ?? ''),
				$agentannounce,
				$args['members'] ?? [],
				$joinannounce,
				isset($args['maxwait']) ? (string)(int)$args['maxwait'] : '0',
				(string)($args['alertinfo'] ?? ''),
				$cur('cwignore', '0'),
				$cur('qregex', ''),
				$cur('queuewait', '0'),
				$cur('use_queue_context', '0'),
				$dynmembers,
				$cur('dynmemberonly', 'no'),
				$cur('togglehint', '0'),
				$cur('qnoanswer', '0'),
				$cur('callconfirm', '0'),
				$cur('callconfirm_id', ''),
				$cur('monitor_type', ''),
				$cur('monitor_heard', '0'),
				$cur('monitor_spoken', '0'),
				$cur('answered_elsewhere', '0')
			);
		} finally {
			$_REQUEST = $prior;
		}
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$account = (string)$params['account'];
		$name = trim((string)$params['name']);

		$members = [];
		if (!empty($params['members']) && is_array($params['members'])) {
			foreach ($params['members'] as $i => $m) {
				$err = '';
				$norm = self::normalizeMember($m, $err);
				if ($norm === null) return ['error' => "members[{$i}]: {$err}"];
				$members[] = $norm;
			}
		}

		// Conflict check against the full FreePBX object space.
		if (function_exists('framework_check_extension_usage')) {
			$usage = framework_check_extension_usage($account);
			if (!empty($usage)) {
				$accountSan = $this->frogman->sanitizeForChat($account);
				return ['error' => "Queue number `{$accountSan}` conflicts with an existing extension/route/queue/conference. Choose a different number."];
			}
		}

		// DB-based pre-existence check. getQueuesDetails() reads AMI "queue show"
		// which is empty until reload — would miss a queue that exists in DB but
		// hasn't been pushed to Asterisk yet.
		$this->freepbx->Modules->loadFunctionsInc('queues');
		if (function_exists('queues_get') && !empty(queues_get($account))) {
			$accountSan = $this->frogman->sanitizeForChat($account);
			return ['error' => "Queue `{$accountSan}` already exists. Use fm_update_queue to change it, or fm_remove_queue first."];
		}

		$nameSan = $this->frogman->sanitizeForChat($name);
		$accountSan = $this->frogman->sanitizeForChat($account);
		$strategy = $params['strategy'] ?? 'ringall';
		$strategySan = $this->frogman->sanitizeForChat($strategy);
		$timeoutDisp = (string)(int)($params['timeout'] ?? 15);

		if (!$confirm) {
			$frogman = $this->frogman;
			$memberSummary = empty($members) ? '_no initial members_' : count($members) . ' member(s): ' . implode(', ', array_map(function($m) use ($frogman) {
				$ext = self::extractMemberExt($m);
				return $ext !== '' ? '`' . $frogman->sanitizeForChat($ext) . '`' : '`?`';
			}, $members));
			return ['dry_run' => true, 'message' => "Would add queue `{$accountSan}` `{$nameSan}` with strategy `{$strategySan}`, per-member timeout {$timeoutDisp}s.\n• {$memberSummary}\n\nReply yes to confirm.", 'queue' => ['account' => $account, 'name' => $name, 'strategy' => $strategy, 'members' => $members]];
		}

		self::writeQueue($this->freepbx, [
			'account' => $account,
			'name' => $name,
			'password' => (string)($params['password'] ?? ''),
			'prefix' => (string)($params['prefix'] ?? ''),
			'fail_destination' => (string)($params['fail_destination'] ?? ''),
			'members' => $members,
			'maxwait' => $params['maxwait'] ?? 0,
			'alertinfo' => (string)($params['alertinfo'] ?? ''),
			'strategy' => $strategy,
			'timeout' => $params['timeout'] ?? 15,
			'retry' => $params['retry'] ?? 5,
			'wrapuptime' => $params['wrapuptime'] ?? 0,
			'weight' => $params['weight'] ?? 0,
			'joinempty' => $params['joinempty'] ?? 'yes',
			'leavewhenempty' => $params['leavewhenempty'] ?? 'no',
			'announce_position' => $params['announce_position'] ?? 'no',
			'announce_holdtime' => $params['announce_holdtime'] ?? 'no',
			'recording' => $params['recording'] ?? 'dontcare',
			'mohclass' => $params['mohclass'] ?? 'default',
		]);

		return ['dry_run' => false, 'message' => "✅ Queue `{$accountSan}` `{$nameSan}` added (" . count($members) . " member(s), strategy `{$strategySan}`).", 'account' => $account, 'name' => $name, 'needs_reload' => true];
	}
}
