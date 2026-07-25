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
            // Register the plugin's author properties; without this the
            // EntityDAO strips them on save and the user link is never stored.
            Hook::add('Schema::get::author', [$this, 'onAuthorSchema']);
            // Register the declared contributor-count property on publications.
            Hook::add('Schema::get::publication', [$this, 'onPublicationSchema']);
            // Edits: mutate the about-to-be-saved author in place.
            Hook::add('Author::edit', [$this, 'onAuthorEdit']);
            // Adds: mutate before insert so changes persist with the new row.
            Hook::add('Author::add::before', [$this, 'onAuthorAddBefore']);
            // Per-contributor Sync/Invite buttons in the workflow contributors panel.
            Hook::add('TemplateManager::display', [$this, 'onTemplateDisplay']);
            // Notify newly added contributors (confirm/decline) when enabled.
            Hook::add('Author::add', [$this, 'onAuthorAdded']);
            // Public confirm/decline page.
            Hook::add('LoadHandler', [$this, 'onLoadHandler']);
            // Submission-wizard contributor-count gate.
            Hook::add('Submission::validateSubmit', [$this, 'onValidateSubmit']);
        }
        return true;
    }

    // ------------------------------------------------------------------ Hooks

    /**
     * Add the plugin's properties to the author schema so they survive saves.
     *
     * @param array $args [stdClass $schema]
     */
    public function onAuthorSchema(string $hookName, array $args): int
    {
        $schema = $args[0];
        $schema->properties->{ContributorSyncService::SETTING_USER_ID} = (object) [
            'type' => 'integer',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        $schema->properties->{ContributorSyncService::SETTING_STATUS} = (object) [
            'type' => 'string',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        $schema->properties->{ContributorSyncService::SETTING_STATUS_AT} = (object) [
            'type' => 'string',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        $schema->properties->{\APP\plugins\generic\contributorUserSync\classes\NotificationService::SETTING_APPROVAL_KEY} = (object) [
            'type' => 'string',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        $schema->properties->{ContributorSyncService::SETTING_PENDING_USER_ID} = (object) [
            'type' => 'integer',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        $schema->properties->{ContributorSyncService::SETTING_MATCH_KEY} = (object) [
            'type' => 'string',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        return Hook::CONTINUE;
    }

    /**
     * Register the declared contributor-count property on the publication schema.
     *
     * @param array $args [stdClass $schema]
     */
    public function onPublicationSchema(string $hookName, array $args): int
    {
        $args[0]->properties->contributorUserSyncExpectedCount = (object) [
            'type' => 'integer',
            'apiSummary' => false,
            'validation' => ['nullable'],
        ];
        return Hook::CONTINUE;
    }

    /**
     * Notify a contributor that they were added to a submission (confirm/decline).
     *
     * @param array $args [$author]
     */
    public function onAuthorAdded(string $hookName, array $args): int
    {
        if (self::$suspendHook) {
            return Hook::CONTINUE;
        }
        $author = $args[0];
        $publication = Repo::publication()->get((int) $author->getData('publicationId'));
        $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId')) : null;
        if (!$submission) {
            return Hook::CONTINUE;
        }
        // Resolve context from the submission, not the request (works in API/CLI).
        $context = Application::getContextDAO()->getById((int) $submission->getData('contextId'));
        if (!$context || !$this->getSetting($context->getId(), 'notifyAddedContributors')) {
            return Hook::CONTINUE;
        }
        try {
            $service = new \APP\plugins\generic\contributorUserSync\classes\NotificationService($context);
            if ($service->notifyAdded($author, $submission, true)) {
                // Persist the approval key written onto the author.
                self::$suspendHook = true;
                Repo::author()->edit($author, []);
                self::$suspendHook = false;
            }
        } catch (\Throwable $e) {
            self::$suspendHook = false;
            error_log('contributorUserSync notify failed: ' . $e->getMessage());
        }
        return Hook::CONTINUE;
    }

    /**
     * Route /contributorApproval/{confirm|decline} to the public handler.
     *
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function onLoadHandler(string $hookName, array $args)
    {
        $page = $args[0];
        $op = $args[1];
        if ($page !== 'contributorApproval' || !in_array($op, ['confirm', 'decline', 'confirmMatch', 'declineMatch'], true)) {
            return Hook::CONTINUE;
        }
        $handler = &$args[3];
        $handler = new ContributorApprovalHandler($this);
        return true;
    }

    /**
     * Enforce the declared contributor count on submit, if configured.
     *
     * @param array $args [&$errors, $submission, $context]
     */
    public function onValidateSubmit(string $hookName, array $args): int
    {
        $errors = &$args[0];
        $submission = $args[1];
        $context = $args[2];
        if (!$this->getSetting($context->getId(), 'requireContributorCount')) {
            return Hook::CONTINUE;
        }
        $publication = $submission->getCurrentPublication();
        $expected = (int) $publication->getData('contributorUserSyncExpectedCount');
        if ($expected < 1) {
            return Hook::CONTINUE; // not declared; nothing to enforce
        }
        $actual = count(Repo::author()->getCollector()
            ->filterByPublicationIds([(int) $publication->getId()])
            ->getMany()->all());
        if ($actual !== $expected) {
            $errors['contributors'] = [__('plugins.generic.contributorUserSync.wizard.countMismatch', [
                'expected' => $expected,
                'actual' => $actual,
            ])];
        }
        return Hook::CONTINUE;
    }

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
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return;
        }
        $service = new ContributorSyncService($this->resolveSettings($context->getId()), $context, $this);
        if (!$service->isEnabled()) {
            return;
        }

        // Authors carry a publicationId, not a submissionId; resolve it for the report.
        $submissionId = 0;
        if ($publicationId = (int) $author->getData('publicationId')) {
            $publication = Repo::publication()->get($publicationId);
            $submissionId = $publication ? (int) $publication->getData('submissionId') : 0;
        }

        $report = new SyncReport();
        $service->processAuthor($author, $submissionId, $report, true);
        $this->notifyEditor($request, $report);
    }

    /**
     * Surface the sync outcome to the editor performing the edit as a trivial
     * notification (matched, ORCID synced, skipped overwrite, no verified ORCID…).
     */
    private function notifyEditor($request, SyncReport $report): void
    {
        $user = $request->getUser();
        if (!$user || empty($report->rows)) {
            return;
        }
        $outcomes = $report->rows[0]['outcomes'];
        if (empty($outcomes)) {
            return;
        }
        // Honour the "warn" policy: only mention a missing verified ORCID when asked to.
        if (
            $outcomes === [SyncReport::MATCHED_USER, SyncReport::SKIPPED_NO_VERIFIED_ORCID]
            && ($this->resolveSettings($request->getContext()->getId())['orcidNoVerifiedAction'] ?? 'nothing') === 'nothing'
        ) {
            $outcomes = [SyncReport::MATCHED_USER];
        }
        $messages = array_map(
            fn (string $outcome) => __('plugins.generic.contributorUserSync.outcome.' . $outcome),
            $outcomes
        );
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification(
            $user->getId(),
            \PKP\notification\Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('plugins.generic.contributorUserSync.displayName') . ': ' . implode('; ', $messages)]
        );
    }

    /**
     * Inject the contributors-panel script (with its config) on backend pages
     * that can show the contributors list (workflow dashboard, submission wizard).
     */
    public function onTemplateDisplay(string $hookName, array $args): int
    {
        /** @var TemplateManager $templateMgr */
        $templateMgr = $args[0];
        $request = Application::get()->getRequest();
        if (!($request->getRouter() instanceof \PKP\core\PKPPageRouter)) {
            return Hook::CONTINUE;
        }
        $context = $request->getContext();
        if (!$context || !in_array($request->getRequestedPage(), ['dashboard', 'workflow', 'submission'], true)) {
            return Hook::CONTINUE;
        }
        $dispatcher = $request->getDispatcher();
        $config = [
            'endpoint' => $dispatcher->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_COMPONENT,
                null,
                'grid.settings.plugins.SettingsPluginGridHandler',
                'manage',
                null,
                ['plugin' => $this->getName(), 'category' => 'generic']
            ),
            'apiBase' => $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_API, $context->getPath(), 'submissions'),
            'csrfToken' => $request->getSession()->token(),
            'requireCount' => (bool) $this->getSetting($context->getId(), 'requireContributorCount'),
            'i18n' => [
                'sync' => __('plugins.generic.contributorUserSync.action.sync'),
                'invite' => __('plugins.generic.contributorUserSync.action.invite'),
                'syncAll' => __('plugins.generic.contributorUserSync.action.syncAll'),
                'error' => __('plugins.generic.contributorUserSync.action.error'),
                'countLabel' => __('plugins.generic.contributorUserSync.wizard.countLabel'),
            ],
        ];
        $templateMgr->addJavaScript(
            'contributorUserSyncConfig',
            'window.ContributorUserSyncConfig = ' . json_encode($config) . ';',
            ['inline' => true, 'contexts' => 'backend']
        );
        $templateMgr->addJavaScript(
            'contributorUserSync',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/contributorSync.js',
            ['contexts' => 'backend']
        );
        return Hook::CONTINUE;
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
            'notifyAddedContributors' => (bool) $get('notifyAddedContributors', false),
            'requireContributorCount' => (bool) $get('requireContributorCount', false),
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
            case 'bulkRun':
                // PluginGridHandler::manage() delegates without a CSRF check, so
                // enforce it here: bulkRun mutates data, and preview shares the path.
                if (!$request->checkCSRF()) {
                    return new JSONMessage(false, __('form.csrfInvalid'));
                }
                return $this->manageBulk($request, $verb === 'bulkRun');
            case 'statuses':
                return $this->manageStatuses($request);
            case 'setCount':
                if (!$request->checkCSRF()) {
                    return new JSONMessage(false, __('form.csrfInvalid'));
                }
                return $this->manageSetCount($request);
            case 'syncOne':
            case 'syncAll':
                if (!$request->checkCSRF()) {
                    return new JSONMessage(false, __('form.csrfInvalid'));
                }
                return $this->manageSyncAction($request, $verb);
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
        $service = new ContributorSyncService($settings, $context, $this);
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
     * Persist the submitter's declared contributor count on the publication.
     */
    private function manageSetCount($request): JSONMessage
    {
        $context = $request->getContext();
        $submission = Repo::submission()->get((int) $request->getUserVar('submissionId'), $context->getId());
        if (!$submission) {
            return new JSONMessage(false);
        }
        $publication = $submission->getCurrentPublication();
        $count = max(0, (int) $request->getUserVar('count'));
        Repo::publication()->edit($publication, ['contributorUserSyncExpectedCount' => $count]);
        return new JSONMessage(true, ['count' => $count]);
    }

    /**
     * Read-only: last sync status per contributor of a submission's current
     * publication, for the persistent badges in the contributors panel.
     */
    private function manageStatuses($request): JSONMessage
    {
        $context = $request->getContext();
        $submission = Repo::submission()->get((int) $request->getUserVar('submissionId'), $context->getId());
        if (!$submission) {
            return new JSONMessage(false);
        }
        $authors = Repo::author()->getCollector()
            ->filterByPublicationIds([(int) $submission->getData('currentPublicationId')])
            ->getMany();
        $map = [];
        foreach ($authors as $author) {
            $status = $author->getData(ContributorSyncService::SETTING_STATUS);
            if (!$status) {
                continue;
            }
            $map[(int) $author->getId()] = [
                'label' => __('plugins.generic.contributorUserSync.outcome.' . $status),
                'at' => (string) $author->getData(ContributorSyncService::SETTING_STATUS_AT),
            ];
        }
        return new JSONMessage(true, [
            'authors' => $map,
            'expectedCount' => (int) $submission->getCurrentPublication()->getData('contributorUserSyncExpectedCount'),
        ]);
    }

    /**
     * Manual sync triggered from the contributors panel: one contributor
     * (optionally forcing invite/create for that person) or all contributors of
     * a submission's current publication. Explicit manager actions run even
     * when automatic sync is switched off.
     */
    private function manageSyncAction($request, string $verb): JSONMessage
    {
        $context = $request->getContext();
        $settings = $this->resolveSettings($context->getId());
        $settings['syncEnabled'] = true;
        $force = (string) $request->getUserVar('force');
        if ($verb === 'syncOne' && in_array($force, ['invite', 'create'], true)) {
            $settings['syncMode'] = $force;
            // An explicit Invite click may re-send a pending invitation.
            $settings['forceResend'] = true;
        } elseif (($settings['syncMode'] ?? 'nothing') === 'nothing') {
            $settings['syncMode'] = 'link';
        }

        // Resolve the target author(s), verifying they belong to this context.
        $authors = [];
        $submissionId = 0;
        if ($verb === 'syncOne') {
            $author = Repo::author()->get((int) $request->getUserVar('authorId'));
            $publication = $author ? Repo::publication()->get((int) $author->getData('publicationId')) : null;
            $submission = $publication ? Repo::submission()->get((int) $publication->getData('submissionId'), $context->getId()) : null;
            if (!$submission) {
                return new JSONMessage(false, __('plugins.generic.contributorUserSync.action.error'));
            }
            $submissionId = (int) $submission->getId();
            $authors = [$author];
        } else {
            $submission = Repo::submission()->get((int) $request->getUserVar('submissionId'), $context->getId());
            if (!$submission) {
                return new JSONMessage(false, __('plugins.generic.contributorUserSync.action.error'));
            }
            $submissionId = (int) $submission->getId();
            $authors = Repo::author()->getCollector()
                ->filterByPublicationIds([(int) $submission->getData('currentPublicationId')])
                ->getMany();
        }

        $service = new ContributorSyncService($settings, $context, $this);
        $report = new SyncReport();
        $lines = [];
        foreach ($authors as $author) {
            $changed = $service->processAuthor($author, $submissionId, $report, true);
            if ($changed) {
                self::$suspendHook = true;
                try {
                    Repo::author()->edit($author, []);
                } finally {
                    self::$suspendHook = false;
                }
            }
            $row = end($report->rows);
            $messages = array_map(
                fn (string $outcome) => __('plugins.generic.contributorUserSync.outcome.' . $outcome),
                $row['outcomes']
            );
            $lines[] = ($row['name'] ?: $row['email']) . ': ' . implode('; ', $messages);
        }
        return new JSONMessage(true, implode("\n", $lines));
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
        $context = Application::get()->getRequest()->getContext();
        $locale = $context && $context->getId() === $contextId
            ? $context->getPrimaryLocale()
            : null;
        $options = [];
        foreach (Repo::userGroup()->getByRoleIds([Role::ROLE_ID_AUTHOR], $contextId) as $group) {
            // getByRoleIds() returns models without settings eager-loaded, so
            // reload via get() to resolve the localized name. The display name is
            // either a stored multilingual 'name' setting or, for default groups,
            // a translatable nameLocaleKey (e.g. Author).
            $groupId = (int) $group->id;
            $loaded = Repo::userGroup()->get($groupId, $contextId) ?? $group;
            $name = $loaded->getLocalizedData('name', $locale);
            if (empty($name) && !empty($loaded->nameLocaleKey)) {
                $name = __($loaded->nameLocaleKey, [], $locale);
            }
            $options[$groupId] = $name ?: ('#' . $groupId);
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
