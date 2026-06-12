/**
 * js/contributorSync.js
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Adds per-contributor "Sync" / "Invite" buttons and a panel-level
 * "Sync Contributors" button to the workflow contributors list. The list is a
 * core Vue component, so the buttons are injected by observing the DOM and the
 * contributor ids are resolved through the submissions REST API.
 */
(function () {
	'use strict';
	var cfg = window.ContributorUserSyncConfig;
	if (!cfg) {
		return;
	}
	var cache = null; // { subId, list } from the REST API

	function getSubmissionId() {
		var params = new URLSearchParams(window.location.search);
		var fromQuery = params.get('workflowSubmissionId');
		if (fromQuery) {
			return fromQuery;
		}
		var m = window.location.pathname.match(/workflow\/(?:access|index)\/(\d+)/);
		return m ? m[1] : null;
	}

	function api(url) {
		return fetch(url, {
			credentials: 'include',
			headers: {'X-Csrf-Token': cfg.csrfToken}
		}).then(function (r) { return r.json(); });
	}

	function getContributors(subId) {
		if (cache && cache.subId === subId) {
			return Promise.resolve(cache);
		}
		return api(cfg.apiBase + '/' + subId).then(function (sub) {
			return api(cfg.apiBase + '/' + subId + '/publications/' + sub.currentPublicationId + '/contributors');
		}).then(function (res) {
			var list = res.items || res || [];
			cache = {subId: subId, list: list};
			return cache;
		});
	}

	function namesOf(contributor) {
		var fn = contributor.fullName;
		var values = typeof fn === 'string' ? [fn] : Object.values(fn || {});
		return values.map(function (s) {
			return String(s || '').replace(/\s+/g, ' ').trim();
		}).filter(Boolean);
	}

	function post(params) {
		var body = new URLSearchParams(params);
		body.set('csrfToken', cfg.csrfToken);
		return fetch(cfg.endpoint, {
			method: 'POST',
			credentials: 'include',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: body
		}).then(function (r) { return r.json(); });
	}

	function showMessage(container, text, ok) {
		var el = container.querySelector(':scope > .cusSyncMsg');
		if (!el) {
			el = document.createElement('div');
			el.className = 'cusSyncMsg';
			el.style.cssText = 'white-space:pre-line;font-size:0.875rem;padding:0.25rem 0;clear:both;';
			container.appendChild(el);
		}
		el.style.color = ok ? '#006798' : '#d00a6c';
		el.textContent = text;
	}

	function makeButton(label, extraClass) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = 'pkpButton cusSyncBtn ' + (extraClass || '');
		b.style.marginLeft = '0.25rem';
		b.textContent = label;
		return b;
	}

	function decorate() {
		var subId = getSubmissionId();
		if (!subId) {
			return;
		}
		var addBtn = Array.prototype.find.call(
			document.querySelectorAll('button'),
			function (b) { return /Add Contributor/i.test(b.textContent); }
		);
		if (!addBtn) {
			return;
		}
		var panel = addBtn.closest('.listPanel') || addBtn.closest('div[class*="listPanel"]') || addBtn.parentElement.parentElement;
		var items = panel ? panel.querySelectorAll('.listPanel__item') : [];
		if (!items.length) {
			return;
		}
		// Invalidate the cache when the visible list size changes (add/delete).
		if (cache && cache.subId === subId && cache.list.length !== items.length) {
			cache = null;
		}
		getContributors(subId).then(function (data) {
			// Panel-level "Sync Contributors".
			if (!panel.querySelector('.cusSyncAllBtn')) {
				var allBtn = makeButton(cfg.i18n.syncAll, 'cusSyncAllBtn');
				addBtn.parentElement.insertBefore(allBtn, addBtn);
				allBtn.addEventListener('click', function () {
					allBtn.disabled = true;
					post({verb: 'syncAll', submissionId: subId}).then(function (res) {
						showMessage(panel, res.content || cfg.i18n.error, res.status);
					}).catch(function () {
						showMessage(panel, cfg.i18n.error, false);
					}).finally(function () {
						allBtn.disabled = false;
						cache = null;
					});
				});
			}
			// Per-row "Sync" / "Invite".
			Array.prototype.forEach.call(items, function (item, idx) {
				var actions = item.querySelector('.listPanel__itemActions');
				if (!actions || actions.querySelector('.cusSyncBtn')) {
					return;
				}
				var titleEl = item.querySelector('.listPanel__itemTitle') || item;
				var title = titleEl.textContent.replace(/\s+/g, ' ').trim();
				var contributor = data.list.find(function (c) {
					return namesOf(c).some(function (n) { return title === n || title.indexOf(n) === 0; });
				}) || data.list[idx];
				if (!contributor) {
					return;
				}
				var syncBtn = makeButton(cfg.i18n.sync);
				var inviteBtn = makeButton(cfg.i18n.invite);
				var run = function (force) {
					return function () {
						syncBtn.disabled = inviteBtn.disabled = true;
						var params = {verb: 'syncOne', authorId: contributor.id};
						if (force) {
							params.force = force;
						}
						post(params).then(function (res) {
							showMessage(item, res.content || cfg.i18n.error, res.status);
						}).catch(function () {
							showMessage(item, cfg.i18n.error, false);
						}).finally(function () {
							syncBtn.disabled = inviteBtn.disabled = false;
						});
					};
				};
				syncBtn.addEventListener('click', run(null));
				inviteBtn.addEventListener('click', run('invite'));
				actions.appendChild(syncBtn);
				actions.appendChild(inviteBtn);
			});
		}).catch(function () { /* API unavailable; leave the panel untouched */ });
	}

	// The script is loaded in <head>; wait for the body before observing.
	function start() {
		var debounce;
		new MutationObserver(function () {
			clearTimeout(debounce);
			debounce = setTimeout(decorate, 400);
		}).observe(document.body, {childList: true, subtree: true});
		setTimeout(decorate, 800);
	}
	if (document.body) {
		start();
	} else {
		document.addEventListener('DOMContentLoaded', start);
	}
})();
