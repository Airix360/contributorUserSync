<form class="pkp_form" id="contributorUserSyncSettingsForm" method="post" action="{$pluginUrl|escape}">
	{csrf}
	<input type="hidden" name="formSubmit" value="1" />

	<fieldset class="pkp_form_fieldset">
		<legend>{translate key="plugins.generic.contributorUserSync.settings.title"}</legend>

		<div class="pkp_form_field">
			<label>
				<input type="checkbox" name="enabled" id="enabled" value="1" {if $enabled}checked{/if} />
				{translate key="plugins.generic.contributorUserSync.settings.enablePlugin"}
			</label>
		</div>

		<div class="pkp_form_field">
			<label for="availableContributorRoles">
				{translate key="plugins.generic.contributorUserSync.settings.availableContributorRoles"}
			</label>
			<div class="pkp_form_multicheckbox">
				{foreach from=$allContributorRoles item=role}
					<div class="pkp_form_checkbox_option">
						<label>
							<input type="checkbox" name="availableContributorRoles[]" value="{$role|escape}" {if in_array($role, $availableContributorRoles)}checked{/if}>
							{translate key="plugins.generic.contributorUserSync.role.$role"}
						</label>
					</div>
				{/foreach}
			</div>
		</div>

		<div class="pkp_form_field">
			<label>
				<input type="checkbox" name="notifySynced" value="1" {if $notifySynced}checked{/if} />
				{translate key="plugins.generic.contributorUserSync.settings.notifySynced"}
			</label>
		</div>

		<div class="pkp_form_field">
			<label for="defaultRole">{translate key="plugins.generic.contributorUserSync.settings.defaultRole"}</label>
			<select name="defaultRole" id="defaultRole">
				<option value="author" {if $defaultRole == "author"}selected{/if}>Author</option>
				<option value="editor" {if $defaultRole == "editor"}selected{/if}>Editor</option>
				<option value="reviewer" {if $defaultRole == "reviewer"}selected{/if}>Reviewer</option>
			</select>
		</div>

		<div class="pkp_form_field">
			<label for="defaultPasswordLength">{translate key="plugins.generic.contributorUserSync.settings.defaultPasswordLength"}</label>
			<input type="number" name="defaultPasswordLength" id="defaultPasswordLength" value="{$defaultPasswordLength|escape}" min="8" />
		</div>

		<div class="pkp_form_field">
			<label>
				<input type="checkbox" name="overwriteExisting" value="1" {if $overwriteExisting}checked{/if} />
				{translate key="plugins.generic.contributorUserSync.settings.overwriteExisting"}
			</label>
		</div>
	</fieldset>

	<fieldset class="pkp_form_fieldset">
		<legend>{translate key="plugins.generic.contributorUserSync.settings.syncNow.title"}</legend>
		<div class="pkp_form_field">
			<p>{translate key="plugins.generic.contributorUserSync.settings.syncNow.description"}</p>
			<button type="button" id="syncNowBtn" class="pkp_button pkp_button_primary">
				{translate key="plugins.generic.contributorUserSync.settings.syncNow.button"}
			</button>

                        <div id="syncProgressContainer" style="display:none; margin-top:1em;">
                                <div style="width:100%;background:#eee;border-radius:4px;overflow:hidden;height:24px;">
                                        <div id="syncProgressBar" style="width:0%;height:24px;background:#4caf50;text-align:center;color:#fff;line-height:24px;transition:width 0.3s;"></div>
                                </div>
                                <div id="syncStats" style="margin-top:0.5em;font-size:1em;">
                                        🔍 Contributors found: <span id="contributorsFound">0</span><br>
                                        ✅ New users synced: <span id="usersSynced">0</span><br>
                                        ⚠️ Skipped: <span id="usersSkipped">0</span><br>
                                </div>
                                <div id="syncSummary" style="margin-top:1em;font-weight:bold;"></div>
                                {if $syncSummary}
                                        <div class="pkp_form_info" style="margin-top:1em;">
                                                {translate key="plugins.generic.contributorUserSync.settings.syncNow.result.created"}: {$syncSummary.created}<br>
                                                {translate key="plugins.generic.contributorUserSync.settings.syncNow.result.skipped"}: {$syncSummary.skipped}
                                        </div>
                                {/if}
                        </div>
                </div>
        </fieldset>

	<button class="pkp_button pkp_button_primary" type="submit">
		{translate key="common.save"}
	</button>
</form>

<script type="text/javascript">
(function() {
	const syncBtn = document.getElementById('syncNowBtn');
	const progressContainer = document.getElementById('syncProgressContainer');
	const progressBar = document.getElementById('syncProgressBar');
	const contributorsFound = document.getElementById('contributorsFound');
	const usersSynced = document.getElementById('usersSynced');
	const usersSkipped = document.getElementById('usersSkipped');
	const syncSummary = document.getElementById('syncSummary');

	let isSyncing = false;

	function updateProgressBar(percent) {
		progressBar.style.width = percent + '%';
		progressBar.textContent = percent + '%';
	}

	function startSync() {
		if (isSyncing) return;
		isSyncing = true;
		progressContainer.style.display = 'block';
		updateProgressBar(0);
		contributorsFound.textContent = '0';
		usersSynced.textContent = '0';
		usersSkipped.textContent = '0';
		syncSummary.textContent = '';
		syncBtn.disabled = true;
		doSync(0, 0, 0, 0);
	}

	function doSync(offset, synced, skipped, total) {
		const xhr = new XMLHttpRequest();
		xhr.open('POST', '{$pluginUrl}&verb=syncContributors', true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onload = function() {
			if (xhr.status === 200) {
				try {
					const resp = JSON.parse(xhr.responseText);
					contributorsFound.textContent = resp.total;
					usersSynced.textContent = resp.synced;
					usersSkipped.textContent = resp.skipped;
					const percent = resp.total > 0 ? Math.round((resp.synced + resp.skipped) / resp.total * 100) : 0;
					updateProgressBar(percent);
					if (resp.finished) {
						isSyncing = false;
						syncBtn.disabled = false;
						syncSummary.textContent = resp.success ? '📋 Sync complete! ' + resp.synced + ' new users, ' + resp.skipped + ' skipped.' : '❌ Sync failed.';
					} else {
						doSync(resp.offset, resp.synced, resp.skipped, resp.total);
					}
				} catch (e) {
					isSyncing = false;
					syncBtn.disabled = false;
					syncSummary.textContent = '❌ Sync failed: Invalid server response.';
				}
			} else {
				isSyncing = false;
				syncBtn.disabled = false;
				syncSummary.textContent = '❌ Sync failed: Server error.';
			}
		};
		xhr.send('csrfToken=' + encodeURIComponent(document.querySelector('input[name=csrfToken]').value) + '&offset=' + offset);
	}

	syncBtn.addEventListener('click', startSync);
})();
</script>
