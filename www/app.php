<?php
require('/srv/control/phlo/phlo.php');
phlo_app (
	id: 'Dashboard',
	host: 'dashboard.qdev.nl',
	auth: false,
	build: true,
	debug: true,
	dashboard: 'phlo',
	app: '/srv/control/dashboard/',
	websocket: 3001,
	files: '/srv/control/files/',
	images: '/srv/control/files/images/',
	thumbs: '/srv/control/files/thumbs/',
);
