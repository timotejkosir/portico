{**
 * plugins/importexport/portico/index.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University Library
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * List of operations this plugin can perform
 *
 *}
{strip}
{assign var="pageTitle" value="plugins.importexport.portico.displayName"}
{include file="common/header.tpl"}
{/strip}

<p>{translate key="plugins.importexport.portico.description"}</p>
<ul class="plain">
	<li><a href="{plugin_url path="settings"}">{translate key="plugins.importexport.portico.ftpConfig"}</a></li>
	<li><a href="{plugin_url path="issues"}">{translate key="plugins.importexport.portico.export.issues"}</a></li>
</ul>
<p>{translate key="plugins.importexport.portico.participate"}</p>
<br />

{include file="common/footer.tpl"}
