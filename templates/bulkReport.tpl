{**
 * templates/bulkReport.tpl
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Rendered fragment for a bulk-sync preview or run.
 *}
<div class="cusReport">
	<h4>
		{if $applied}{translate key="plugins.generic.contributorUserSync.bulk.appliedHeading"}
		{else}{translate key="plugins.generic.contributorUserSync.bulk.previewHeading"}{/if}
	</h4>
	<ul>
		<li>{translate key="plugins.generic.contributorUserSync.report.scanned"}: <strong>{$summary.scanned}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.matched"}: <strong>{$summary.matched}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.created"}: <strong>{$summary.created}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.invited"}: <strong>{$summary.invited}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.orcidSynced"}: <strong>{$summary.orcidSynced}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.skippedNoEmail"}: <strong>{$summary.skippedNoEmail}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.skippedNoUser"}: <strong>{$summary.skippedNoUser}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.skippedNoVerifiedOrcid"}: <strong>{$summary.skippedNoVerifiedOrcid}</strong></li>
		<li>{translate key="plugins.generic.contributorUserSync.report.errors"}: <strong>{$summary.errors}</strong></li>
	</ul>

	{if $rows}
		<a href="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="bulkExport"}" class="pkp_button">
			{translate key="plugins.generic.contributorUserSync.bulk.export"}
		</a>
		<table class="pkpTable" style="margin-top:1rem;">
			<thead>
				<tr>
					<th>{translate key="plugins.generic.contributorUserSync.report.submissionId"}</th>
					<th>{translate key="user.name"}</th>
					<th>{translate key="user.email"}</th>
					<th>{translate key="plugins.generic.contributorUserSync.report.outcome"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$rows item=row}
					<tr>
						<td>{$row.submissionId}</td>
						<td>{$row.name|escape}</td>
						<td>{$row.email|escape}</td>
						<td>
							{foreach from=$row.outcomes item=outcome name=oc}
								{translate key="plugins.generic.contributorUserSync.outcome."|cat:$outcome}{if !$smarty.foreach.oc.last}; {/if}
							{/foreach}
							{if $row.detail} <em>({$row.detail|escape})</em>{/if}
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>
