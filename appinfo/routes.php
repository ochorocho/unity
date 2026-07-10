<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Connections (per-user tracker accounts)
		['name' => 'connection#index', 'url' => '/connections', 'verb' => 'GET'],
		['name' => 'connection#create', 'url' => '/connections', 'verb' => 'POST'],
		['name' => 'connection#test', 'url' => '/connections/test', 'verb' => 'POST'],
		['name' => 'connection#update', 'url' => '/connections/{id}', 'verb' => 'PUT'],
		['name' => 'connection#destroy', 'url' => '/connections/{id}', 'verb' => 'DELETE'],

		// Issues
		['name' => 'issue#search', 'url' => '/issues', 'verb' => 'GET'],
		['name' => 'issue#show', 'url' => '/issues/{ref}', 'verb' => 'GET'],
		['name' => 'issue#update', 'url' => '/issues/{ref}', 'verb' => 'PUT'],
		['name' => 'issue#editMeta', 'url' => '/issues/{ref}/edit-meta', 'verb' => 'GET'],
		['name' => 'issue#file', 'url' => '/issues/{ref}/file', 'verb' => 'GET'],
		['name' => 'issue#comments', 'url' => '/issues/{ref}/comments', 'verb' => 'GET'],
		['name' => 'issue#addComment', 'url' => '/issues/{ref}/comments', 'verb' => 'POST'],
		['name' => 'issue#updateComment', 'url' => '/issues/{ref}/comments/{commentId}', 'verb' => 'PUT'],
		['name' => 'issue#timeRecords', 'url' => '/issues/{ref}/time', 'verb' => 'GET'],
		['name' => 'issue#logTime', 'url' => '/issues/{ref}/time', 'verb' => 'POST'],
		['name' => 'issue#updateTime', 'url' => '/issues/{ref}/time/{recordId}', 'verb' => 'PUT'],
		['name' => 'issue#deleteTime', 'url' => '/issues/{ref}/time/{recordId}', 'verb' => 'DELETE'],
		['name' => 'issue#markSeen', 'url' => '/issues/{ref}/seen', 'verb' => 'POST'],
	],
];
