<?php

namespace APP\plugins\generic\contributorUserSync;

use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorPost;
use PKP\facades\Repo;
use PKP\security\Validation;
use PKP\template\PKPTemplateManager as TemplateManager;

class ContributorUserSyncSettingsForm extends Form
{
    protected $plugin;
    protected $syncSummary;

    public function __construct($plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new FormValidator($this, 'enabled', 'required', 'plugins.generic.contributorUserSync.settings.enablePlugin'));
        $this->addCheck(new FormValidatorPost($this));
    }

    public function initData(): void
    {
        $contextId = $this->plugin->getCurrentContextId();

        $this->setData('enabled', $this->plugin->getSetting($contextId, 'enabled'));
        $this->setData('availableContributorRoles', $this->plugin->getSetting($contextId, 'availableContributorRoles') ?? []);
        $this->setData('allContributorRoles', self::getAllContributorRoles());
        $this->setData('customContributorRoles', $this->plugin->getSetting($contextId, 'customContributorRoles') ?? []);
        $this->setData('notifySynced', $this->plugin->getSetting($contextId, 'notifySynced'));
        $this->setData('defaultRole', $this->plugin->getSetting($contextId, 'defaultRole') ?? 'author');
        $this->setData('defaultPasswordLength', $this->plugin->getSetting($contextId, 'defaultPasswordLength') ?? 12);
        $this->setData('overwriteExisting', $this->plugin->getSetting($contextId, 'overwriteExisting'));
    }

    public function readInputData(): void
    {
        $this->readUserVars([
            'enabled',
            'customContributorRoles',
            'notifySynced',
            'defaultRole',
            'defaultPasswordLength',
            'overwriteExisting',
            'syncNow'
        ]);
    }

    public function execute(...$functionArgs): void
    {
        $request = $functionArgs[0];
        $contextId = $this->plugin->getCurrentContextId();

        $this->plugin->updateSetting($contextId, 'enabled', (bool) $this->getData('enabled'), 'bool');
        $this->plugin->updateSetting($contextId, 'customContributorRoles', $this->getData('customContributorRoles') ?? [], 'object');
        $this->plugin->updateSetting($contextId, 'notifySynced', (bool) $this->getData('notifySynced'), 'bool');
        $this->plugin->updateSetting($contextId, 'defaultRole', $this->getData('defaultRole'), 'string');
        $this->plugin->updateSetting($contextId, 'defaultPasswordLength', (int) $this->getData('defaultPasswordLength'), 'int');
        $this->plugin->updateSetting($contextId, 'overwriteExisting', (bool) $this->getData('overwriteExisting'), 'bool');

        if ($request->getUserVar('syncNow')) {
            $this->syncPastSubmissions($request, $contextId);
        }
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $pluginUrl = $request->getRouter()->url(
            $request,
            null,
            null,
            'manage',
            null,
            [
                'verb' => 'settings',
                'plugin' => $this->plugin->getName(),
                'category' => 'generic'
            ]
        );
        $templateMgr->assign('pluginUrl', $pluginUrl);
        $templateMgr->assign('syncSummary', $this->syncSummary);
        return parent::fetch($request, $template, $display);
    }

    private function syncPastSubmissions($request, $contextId): void
    {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->getMany();

        $created = 0;
        $skipped = 0;
        $role = $this->getData('defaultRole') ?? 'author';
        $notify = (bool) $this->getData('notifySynced');
        $overwrite = (bool) $this->getData('overwriteExisting');
        $restrictRoles = $this->getData('customContributorRoles') ?? [];
        $minPasswordLength = (int) $this->getData('defaultPasswordLength') ?: 12;

        foreach ($submissions as $submission) {
            foreach ($submission->getAuthors() as $author) {
                $email = $author->getEmail();
                if (!$email) {
                    $skipped++;
                    continue;
                }

                $existingUser = Repo::user()->getCollector()->filterByEmail($email)->getMany()->first();

                if ($existingUser && !$overwrite) {
                    $skipped++;
                    continue;
                }

                if (!empty($restrictRoles) && !in_array($author->getUserGroupRole(), $restrictRoles)) {
                    $skipped++;
                    continue;
                }

                if (!$existingUser) {
                    $username = strtolower(explode('@', $email)[0]) . rand(1000, 9999);
                    $password = Validation::generatePassword($minPasswordLength);

                    $user = Repo::user()->newDataObject();
                    $user->setUsername($username);
                    $user->setEmail($email);
                    $user->setGivenName($author->getGivenName(null), null);
                    $user->setFamilyName($author->getFamilyName(null), null);
                    $user->setPreferredPublicName($author->getFullName(), null);
                    $user->setAuthId(0);
                    $user->setPassword(Validation::encryptCredentials($username, $password));
                    $user->setInlineHelp(true);

                    Repo::user()->add($user);
                    Repo::user()->assignRole($contextId, $user->getId(), $role);
                    $created++;

                    if ($notify) {
                        $mail = new \PKP\mail\MailTemplate('USER_REGISTER');
                        $mail->setReplyTo($request->getSite()->getContactEmail(), $request->getSite()->getContactName());
                        $mail->assignParams([
                            'username' => $username,
                            'password' => $password,
                            'userFullName' => $user->getFullName(),
                            'contextName' => $request->getContext()->getData('name', $user->getLocale()),
                            'loginUrl' => $request->url($request->getContext()->getPath(), 'login')
                        ]);
                        $mail->addRecipient($email);
                        $mail->send();
                    }
                } elseif ($overwrite && $existingUser) {
                    $existingUser->setGivenName($author->getGivenName(null), null);
                    $existingUser->setFamilyName($author->getFamilyName(null), null);
                    $existingUser->setPreferredPublicName($author->getFullName(), null);
                    Repo::user()->edit($existingUser);
                    $skipped++;
                }
            }
        }

        $this->syncSummary = [
            'created' => $created,
            'skipped' => $skipped
        ];
    }

    public static function getAllContributorRoles(): array
    {
        $jsonPath = __DIR__ . '/contributorRoles.json';
        if (file_exists($jsonPath)) {
            $json = json_decode(file_get_contents($jsonPath), true);
            if (!empty($json['roles']) && is_array($json['roles'])) {
                return $json['roles'];
            }
        }
        return [
            'Author',
            'Co-author',
            'Lead Author',
            'Corresponding Author',
            'Data Collector',
            'Data Analyst',
            'Resource Provider',
            'Materials Provider',
            'Editor',
            'Translator',
            'Proofreader',
            'Reviewer',
            'Layout Designer',
            'Copyeditor',
            'Ethics Advisor',
            'Legal Advisor',
            'Community Partner',
            'Field Expert',
            'Liaison Officer',
            'Funding Coordinator'
        ];
    }
}
