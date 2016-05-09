<?php

/**
 * Roles Dashboard
 *
 * @author Ismayil Khayredinov <ismayil.khayredinov@gmail.com>
 * @copyright Copyright (c) 2016, Ismayil Khayredinov
 * @copyright Copyright (c) 2016, IvyTies.com
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2
 */
define('ROLES_DASHBOARD_NUM_COLUMNS', 3);

elgg_register_event_handler('init', 'system', 'roles_dashboard_init');

/**
 * Init plugin
 * @return void
 */
function roles_dashboard_init() {

	// Listen to 'has_role' relationship changes
	elgg_register_event_handler('create', 'relationship', 'roles_dashboard_create_relationship');
	elgg_register_event_handler('delete', 'relationship', 'roles_dashboard_delete_relationship');

	// Add selectable roles as widget contexts
	elgg_register_plugin_hook_handler('get_list', 'default_widgets', 'roles_dashboard_default_widget_contexts');

	// Create default widgets when multidashboard tab is created
	elgg_register_event_handler('create', 'object', 'roles_dashboard_create_default_widgets');

	// Push roles widget context before widgets for dashboard are retrieved
	elgg_extend_view('widget_manager/multi_dashboard/navigation', 'roles/dashboard/context');

	// Widget layout/dashboard permissions
	elgg_register_plugin_hook_handler('permissions_check', 'widget_layout', 'roles_dashboard_widget_layout_permissions_check');
	elgg_register_plugin_hook_handler('permissions_check', 'object', 'roles_dashboard_object_permissions_check');

	// CSS
	elgg_extend_view('css/elgg', 'roles/dashboard.css');

}

/**
 * Add multi dashboard tab when a new role is assigned
 * 
 * @param string           $event        "create"
 * @param string           $type         "relationship"
 * @param ElggRelationship $relationship Relationship object
 * @return void
 */
function roles_dashboard_create_relationship($event, $type, $relationship) {

	if ($relationship->relationship !== 'has_role') {
		return;
	}

	$user = get_entity($relationship->guid_one);
	$role = get_entity($relationship->guid_two);
	if (!$role instanceof ElggRole || !$user instanceof ElggUser) {
		return;
	}

	$ia = elgg_set_ignore_access(true);

	// check if role-specific dashboard exists
	$dashboards = elgg_get_entities_from_relationship(array(
		'types' => 'object',
		'subtypes' => MultiDashboard::SUBTYPE,
		'owner_guid' => $user->guid,
		'relationship' => 'dashboard_for',
		'relationship_guid' => $role->guid,
		'inverse_relationship' => true,
		'count' => true,
	));

	if (!$dashboards) {
		$dashboard = new ElggObject();
		$dashboard->subtype = MultiDashboard::SUBTYPE; // not using MultiDashboard instantiation due to problems with save() overwriting values
		$dashboard->owner_guid = $user->guid;
		$dashboard->container_guid = $user->guid;
		$dashboard->title = $role->getDisplayName();
		$dashboard->name = $role->name;
		$dashboard->roles_context = "role::{$role->name}";
		$dashboard->dashboard_type = 'widgets';
		$dashboard->num_columns = ROLES_DASHBOARD_NUM_COLUMNS;
		if ($dashboard->save()) {
			add_entity_relationship($dashboard->guid, 'dashboard_for', $role->guid);
		}
	}

	elgg_set_ignore_access($ia);
}

/**
 * Delete dashboard tab when role is revoked
 * 
 * @param string           $event        "delete"
 * @param string           $type         "relationship"
 * @param ElggRelationship $relationship Relationship object
 * @return void
 */
function roles_dashboard_delete_relationship($event, $type, $relationship) {
	
	if ($relationship->relationship !== 'has_role') {
		return;
	}

	$user = get_entity($relationship->guid_one);
	$role = get_entity($relationship->guid_two);
	if (!$role instanceof ElggRole || !$user instanceof ElggUser) {
		return;
	}

	$ia = elgg_set_ignore_access(true);

	$dashboards = elgg_get_entities_from_relationship(array(
		'types' => 'object',
		'subtypes' => MultiDashboard::SUBTYPE,
		'owner_guid' => $user->guid,
		'relationship' => 'dashboard_for',
		'relationship_guid' => $role->guid,
		'inverse_relationship' => true,
		'limit' => 0,
	));

	if ($dashboards) {
		foreach ($dashboards as $dashboard) {
			$dashboard->delete();
		}
	}

	elgg_set_ignore_access($ia);
}

