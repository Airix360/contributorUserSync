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

	function makeButton(label, extraClass, referenceButton) {
		var b = document.createElement('button');
		b.type = 'button';
		// Copy the class list from a sibling core button so the styling always
		// matches the current OJS theme (the classes are Tailwind utilities).
		b.className = (referenceButton ? referenceButton.className : 'pkpButton') + ' cusSyncBtn ' + (extraClass || '');
		b.style.marginLeft = '0.25rem';
		b.textContent = label;
		return b;
	}

	function renderBadges(panel, statuses) {
		Array.prototype.forEach.call(panel.querySelectorAll('.listPanel__item'), function (item) {
			var authorId = item.getAttribute('data-cus-author-id');
			var info = authorId && statuses ? statuses[authorId] : null;
			var badge = item.querySelector('.cusStatusBadge');
			if (!info) {
				if (badge) {
					badge.remove();
				}
				return;
			}
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'cusStatusBadge';
				badge.style.cssText = 'display:inline-block;margin-left:0.5rem;padding:0.125rem 0.5rem;' +
					'border:1px solid #bbb;border-radius:1rem;font-size:0.75rem;color:#555;vertical-align:middle;';
				var anchor = item.querySelector('.listPanel__itemTitle') || item.firstElementChild;
				(anchor.parentElement || item).insertBefore(badge, anchor.nextSibling);
			}
			// Only touch the DOM on real changes, so the MutationObserver settles.
			if (badge.textContent !== info.label) {
				badge.textContent = info.label;
			}
			if (badge.title !== (info.at || '')) {
				badge.title = info.at || '';
			}
		});
	}

	function loadStatuses(panel, subId) {
		post({verb: 'statuses', submissionId: subId}).then(function (res) {
			renderBadges(panel, res.content || {});
		}).catch(function () { /* badges are best-effort */ });
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
				var allBtn = makeButton(cfg.i18n.syncAll, 'cusSyncAllBtn', addBtn);
				addBtn.parentElement.insertBefore(allBtn, addBtn);
				allBtn.addEventListener('click', function () {
					allBtn.disabled = true;
					post({verb: 'syncAll', submissionId: subId}).then(function (res) {
						showMessage(panel, res.content || cfg.i18n.error, res.status);
						loadStatuses(panel, subId);
					}).catch(function () {
						showMessage(panel, cfg.i18n.error, false);
					}).finally(function () {
						allBtn.disabled = false;
						cache = null;
					});
				});
			}
			// Per-row "Sync" / "Invite".
			var decoratedNew = false;
			Array.prototype.forEach.call(items, function (item, idx) {
				var actions = item.querySelector('.listPanel__itemActions');
				if (!actions || actions.querySelector('.cusSyncBtn')) {
					return;
				}
				decoratedNew = true;
				var titleEl = item.querySelector('.listPanel__itemTitle') || item;
				var title = titleEl.textContent.replace(/\s+/g, ' ').trim();
				var contributor = data.list.find(function (c) {
					return namesOf(c).some(function (n) { return title === n || title.indexOf(n) === 0; });
				}) || data.list[idx];
				if (!contributor) {
					return;
				}
				item.setAttribute('data-cus-author-id', contributor.id);
				var refBtn = actions.querySelector('button');
				var syncBtn = makeButton(cfg.i18n.sync, '', refBtn);
				var inviteBtn = makeButton(cfg.i18n.invite, '', refBtn);
				var run = function (force) {
					return function () {
						syncBtn.disabled = inviteBtn.disabled = true;
						var params = {verb: 'syncOne', authorId: contributor.id};
						if (force) {
							params.force = force;
						}
						post(params).then(function (res) {
							showMessage(item, res.content || cfg.i18n.error, res.status);
							loadStatuses(panel, subId);
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
			// Fetch statuses only when rows were newly decorated; action handlers
			// refresh badges themselves. Prevents an observer/poll feedback loop.
			if (decoratedNew) {
				loadStatuses(panel, subId);
			}
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
