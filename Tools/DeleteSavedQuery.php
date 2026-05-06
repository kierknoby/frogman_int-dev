<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class DeleteSavedQuery extends AbstractTool {

	public function name() {
		return 'fm_delete_saved_query';
	}

	public function description() {
		return 'Delete a saved query by name. Params: name (required). Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['name'])) {
			return 'Parameter "name" is required';
		}
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	public function permissionLevel() { return self::PERM_WRITE; }
	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$name = $params['name'];
		$db = $this->freepbx->Database;

		$sth = $db->prepare("SELECT id FROM oc_saved_queries WHERE name = ?");
		$sth->execute([$name]);
		$existing = $sth->fetch(\PDO::FETCH_ASSOC);

		if (empty($existing)) {
			throw new \Exception("Saved query '{$name}' not found");
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would delete saved query `{$name}`. Reply yes to confirm.",
			];
		}

		$sth = $db->prepare("DELETE FROM oc_saved_queries WHERE name = ?");
		$sth->execute([$name]);

		return [
			'message' => "Saved query '{$name}' deleted",
			'name' => $name,
		];
	}
}