/**
 * Add selectable roles as widget contexts for default widget management interface
 *
 * @param string $hook   "get_list"
 * @param string $type   "default_widgets"
 * @param array  $return Widget context config
 * @param array  $params Hook params
 * @return array
 */
function roles_dashboard_default_widget_contexts($hook, $type, $return, $params) {

	$roles = roles_get_all_selectable_roles();
	if (empty($roles)) {
		return;
	}

	foreach ($roles as $role) {
		// We are not configuring the event information here as we will need to apply some custom logic,
		// i.e. check relationship between a specific role and a dashboard
		// @see roles_dashboard_create_default_widgets
		$return[] = array(
			'name' => $role->getDisplayName(),
			'widget_context' => "role::{$role->name}",
			'widget_columns' => ROLES_DASHBOARD_NUM_COLUMNS,
		);
	}

	return $return;
}

/**
 * Creates default widgets on the multidashboard tab
 *
 * @param string         $event  "create"
 * @param string         $type   "object"
 * @param MultiDashboard $entity Dashboard tab
 * @return void
 */
function roles_dashboard_create_default_widgets($event, $type, $entity) {

	if ($entity->getSubtype() != MultiDashboard::SUBTYPE) {
		return;
	}

	$default_widget_info = elgg_get_config('default_widget_info');

	if (!$default_widget_info || !$entity->roles_context) {
		return;
	}

	// need to be able to access everything
	$ia = elgg_set_ignore_access(true);
	elgg_push_context('create_default_widgets');

	// pull in by widget context with widget owners as the site
	// not using elgg_get_widgets() because it sorts by columns and we don't care right now.
	$options = array(
		'type' => 'object',
		'subtype' => 'widget',
		'owner_guid' => elgg_get_site_entity()->guid,
		'private_setting_name' => 'context',
		'private_setting_value' => $entity->roles_context,
		'limit' => 0
	);

	$widgets = elgg_get_entities_from_private_settings($options);
	/* @var \ElggWidget[] $widgets */

	foreach ($widgets as $widget) {
		// change the container and owner
		$new_widget = clone $widget;
		$new_widget->container_guid = $entity->owner_guid;
		$new_widget->owner_guid = $entity->owner_guid;

		// pull in settings
		$settings = get_all_private_settings($widget->guid);

		foreach ($settings as $name => $value) {
			$new_widget->$name = $value;
		}

		if ($new_widget->save()) {
			// Add to dashboard
			add_entity_relationship($new_widget->guid, MultiDashboard::WIDGET_RELATIONSHIP, $entity->guid);
		}
	}

	widget_manager_update_fixed_widgets($entity->roles_context, $entity->owner_guid);

	elgg_set_ignore_access($ia);
	elgg_pop_context();
}

/**
 * Prevent users from editing role dashboard widget layouts
 * 
 * @param string $hook   "permissions_check"
 * @param string $type   "widget_layout"
 * @param bool   $return Permission
 * @param array  $params Hook params
 * @return bool
 */
function roles_dashboard_widget_layout_permissions_check($hook, $type, $return, $params) {

	$user = elgg_extract('user', $params);
	$context = elgg_extract('context', $params);

	if (strpos($context, 'role::') === 0) {
		return $user && $user->isAdmin();
	}
}

/**
 * Users are not allowed to edit dashboard tabs and widgets in role context
 *
 * @param string $hook   "permissions_check"
 * @param string $type   "object"
 * @param bool   $return Permission
 * @param array  $params Hook params
 * @return bool
 */
function roles_dashboard_object_permissions_check($hook, $type, $return, $params) {

	$user = elgg_extract('user', $params);
	$entity = elgg_extract('entity', $params);

	if ($entity->getSubtype() == MultiDashboard::SUBTYPE && !empty($entity->roles_context)) {
		return $user && $user->isAdmin();
	}

	$context = elgg_get_context();
	if ($entity->getSubtype() == 'widget' && strpos($context, 'role::') === 0) {
		return $user && $user->isAdmin();
	}
}