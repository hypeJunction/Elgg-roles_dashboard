<?php

$en = [];

$roles = roles_get_all_selectable_roles();
if (!empty($roles)) {
	foreach ($roles as $role) {
		$en["dashboard::{$role->name}"] = $role->getDisplayName();
	}
}

return $en;