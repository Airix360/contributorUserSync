{**
 * templates/approval.tpl
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Confirm / decline result page.
 *}
{include file="frontend/components/header.tpl" pageTitle=$pageTitle}

<div class="page page_message">
	<h1>{$pageTitle|escape}</h1>
	<p>{$cusMessage|escape}</p>
</div>

{include file="frontend/components/footer.tpl"}
