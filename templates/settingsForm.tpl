{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Contributor User Sync — plugin settings.
 *}
<script>
	$(function() {ldelim}
		$('#contributorUserSyncSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		// Bulk sync: POST the preview/run verbs and drop the rendered report in place.
		function cusBulk(verb) {ldelim}
			var $out = $('#cusBulkReport');
			$out.html('{translate key="plugins.generic.contributorUserSync.bulk.running"}');
			$.post(
				'{url|escape:"javascript" router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName escape=false}',
				{ldelim} verb: verb, csrfToken: {csrf type="json"} {rdelim},
				function(data) {ldelim} $out.html(data.content); {rdelim}
			);
		{rdelim}
		$('#cusBulkPreview').click(function(e) {ldelim} e.preventDefault(); cusBulk('bulkPreview'); {rdelim});
		$('#cusBulkRun').click(function(e) {ldelim}
			e.preventDefault();
			if (confirm('{translate key="plugins.generic.contributorUserSync.bulk.confirmRun"}')) {ldelim} cusBulk('bulkRun'); {rdelim}
		{rdelim});
	{rdelim});
</script>

<form class="pkp_form" id="contributorUserSyncSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	<div id="description">{translate key="plugins.generic.contributorUserSync.description"}</div>

	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="contributorUserSyncSettingsFormNotification"}

	{* ---------------------------------------------------------- General *}
	{fbvFormArea id="cusGeneral" title="plugins.generic.contributorUserSync.settings.general"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="syncEnabled" value="1" checked=$syncEnabled label="plugins.generic.contributorUserSync.settings.syncEnabled"}
		{/fbvFormSection}
	{/fbvFormArea}

	{* ----------------------------------------------------- Sync behaviour *}
	{fbvFormArea id="cusBehaviour" title="plugins.generic.contributorUserSync.settings.behaviour"}
		{fbvFormSection list=true description="plugins.generic.contributorUserSync.settings.behaviour.desc"}
			{fbvElement type="radio" id="syncMode-nothing" name="syncMode" value="nothing" checked=$syncMode|compare:"nothing" label="plugins.generic.contributorUserSync.settings.mode.nothing"}
			{fbvElement type="radio" id="syncMode-link" name="syncMode" value="link" checked=$syncMode|compare:"link" label="plugins.generic.contributorUserSync.settings.mode.link"}
			{fbvElement type="radio" id="syncMode-invite" name="syncMode" value="invite" checked=$syncMode|compare:"invite" label="plugins.generic.contributorUserSync.settings.mode.invite"}
			{fbvElement type="radio" id="syncMode-create" name="syncMode" value="create" checked=$syncMode|compare:"create" label="plugins.generic.contributorUserSync.settings.mode.create"}
		{/fbvFormSection}
	{/fbvFormArea}

	{* -------------------------------------------------- ORCID auto-sync *}
	{fbvFormArea id="cusOrcid" title="plugins.generic.contributorUserSync.settings.orcid"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="orcidAutoSync" value="1" checked=$orcidAutoSync label="plugins.generic.contributorUserSync.settings.orcidAutoSync"}
			{fbvElement type="checkbox" id="orcidOverwrite" value="1" checked=$orcidOverwrite label="plugins.generic.contributorUserSync.settings.orcidOverwrite"}
			{fbvElement type="checkbox" id="orcidAllowManual" value="1" checked=$orcidAllowManual label="plugins.generic.contributorUserSync.settings.orcidAllowManual"}
		{/fbvFormSection}
		{fbvFormSection list=true description="plugins.generic.contributorUserSync.settings.orcidNoVerified.desc"}
			{fbvElement type="radio" id="orcidNoVerifiedAction-nothing" name="orcidNoVerifiedAction" value="nothing" checked=$orcidNoVerifiedAction|compare:"nothing" label="plugins.generic.contributorUserSync.settings.orcidNoVerified.nothing"}
			{fbvElement type="radio" id="orcidNoVerifiedAction-warn" name="orcidNoVerifiedAction" value="warn" checked=$orcidNoVerifiedAction|compare:"warn" label="plugins.generic.contributorUserSync.settings.orcidNoVerified.warn"}
			{fbvElement type="radio" id="orcidNoVerifiedAction-request" name="orcidNoVerifiedAction" value="request" checked=$orcidNoVerifiedAction|compare:"request" label="plugins.generic.contributorUserSync.settings.orcidNoVerified.request"}
		{/fbvFormSection}
	{/fbvFormArea}

	{* ------------------------------------------------ Contributor roles *}
	{fbvFormArea id="cusRoles" title="plugins.generic.contributorUserSync.settings.roles"}
		{fbvFormSection list=true description="plugins.generic.contributorUserSync.settings.roles.desc"}
			{foreach from=$contributorRoleOptions key=roleId item=roleName}
				{assign var=fieldId value="eligibleRoles-"|cat:$roleId}
				{fbvElement type="checkbox" id=$fieldId name="eligibleRoles[]" value=$roleId checked=in_array($roleId, $eligibleRoles) label=$roleName translate=false}
			{/foreach}
		{/fbvFormSection}
	{/fbvFormArea}

	{* --------------------------------------------------- Existing users *}
	{fbvFormArea id="cusExisting" title="plugins.generic.contributorUserSync.settings.existing"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="updateContributorFromUser" value="1" checked=$updateContributorFromUser label="plugins.generic.contributorUserSync.settings.updateContributorFromUser"}
			{fbvElement type="checkbox" id="updateUserFromContributor" value="1" checked=$updateUserFromContributor label="plugins.generic.contributorUserSync.settings.updateUserFromContributor"}
		{/fbvFormSection}
	{/fbvFormArea}

	{* -------------------------------------------------------- New users *}
	{fbvFormArea id="cusNew" title="plugins.generic.contributorUserSync.settings.new"}
		{fbvFormSection list=true description="plugins.generic.contributorUserSync.settings.new.desc"}
			{fbvElement type="checkbox" id="createUserAllowReviewer" value="1" checked=$createUserAllowReviewer label="plugins.generic.contributorUserSync.settings.createUserAllowReviewer"}
		{/fbvFormSection}
	{/fbvFormArea}

	{* ------------------------------------------ Contributor notifications *}
	{fbvFormArea id="cusNotify" title="plugins.generic.contributorUserSync.settings.notify"}
		{fbvFormSection list=true description="plugins.generic.contributorUserSync.settings.notify.desc"}
			{fbvElement type="checkbox" id="notifyAddedContributors" value="1" checked=$notifyAddedContributors label="plugins.generic.contributorUserSync.settings.notifyAddedContributors"}
			{fbvElement type="checkbox" id="requireContributorCount" value="1" checked=$requireContributorCount label="plugins.generic.contributorUserSync.settings.requireContributorCount"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>

{* ---------------------------------------------------------- Bulk sync *}
<div id="cusBulkArea" class="pkp_form">
	<h3>{translate key="plugins.generic.contributorUserSync.settings.bulk"}</h3>
	<p>{translate key="plugins.generic.contributorUserSync.settings.bulk.desc"}</p>
	<button id="cusBulkPreview" class="pkp_button">{translate key="plugins.generic.contributorUserSync.bulk.preview"}</button>
	<button id="cusBulkRun" class="pkp_button pkp_button_primary">{translate key="plugins.generic.contributorUserSync.bulk.run"}</button>
	<div id="cusBulkReport" style="margin-top:1rem;"></div>
</div>
