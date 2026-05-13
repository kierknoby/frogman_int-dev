<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class UpdateSipNat extends AbstractTool {
	public function name() { return 'fm_update_sip_nat'; }
	public function description() { return 'Update SIP NAT external IP or local network. Params: external_ip (optional), local_network (optional). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['external_ip']) && empty($params['local_network'])) {
			return 'At least one of external_ip or local_network is required';
		}
		// external_ip accepts an IP (v4 or v6) OR an FQDN — FreePBX's externip
		// setting accepts both, so we have to match that to avoid breaking valid configs.
		if (!empty($params['external_ip'])) {
			$ip = $params['external_ip'];
			$isIp   = (bool) filter_var($ip, FILTER_VALIDATE_IP);
			$isHost = (bool) filter_var($ip, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
			if (!$isIp && !$isHost) {
				return 'Parameter "external_ip" must be a valid IP address or hostname';
			}
		}
		// local_network historically accepts comma/semicolon/whitespace-separated entries
		// of either bare IPs or CIDRs (v4 or v6). Validate each entry; reject the whole
		// input if any entry is malformed.
		if (!empty($params['local_network'])) {
			$entries = preg_split('/[\s,;]+/', trim((string)$params['local_network']), -1, PREG_SPLIT_NO_EMPTY);
			if (empty($entries)) return 'Parameter "local_network" is empty after parsing';
			foreach ($entries as $entry) {
				if (!self::isIpOrCidr($entry)) {
					return "Parameter \"local_network\" entry \"{$entry}\" is not a valid IP or CIDR";
				}
			}
		}
		return true;
	}

	private static function isIpOrCidr($v) {
		if (filter_var($v, FILTER_VALIDATE_IP)) return true;
		if (!preg_match('#^([^/]+)/(\d+)$#', $v, $m)) return false;
		if (!filter_var($m[1], FILTER_VALIDATE_IP)) return false;
		$prefix = (int) $m[2];
		$max = strpos($m[1], ':') !== false ? 128 : 32;
		return $prefix >= 0 && $prefix <= $max;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$changes = [];
		if (!empty($params['external_ip'])) $changes[] = "External IP → {$params['external_ip']}";
		if (!empty($params['local_network'])) $changes[] = "Local network → {$params['local_network']}";
		if (!$confirm) {
			return ['dry_run' => true, 'message' => "Would update SIP NAT: " . implode(', ', $changes) . ". Reply yes to confirm."];
		}
		if (!empty($params['external_ip'])) {
			$this->freepbx->Sipsettings->setConfig('externip', $params['external_ip']);
		}
		if (!empty($params['local_network'])) {
			$this->freepbx->Sipsettings->setConfig('localnetworks', $params['local_network']);
		}
		return ['dry_run' => false, 'message' => "SIP NAT settings updated: " . implode(', ', $changes), 'needs_reload' => true];
	}
}
