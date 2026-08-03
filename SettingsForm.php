<?php

/**
 * @file SettingsForm.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 *
 * @brief Settings form for the Contributor User Sync plugin.
 */

namespace APP\plugins\generic\contributorUserSync;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class SettingsForm extends Form
{
    /** Scalar settings persisted as-is (with their storage type). */
    private const SCALAR_SETTINGS = [
        'syncEnabled' => 'bool',
        'syncMode' => 'string',
        'orcidAutoSync' => 'bool',
        'orcidOverwrite' => 'bool',
        'orcidAllowManual' => 'bool',
        'orcidNoVerifiedAction' => 'string',
        'updateContributorFromUser' => 'bool',
        'updateUserFromContributor' => 'bool',
        'notifyAddedContributors' => 'bool',
        'requireContributorCount' => 'bool',
        'createUserAllowReviewer' => 'bool',
    ];

    public function __construct(private ContributorUserSyncPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $settings = $this->plugin->resolveSettings($this->contextId);
        foreach (array_keys(self::SCALAR_SETTINGS) as $key) {
            $this->setData($key, $settings[$key]);
        }
        $this->setData('eligibleRoles', $settings['eligibleRoles']);
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys(self::SCALAR_SETTINGS));
        $this->readUserVars(['eligibleRoles']);
        // Normalise the role picker to a list of integer user-group ids.
        $roles = (array) ($this->getData('eligibleRoles') ?: []);
        $this->setData('eligibleRoles', array_values(array_map('intval', $roles)));
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'contributorRoleOptions' => $this->plugin->getContributorRoleOptions($this->contextId),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        foreach (self::SCALAR_SETTINGS as $key => $type) {
            $value = $this->getData($key);
            if ($type === 'bool') {
                $value = (bool) $value;
            }
            $this->plugin->updateSetting($this->contextId, $key, $value, $type);
        }
        $this->plugin->updateSetting($this->contextId, 'eligibleRoles', (array) $this->getData('eligibleRoles'), 'object');
        return parent::execute(...$functionArgs);
    }
}
