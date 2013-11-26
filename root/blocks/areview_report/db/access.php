<?php

$capabilities = array(

		'block/areview_report:myaddinstance' => array(
				'captype' => 'write',
				'contextlevel' => CONTEXT_COURSE,
				'archetypes' => array(
						'user' => CAP_ALLOW
				),

				'clonepermissionsfrom' => 'moodle/my:manageblocks'
		),

		'block/areview_report:addinstance' => array(
				'riskbitmask' => RISK_SPAM | RISK_XSS,

				'captype' => 'write',
				'contextlevel' => CONTEXT_BLOCK,
				'archetypes' => array(
						'editingteacher' => CAP_ALLOW,
						'manager' => CAP_ALLOW
				),

				'clonepermissionsfrom' => 'moodle/site:manageblocks'
		),
);

