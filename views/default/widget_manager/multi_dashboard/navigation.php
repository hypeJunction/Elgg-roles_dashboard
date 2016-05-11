<?php

elgg_load_js('lightbox');
elgg_load_css('lightbox');

elgg_require_js('widget_manager/multi_dashboard');

$max_tab_title_length = 10;

$md_entities = elgg_extract('entities', $vars);
$selected_guid = (int) get_input('multi_dashboard_guid', 0);

$tabs = [];

// add the default tab
$default_tab = [
	'text' => elgg_echo('dashboard'),
	'href' => 'dashboard',
	'title' => elgg_echo('dashboard'),
	'class' => 'widget-manager-multi-dashboard-tab-widgets',
];

if (empty($selected_guid)) {
	$default_tab['selected'] = true;
}

$tabs[0] = $default_tab;

if (!empty($md_entities)) {
	foreach ($md_entities as $key => $entity) {

		$selected = false;
		if ($entity->getGUID() == $selected_guid) {
			$selected = true;
		}
		$tab_title = elgg_strip_tags($entity->title);

		if (strlen($tab_title) > $max_tab_title_length) {
			$tab_title = substr($tab_title, 0, $max_tab_title_length);
		}

		$order = $entity->order ? $entity->order : $entity->time_created;

		$edit_icon = '';
		if ($entity->canEdit()) {
			$edit_icon = elgg_view_icon('settings-alt', [
				'class' => 'widget-manager-multi-dashboard-tabs-edit hidden',
				'data-multi-dashboard-edit-link' => elgg_normalize_url("ajax/view/widget_manager/forms/multi_dashboard?guid={$entity->getGUID()}"),
			]);
		}

		$tabs[$order] = [
			'text' => $tab_title . $edit_icon,
			'href' => $entity->getURL(),
			'title' => $entity->title,
			'selected' => $selected,
			'rel' => $entity->getGUID(),
			'id' => $entity->getGUID(),
			'class' => 'widget-manager-multi-dashboard-tab widget-manager-multi-dashboard-tab-' . $entity->getDashboardType(),
		];
	}
}

ksort($tabs);

// add tab tab
if (is_array($md_entities) && count($md_entities) < MULTI_DASHBOARD_MAX_TABS) {
	$tabs[] = [
		'text' => elgg_view_icon('round-plus'),
		'href' => '#',
		'title' => elgg_echo('widget_manager:multi_dashboard:add'),
		'link_class' => 'elgg-lightbox',
		'data-colorbox-opts' => json_encode([
			'href' => elgg_normalize_url('ajax/view/widget_manager/forms/multi_dashboard'),
			'innerWidth' => 400,
		]),
	];
}

elgg_unregister_menu_item('title', 'widgets:add');

echo elgg_view('navigation/tabs', ['id' => 'widget-manager-multi-dashboard-tabs', 'tabs' => $tabs]);

// We need to push "role::<role_name>" context so that widgets are retrieved correctly
// and can be configured via admin interface
// This is the only place we can do it, before MultiDashboard::getWidgets() is called
// in page/layouts/widgets (in widget_manager)

$dashboard = get_entity($selected_guid);

if ($dashboard instanceof MultiDashboard && $dashboard->roles_context) {
	$context = $dashboard->roles_context;
	elgg_push_context($context);

	// update fixed widgets if needed
	$page_owner = elgg_get_page_owner_entity();
	if ($page_owner instanceof ElggUser && $page_owner->canEdit()) {
		// only check things if you are viewing a profile or dashboard page
		$fixed_ts = elgg_get_plugin_setting($context . "_fixed_ts", "widget_manager");
		if (empty($fixed_ts)) {
			$fixed_ts = time();
			elgg_set_plugin_setting($context . "_fixed_ts", $fixed_ts, "widget_manager");
		}

		// get the ts of the profile/dashboard you are viewing
		$user_fixed_ts = elgg_get_plugin_user_setting($context . "_fixed_ts", $page_owner->guid, "widget_manager");
		if ($user_fixed_ts < $fixed_ts) {
			widget_manager_update_fixed_widgets($context, $page_owner->guid);
		}
	}
}
