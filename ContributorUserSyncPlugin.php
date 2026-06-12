<?php

/**
 * @file ContributorUserSyncPlugin.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContributorUserSyncPlugin
 *
 * @brief Links OJS submission contributors to user accounts and reuses verified
 *   ORCID iDs from existing user profiles. See README.md for the full feature
 *   set and safety model.
 */

namespace APP\plugins\generic\contributorUserSync;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\plugins\generic\contributorUserSync\classes\BulkSyncTool;
use APP\plugins\generic\contributorUserSync\classes\ContributorSyncService;
use APP\plugins\generic\contributorUserSync\classes\SyncReport;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;

class ContributorUserSyncPlugin extends GenericPlugin
{
    /**
     * When true, the on-save hooks are bypassed. The bulk tool sets this while
     * persisting so its own Repo::author()->edit() calls do not recurse.
     */
    public static bool $suspendHook = false;

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled($mainContextId)) {
            // Edits: mutate the about-to-be-saved author in place.
            Hook::add('Author::edit', [$this, 'onAuthorEdit']);
            // Adds: mutate before insert so changes persist with the new row.
            Hook::add('Author::add::before', [$this, 'onAuthorAddBefore']);
        }
        return true;
    }

    // ------------------------------------------------------------------ Hooks

    /**
     * @param array $args [$newAuthor, $author, $params]
     */
    public function onAuthorEdit(string $hookName, array $args): int
    {
        if (self::$suspendHook) {
            return Hook::CONTINUE;
        }
        $this->syncSingle($args[0]);
        return Hook::CONTINUE;
    }

    /**
     * @param array $args [$author]
     */
    public function onAuthorAddBefore(string $hookName, array $args): int
    {
        if (self::$suspendHook) {
            return Hook::CONTINUE;
        }
        $this->syncSingle($args[0]);
        return Hook::CONTINUE;
    }

    /**
     * Run the sync service against a single author, mutating it in place. The
     * surrounding OJS save (insert/update) persists the changes.
     */
    private function syncSingle($author): void
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }
        $service = new ContributorSyncService($this->resolveSettings($context->getId()), $context);
        if (!$service->isEnabled()) {
            return;
        }
        $report = new SyncReport();
        $service->processAuthor($author, (int) $author->getData('submissionId'), $report, true);
    }

    // --------------------------------------------------------------- Settings

    /**
     * Read all plugin settings for a context into a single resolved array, with
     * the safe defaults applied. Shared by the hook path and the bulk tool.
     */
    public function resolveSettings(int $contextId): array
    {
        $get = fn (string $key, $default) => $this->getSetting($contextId, $key) ?? $default;
        return [
            'syncEnabled' => (bool) $get('syncEnabled', false),
            'syncMode' => $get('syncMode', 'link'),
            'orcidAutoSync' => (bool) $get('orcidAutoSync', true),
            'orcidOverwrite' => (bool) $get('orcidOverwrite', false),
            'orcidAllowManual' => (bool) $get('orcidAllowManual', false),
            'orcidNoVerifiedAction' => $get('orcidNoVerifiedAction', 'nothing'),
            'updateContributorFromUser' => (bool) $get('updateContributorFromUser', false),
            'updateUserFromContributor' => (bool) $get('updateUserFromContributor', false),
            'createUserRole' => $get('createUserRole', 'author'),
            'eligibleRoles' => (array) ($this->getSetting($contextId, 'eligibleRoles') ?? []),
        ];
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        $url = $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($url, $this->getDisplayName()), __('manager.plugins.settings')));
        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $verb = $request->getUserVar('verb');
        $context = $request->getContext();

        switch ($verb) {
            case 'settings':
                return $this->manageSettings($args, $request);
            case 'bulkPreview':
                return $this->manageBulk($request, false);
            case 'bulkRun':
                return $this->manageBulk($request, true);
            case 'bulkExport':
                $this->downloadLastReport($request);
                // downloadLastReport emits the file and exits; this is unreachable.
                return new JSONMessage(true);
            default:
                return parent::manage($args, $request);
        }
    }

    private function manageSettings($args, $request): JSONMessage
    {
        $form = new SettingsForm($this, $request->getContext()->getId());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->readInputData();
        if (!$form->validate()) {
            return new JSONMessage(true, $form->fetch($request));
        }
        $form->execute();
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId());
        return new JSONMessage(true);
    }

    /**
     * Run a bulk preview or apply, persist the resulting report, and return the
     * rendered report fragment.
     */
    private function manageBulk($request, bool $apply): JSONMessage
    {
        $context = $request->getContext();
        $settings = $this->resolveSettings($context->getId());
        $service = new ContributorSyncService($settings, $context);
        $tool = new BulkSyncTool($service, $context);
        $report = $tool->run($apply, (int) ($request->getUserVar('limit') ?: 0));

        // Stash the rows so they can be exported as CSV without re-running.
        $this->updateSetting($context->getId(), 'lastReportRows', $report->rows, 'object');
        $this->updateSetting($context->getId(), 'lastReportApplied', $apply, 'bool');

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            'applied' => $apply,
            'summary' => $report->summary(),
            'rows' => $report->rows,
        ]);
        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('bulkReport.tpl')));
    }

    /**
     * Stream the most recent bulk report as a CSV download.
     */
    private function downloadLastReport($request): void
    {
        $context = $request->getContext();
        $rows = (array) ($this->getSetting($context->getId(), 'lastReportRows') ?? []);
        $report = new SyncReport();
        $report->rows = $rows;
        $csv = $report->toCsv();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contributor-user-sync-report.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /**
     * Author user groups in this context, for the eligible-roles picker.
     *
     * @return array<int,string> userGroupId => localized name
     */
    public function getContributorRoleOptions(int $contextId): array
    {
        $groups = Repo::userGroup()->getByRoleIds([Role::ROLE_ID_AUTHOR], $contextId);
        $options = [];
        foreach ($groups as $group) {
            $options[(int) $group->getId()] = $group->getLocalizedName();
        }
        return $options;
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.contributorUserSync.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.contributorUserSync.description');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\contributorUserSync\ContributorUserSyncPlugin', '\ContributorUserSyncPlugin');
}
