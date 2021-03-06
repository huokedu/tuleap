<?php
/**
 * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
 * Copyright (c) Enalean, 2011 - 2017. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

use Tuleap\Admin\AdminPageRenderer;
use Tuleap\Git\Events\ParseGitolite3Logs;
use Tuleap\Git\GerritCanMigrateChecker;
use Tuleap\Git\Gitolite\SSHKey\AuthorizedKeysFileCreator;
use Tuleap\Git\Gitolite\SSHKey\DumperFactory;
use Tuleap\Git\Gitolite\SSHKey\ManagementDetector;
use Tuleap\Git\Gitolite\SSHKey\Provider\WholeInstanceKeysAggregator;
use Tuleap\Git\Gitolite\SSHKey\Provider\GitoliteAdmin;
use Tuleap\Git\Gitolite\SSHKey\Provider\GerritServer;
use Tuleap\Git\Gitolite\SSHKey\Provider\User;
use Tuleap\Git\Gitolite\SSHKey\SystemEvent\MigrateToTuleapSSHKeyManagement;
use Tuleap\Git\Gitolite\VersionDetector;
use Tuleap\Git\Gitolite\Gitolite3LogParser;
use Tuleap\Git\Notifications\NotificationsForProjectMemberCleaner;
use Tuleap\Git\Notifications\UgroupsToNotifyDao;
use Tuleap\Git\Notifications\UsersToNotifyDao;
use Tuleap\Git\GlobalParameterDao;
use Tuleap\Git\Permissions\FineGrainedRegexpValidator;
use Tuleap\Git\Permissions\PatternValidator;
use Tuleap\Git\Permissions\RegexpTemplateDao;
use Tuleap\Git\Permissions\RegexpFineGrainedDao;
use Tuleap\Git\Permissions\RegexpFineGrainedDisabler;
use Tuleap\Git\Permissions\RegexpFineGrainedRetriever;
use Tuleap\Git\Permissions\RegexpFineGrainedEnabler;
use Tuleap\Git\Permissions\RegexpPermissionFilter;
use Tuleap\Git\Permissions\RegexpRepositoryDao;
use Tuleap\Git\RemoteServer\Gerrit\HttpUserValidator;
use Tuleap\Git\Gitolite\GitoliteFileLogsDao;
use Tuleap\Git\Webhook\WebhookDao;
use Tuleap\Git\Permissions\FineGrainedUpdater;
use Tuleap\Git\Permissions\FineGrainedRetriever;
use Tuleap\Git\Permissions\FineGrainedDao;
use Tuleap\Git\Permissions\FineGrainedPermissionFactory;
use Tuleap\Git\Permissions\FineGrainedPermissionSaver;
use Tuleap\Git\Permissions\DefaultFineGrainedPermissionFactory;
use Tuleap\Git\Permissions\TemplateFineGrainedPermissionSaver;
use Tuleap\Git\Permissions\DefaultFineGrainedPermissionReplicator;
use Tuleap\Git\CIToken\Manager as CITokenManager;
use Tuleap\Git\CIToken\Dao as CITokenDao;
use Tuleap\Git\Permissions\FineGrainedPermissionDestructor;
use Tuleap\Git\Permissions\FineGrainedRepresentationBuilder;
use Tuleap\Git\AccessRightsPresenterOptionsBuilder;
use Tuleap\Git\Permissions\FineGrainedPermissionReplicator;
use Tuleap\Git\Permissions\FineGrainedPatternValidator;
use Tuleap\Git\Permissions\FineGrainedPermissionSorter;
use Tuleap\Git\Permissions\HistoryValueFormatter;
use Tuleap\Git\Permissions\PermissionChangesDetector;
use Tuleap\Git\Permissions\TemplatePermissionsUpdater;
use Tuleap\Git\Repository\DescriptionUpdater;
use Tuleap\Git\History\GitPhpAccessLogger;
use Tuleap\Git\History\Dao as HistoryDao;
use Tuleap\Git\XmlUgroupRetriever;
use Tuleap\Mail\MailFilter;
use Tuleap\Mail\MailLogger;
use Tuleap\Git\RestrictedGerritServerDao;
use Tuleap\Git\GerritServerResourceRestrictor;
use Tuleap\Git\RemoteServer\Gerrit\Restrictor;
use Tuleap\Git\Notifications\UgroupToNotifyUpdater;
use Tuleap\Git\DiskUsage\Collector;
use Tuleap\Git\DiskUsage\Retriever;

require_once 'constants.php';
require_once 'autoload.php';

/**
 * GitPlugin
 */
class GitPlugin extends Plugin {

    /**
     *
     * @var Logger
     */
    private $logger;

    /**
     * @var Git_UserAccountManager
     */
    private $user_account_manager;

    /**
     * Service short_name as it appears in 'service' table
     *
     * Should be transfered in 'ServiceGit' class when we introduce it
     */
    const SERVICE_SHORTNAME = 'plugin_git';

    const SYSTEM_NATURE_NAME = 'git_revision';

    private static $FREQUENCIES_GIT_READ = 'git';

    public function __construct($id)
    {
        parent::__construct($id);
        bindtextdomain('tuleap-git', __DIR__.'/../site-content');

        $this->setScope(Plugin::SCOPE_PROJECT);
        $this->_addHook('site_admin_option_hook', 'site_admin_option_hook', false);
        $this->_addHook('cssfile',                                         'cssFile',                                      false);
        $this->_addHook('javascript_file',                                 'jsFile',                                       false);
        $this->_addHook(Event::JAVASCRIPT,                                 'javascript',                                   false);
        $this->_addHook(Event::GET_SYSTEM_EVENT_CLASS,                     'getSystemEventClass',                          false);
        $this->_addHook(Event::GET_PLUGINS_AVAILABLE_KEYWORDS_REFERENCES,  'getReferenceKeywords',                         false);
        $this->_addHook(Event::GET_AVAILABLE_REFERENCE_NATURE,             'getReferenceNatures',                          false);
        $this->addHook(Event::GET_REFERENCE);
        $this->_addHook('SystemEvent_PROJECT_IS_PRIVATE',                  'changeProjectRepositoriesAccess',              false);
        $this->_addHook('SystemEvent_PROJECT_RENAME',                      'systemEventProjectRename',                     false);
        $this->_addHook('project_is_deleted',                              'project_is_deleted',                           false);
        $this->_addHook('file_exists_in_data_dir',                         'file_exists_in_data_dir',                      false);
        $this->addHook(Event::SERVICE_ICON);
        $this->addHook(Event::SERVICES_ALLOWED_FOR_PROJECT);

        // Stats plugin
        $this->_addHook('plugin_statistics_disk_usage_collect_project',    'plugin_statistics_disk_usage_collect_project', false);
        $this->_addHook('plugin_statistics_disk_usage_service_label',      'plugin_statistics_disk_usage_service_label',   false);
        $this->_addHook('plugin_statistics_color',                         'plugin_statistics_color',                      false);

        $this->_addHook(Event::LIST_SSH_KEYS,                              'getRemoteServersForUser',                      false);
        $this->_addHook(Event::DUMP_SSH_KEYS);
        $this->_addHook(Event::EDIT_SSH_KEYS);
        $this->_addHook(Event::PROCCESS_SYSTEM_CHECK);
        $this->_addHook(Event::SYSTEM_EVENT_GET_TYPES_FOR_DEFAULT_QUEUE);
        $this->_addHook(Event::SYSTEM_EVENT_GET_CUSTOM_QUEUES);
        $this->_addHook(Event::SYSTEM_EVENT_GET_TYPES_FOR_CUSTOM_QUEUE);

        $this->_addHook('permission_get_name',                             'permission_get_name',                          false);
        $this->_addHook('permission_get_object_type',                      'permission_get_object_type',                   false);
        $this->_addHook('permission_get_object_name',                      'permission_get_object_name',                   false);
        $this->_addHook('permission_get_object_fullname',                  'permission_get_object_fullname',               false);
        $this->_addHook('permission_user_allowed_to_change',               'permission_user_allowed_to_change',            false);
        $this->_addHook('permissions_for_ugroup',                          'permissions_for_ugroup',                       false);

        $this->_addHook('statistics_collector',                            'statistics_collector',                         false);

        $this->_addHook('collect_ci_triggers',                             'collect_ci_triggers',                          false);
        $this->_addHook('save_ci_triggers',                                'save_ci_triggers',                             false);
        $this->_addHook('update_ci_triggers',                              'update_ci_triggers',                           false);
        $this->_addHook('delete_ci_triggers',                              'delete_ci_triggers',                           false);

        $this->_addHook('logs_daily',                                       'logsDaily',                                   false);
        $this->_addHook('widget_instance',                                  'myPageBox',                                   false);
        $this->_addHook('widgets',                                          'widgets',                                     false);
        $this->_addHook('show_pending_documents',                           'showArchivedRepositories',                    false);

        $this->_addHook('SystemEvent_USER_RENAME', 'systemevent_user_rename');

        // User Group membership modification
        $this->_addHook('project_admin_add_user');
        $this->_addHook('project_admin_ugroup_add_user');
        $this->_addHook('project_admin_remove_user');
        $this->_addHook('project_admin_ugroup_remove_user');
        $this->_addHook('project_admin_change_user_permissions');
        $this->_addHook('project_admin_ugroup_deletion');
        $this->_addHook('project_admin_remove_user_from_project_ugroups');
        $this->_addHook('project_admin_ugroup_creation');
        $this->_addHook('project_admin_parent_project_modification');
        $this->_addHook(Event::UGROUP_MANAGER_UPDATE_UGROUP_BINDING_ADD);
        $this->_addHook(Event::UGROUP_MANAGER_UPDATE_UGROUP_BINDING_REMOVE);

        // Project hierarchy modification
        $this->_addHook(Event::PROJECT_SET_PARENT_PROJECT, 'project_admin_parent_project_modification');
        $this->_addHook(Event::PROJECT_UNSET_PARENT_PROJECT, 'project_admin_parent_project_modification');

        //Gerrit user synch help
        $this->_addHook(Event::MANAGE_THIRD_PARTY_APPS, 'manage_third_party_apps');

        $this->_addHook(Event::REGISTER_PROJECT_CREATION);
        $this->_addHook(Event::GET_PROJECTID_FROM_URL);
        $this->_addHook('anonymous_access_to_script_allowed');
        $this->_addHook(Event::IS_SCRIPT_HANDLED_FOR_RESTRICTED);
        $this->_addHook(Event::GET_SERVICES_ALLOWED_FOR_RESTRICTED);
        $this->_addHook(Event::PROJECT_ACCESS_CHANGE);
        $this->_addHook(Event::SITE_ACCESS_CHANGE);

        $this->_addHook('fill_project_history_sub_events');
        $this->_addHook(Event::POST_SYSTEM_EVENTS_ACTIONS);

        $this->addHook(EVENT::REST_RESOURCES);
        $this->addHook(EVENT::REST_PROJECT_RESOURCES);
        $this->addHook(EVENT::REST_PROJECT_GET_GIT);
        $this->addHook(EVENT::REST_PROJECT_OPTIONS_GIT);

        $this->_addHook(Event::IMPORT_XML_PROJECT, 'importXmlProject', false);

        // Gerrit user suspension
        if (defined('LDAP_DAILY_SYNCHRO_UPDATE_USER')) {
            $this->addHook(LDAP_DAILY_SYNCHRO_UPDATE_USER);
        }

        $this->addHook(Event::SERVICES_TRUNCATED_EMAILS);

        $this->addHook('codendi_daily_start');

        $this->addHook(Event::BURNING_PARROT_GET_STYLESHEETS);
        $this->addHook(Event::BURNING_PARROT_GET_JAVASCRIPT_FILES);
    }

    public function getHooksAndCallbacks()
    {
        $this->addHook(Event::IS_IN_SITEADMIN);

        if (defined('STATISTICS_BASE_DIR')) {
            $this->addHook(Statistics_Event::FREQUENCE_STAT_ENTRIES);
            $this->addHook(Statistics_Event::FREQUENCE_STAT_SAMPLE);
        }

        return parent::getHooksAndCallbacks();
    }

    public function getServiceShortname() {
        return self::SERVICE_SHORTNAME;
    }

    public function service_icon($params) {
        $params['list_of_icon_unicodes'][$this->getServiceShortname()] = '\e806';
    }

    public function site_admin_option_hook($params)
    {
        $params['plugins'][] = array(
            'label' => $GLOBALS['Language']->getText('plugin_git', 'descriptor_name'),
            'href'  => $this->getPluginPath() . '/admin/index.php'
        );
    }

    /** @see Event::IS_IN_SITEADMIN */
    public function is_in_siteadmin($params)
    {
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath().'/admin/') === 0) {
            $params['is_in_siteadmin'] = true;
        }
    }

    public function getPluginInfo() {
        if (!is_a($this->pluginInfo, 'GitPluginInfo')) {
            $this->pluginInfo = new GitPluginInfo($this);
        }
        return $this->pluginInfo;
    }

    /**
     * @see Statistics_Event::FREQUENCE_STAT_ENTRIES
     */
    public function plugin_statistics_frequence_stat_entries($params)
    {
        $params['entries'][self::$FREQUENCIES_GIT_READ] = 'Git read access';
    }

    /**
     * @see Statistics_Event::FREQUENCE_STAT_SAMPLE
     */
    public function plugin_statistics_frequence_stat_sample($params)
    {
        if ($params['character'] === self::$FREQUENCIES_GIT_READ) {
            $params['sample'] = new Tuleap\Git\Statistics\FrequenciesSample();
        }
    }

    /**
     * Returns the configuration defined for given variable name
     *
     * @param String $key
     *
     * @return Mixed
     */
    public function getConfigurationParameter($key) {
        return $this->getPluginInfo()->getPropertyValueForName($key);
    }

    public function cssFile($params) {
        // Only show the stylesheet if we're actually in the Git pages.
        // This stops styles inadvertently clashing with the main site.
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0 ||
            strpos($_SERVER['REQUEST_URI'], '/widgets/') === 0) {
            echo '<link rel="stylesheet" type="text/css" href="'.$this->getThemePath().'/css/style.css" />';
            echo '<link rel="stylesheet" type="text/css" href="/plugins/git/themes/default/css/gitphp.css" />';
        }
    }

    public function burning_parrot_get_stylesheets(array $params)
    {
        if (strpos($_SERVER['REQUEST_URI'], '/plugins/git') === 0) {
            $variant = $params['variant'];
            $params['stylesheets'][] = $this->getThemePath() .'/css/style-'. $variant->getName() .'.css';
        }
    }

    public function jsFile($params) {
        // Only show the javascript if we're actually in the Git pages.
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0) {
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/git.js"></script>';
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/online_edit.js"></script>';
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/clone_url.js"></script>';
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/mass-update.js"></script>';
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/webhooks.js"></script>';
            echo '<script type="text/javascript" src="'.$this->getPluginPath().'/scripts/permissions.js"></script>';
        }
    }

    public function burning_parrot_get_javascript_files(array $params)
    {
        if (strpos($_SERVER['REQUEST_URI'], GIT_BASE_URL) === 0) {
            if (strpos($_SERVER['REQUEST_URI'], 'gerrit_servers_admin')) {
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/modal-add-gerrit-server.js';
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/modal-delete-gerrit-server.js';
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/modal-edit-gerrit-server.js';
            } else if (strpos($_SERVER['REQUEST_URI'], 'mirrors_admin')) {
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/modal-add-mirror.js';
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/modal-mirror-configuration.js';
            } else if (strpos($_SERVER['REQUEST_URI'], 'mirrors_restriction')) {
                $params['javascript_files'][] = '/scripts/tuleap/manage-allowed-projects-on-resource.js';
            } else if (strpos($_SERVER['REQUEST_URI'], 'gerrit_servers_restriction')) {
                $params['javascript_files'][] = '/scripts/tuleap/manage-allowed-projects-on-resource.js';
            }
            else if (strpos($_SERVER['REQUEST_URI'], 'gitolite_config')) {
                $params['javascript_files'][] = GIT_BASE_URL . '/scripts/admin-gitolite.js';
            }
        }
    }

    public function javascript($params) {
        include $GLOBALS['Language']->getContent('script_locale', null, 'git');
    }

    public function system_event_get_types_for_default_queue(array &$params) {
        $params['types'] = array_merge($params['types'], $this->getGitSystemEventManager()->getTypesForDefaultQueue());
    }

    /** @see Event::SYSTEM_EVENT_GET_CUSTOM_QUEUES */
    public function system_event_get_custom_queues(array &$params) {
        $params['queues'][Git_SystemEventQueue::NAME] = new Git_SystemEventQueue($this->getLogger());
        $params['queues'][Git_Mirror_MirrorSystemEventQueue::NAME] = new Git_Mirror_MirrorSystemEventQueue($this->getLogger());
    }

    /** @see Event::SYSTEM_EVENT_GET_TYPES_FOR_CUSTOM_QUEUE */
    public function system_event_get_types_for_custom_queue(array &$params) {
        if ($params['queue'] == Git_SystemEventQueue::NAME) {
            $params['types'] = array_merge(
                $params['types'],
                $this->getGitSystemEventManager()->getTypes()
            );
        }

        if ($params['queue'] == Git_Mirror_MirrorSystemEventQueue::NAME) {
            $params['types'] = array_merge(
                $params['types'],
                $this->getGitSystemEventManager()->getGrokMirrorTypes()
            );
        }
    }

    /**
     *This callback make SystemEvent manager knows about git plugin System Events
     * @param <type> $params
     */
    public function getSystemEventClass($params) {
        switch($params['type']) {
            case SystemEvent_GIT_REPO_UPDATE::NAME:
                $params['class'] = 'SystemEvent_GIT_REPO_UPDATE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getSystemEventDao(),
                    $this->getLogger(),
                    $this->getGitSystemEventManager()
                );
                break;
            case SystemEvent_GIT_REPO_DELETE::NAME:
                $params['class'] = 'SystemEvent_GIT_REPO_DELETE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getLogger(),
                    $this->getGitSystemEventManager(),
                    $this->getUgroupsToNotifyDao(),
                    $this->getUsersToNotifyDao()
                );
                break;
            case SystemEvent_GIT_LEGACY_REPO_DELETE::NAME:
                $params['class'] = 'SystemEvent_GIT_LEGACY_REPO_DELETE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getManifestManager(),
                    $this->getLogger(),
                );
                break;
            case SystemEvent_GIT_LEGACY_REPO_ACCESS::NAME:
                $params['class'] = 'SystemEvent_GIT_LEGACY_REPO_ACCESS';
                break;
            case SystemEvent_GIT_GERRIT_MIGRATION::NAME:
                $params['class'] = 'SystemEvent_GIT_GERRIT_MIGRATION';
                $params['dependencies'] = array(
                    $this->getGitDao(),
                    $this->getRepositoryFactory(),
                    $this->getGerritServerFactory(),
                    $this->getLogger(),
                    $this->getProjectCreator(),
                    $this->getGitRepositoryUrlManager(),
                    UserManager::instance(),
                    new MailBuilder(
                        TemplateRendererFactory::build(),
                        new MailFilter(
                            UserManager::instance(),
                            new URLVerification(),
                            new MailLogger()
                        )
                    ),
                );
                break;
            case SystemEvent_GIT_REPO_FORK::NAME:
                $params['class'] = 'SystemEvent_GIT_REPO_FORK';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory()
                );
                break;
            case SystemEvent_GIT_GERRIT_ADMIN_KEY_DUMP::NAME:
                $params['class'] = 'SystemEvent_GIT_GERRIT_ADMIN_KEY_DUMP';
                $params['dependencies'] = array(
                    $this->getGerritServerFactory(),
                    $this->getSSHKeyDumper(),
                );
                break;
            case SystemEvent_GIT_GERRIT_PROJECT_DELETE::NAME:
                $params['class'] = 'SystemEvent_GIT_GERRIT_PROJECT_DELETE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getGerritServerFactory(),
                    $this->getGerritDriverFactory()
                );
                break;
            case SystemEvent_GIT_GERRIT_PROJECT_READONLY::NAME:
                $params['class'] = 'SystemEvent_GIT_GERRIT_PROJECT_READONLY';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getGerritServerFactory(),
                    $this->getGerritDriverFactory()
                );
                break;
            case SystemEvent_GIT_USER_RENAME::NAME:
                $params['class'] = 'SystemEvent_GIT_USER_RENAME';
                $params['dependencies'] = array(
                    $this->getSSHKeyDumper(),
                    UserManager::instance()
                );
                break;
            case SystemEvent_GIT_GROKMIRROR_MANIFEST_UPDATE::NAME:
                $params['class'] = 'SystemEvent_GIT_GROKMIRROR_MANIFEST_UPDATE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getManifestManager(),
                );
                break;
            case SystemEvent_GIT_GROKMIRROR_MANIFEST_UPDATE_FOLLOWING_A_GIT_PUSH::NAME:
                $params['class'] = 'SystemEvent_GIT_GROKMIRROR_MANIFEST_UPDATE_FOLLOWING_A_GIT_PUSH';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getManifestManager(),
                );
                break;
            case SystemEvent_GIT_GROKMIRROR_MANIFEST_CHECK::NAME:
                $params['class'] = 'SystemEvent_GIT_GROKMIRROR_MANIFEST_CHECK';
                $params['dependencies'] = array(
                    $this->getManifestManager(),
                );
                break;
            case SystemEvent_GIT_GROKMIRROR_MANIFEST_REPODELETE::NAME:
                $params['class'] = 'SystemEvent_GIT_GROKMIRROR_MANIFEST_REPODELETE';
                $params['dependencies'] = array(
                    $this->getManifestManager(),
                );
                break;
            case SystemEvent_GIT_EDIT_SSH_KEYS::NAME:
                $params['class'] = 'SystemEvent_GIT_EDIT_SSH_KEYS';
                $params['dependencies'] = array(
                    UserManager::instance(),
                    $this->getSSHKeyDumper(),
                    $this->getUserAccountManager(),
                    $this->getGitSystemEventManager(),
                    $this->getLogger()
                );
                break;
            case SystemEvent_GIT_DUMP_ALL_SSH_KEYS::NAME:
                $params['class'] = 'SystemEvent_GIT_DUMP_ALL_SSH_KEYS';
                $params['dependencies'] = array(
                    $this->getSSHKeyMassDumper(),
                    $this->getLogger()
                );
                break;
            case SystemEvent_GIT_REPO_RESTORE::NAME:
                $params['class'] = 'SystemEvent_GIT_REPO_RESTORE';
                $params['dependencies'] = array(
                    $this->getRepositoryFactory(),
                    $this->getGitSystemEventManager(),
                    $this->getLogger()
                );
                break;
            case SystemEvent_GIT_PROJECTS_UPDATE::NAME:
                $params['class'] = 'SystemEvent_GIT_PROJECTS_UPDATE';
                $params['dependencies'] = array(
                    $this->getLogger(),
                    $this->getGitSystemEventManager(),
                    $this->getProjectManager(),
                    $this->getGitoliteDriver(),
                );
                break;
            case SystemEvent_GIT_DUMP_ALL_MIRRORED_REPOSITORIES::NAME:
                $params['class'] = 'SystemEvent_GIT_DUMP_ALL_MIRRORED_REPOSITORIES';
                $params['dependencies'] = array(
                    $this->getGitoliteDriver()
                );
                break;
            case SystemEvent_GIT_UPDATE_MIRROR::NAME:
                $params['class'] = 'SystemEvent_GIT_UPDATE_MIRROR';
                $params['dependencies'] = array(
                    $this->getGitoliteDriver()
                );
                break;
            case SystemEvent_GIT_DELETE_MIRROR::NAME:
                $params['class'] = 'SystemEvent_GIT_DELETE_MIRROR';
                $params['dependencies'] = array(
                    $this->getGitoliteDriver()
                );
                break;
            case SystemEvent_GIT_REGENERATE_GITOLITE_CONFIG::NAME:
                $params['class'] = 'SystemEvent_GIT_REGENERATE_GITOLITE_CONFIG';
                $params['dependencies'] = array(
                    $this->getGitoliteDriver(),
                    $this->getProjectManager()
                );
                break;
            case ParseGitolite3Logs::NAME:
                $params['class'] = '\\Tuleap\\Git\\Events\\ParseGitolite3Logs';
                $params['dependencies'] = array(
                    $this->getGitolite3Parser()
                );
                break;
            case MigrateToTuleapSSHKeyManagement::NAME:
                $params['class'] = 'Tuleap\\Git\\Gitolite\\SSHKey\\SystemEvent\\MigrateToTuleapSSHKeyManagement';
                $params['dependencies'] = array(
                    new GlobalParameterDao(),
                    new System_Command()
                );
                break;
            default:
                break;
        }
    }

    private function getTemplateFactory() {
        return new Git_Driver_Gerrit_Template_TemplateFactory(new Git_Driver_Gerrit_Template_TemplateDao());
    }

    private function getSystemEventDao() {
        return new SystemEventDao();
    }

    public function getReferenceKeywords($params) {
        $params['keywords'] = array_merge(
            $params['keywords'],
            array(Git::REFERENCE_KEYWORD)
        );
    }

    public function getReferenceNatures($params) {
        $params['natures'] = array_merge(
            $params['natures'],
            array(
                Git::REFERENCE_NATURE => array(
                    'keyword' => Git::REFERENCE_KEYWORD,
                    'label'   => $GLOBALS['Language']->getText('plugin_git', 'reference_commit_nature_key')
                )
            )
        );
    }

    public function get_reference($params) {
        if ($params['keyword'] == Git::REFERENCE_KEYWORD) {
            $reference = false;
            if ($params['project']) {
                $git_reference_manager = new Git_ReferenceManager(
                    $this->getRepositoryFactory(),
                    $params['reference_manager']
                );
                $reference = $git_reference_manager->getReference(
                    $params['project'],
                    $params['keyword'],
                    $params['value']
                );
            }
            $params['reference'] = $reference;
        }
    }

    public function changeProjectRepositoriesAccess($params) {
        $groupId   = $params[0];
        $isPrivate = $params[1];
        $dao       = new GitDao();
        $factory   = $this->getRepositoryFactory();
        GitActions::changeProjectRepositoriesAccess($groupId, $isPrivate, $dao, $factory);
    }

    public function systemEventProjectRename($params) {
        GitActions::renameProject($params['project'], $params['new_name']);
    }

    public function file_exists_in_data_dir($params) {
        $params['result'] = $this->isNameAvailable($params['new_name'], $params['error']);
    }

    private function isNameAvailable($newName, &$error) {
        $backend_gitolite = $this->getBackendGitolite();
        $backend_gitshell = Backend::instance('Git','GitBackend', array($this->getGitRepositoryUrlManager()));

        if (! $backend_gitolite->isNameAvailable($newName) && ! $backend_gitshell->isNameAvailable($newName)) {
            $error = $GLOBALS['Language']->getText('plugin_git', 'actions_name_not_available');
            return false;
        }

        return true;
    }

    public function getBackendGitolite() {
        return new Git_Backend_Gitolite($this->getGitoliteDriver(), $this->getLogger());
    }

    public function process() {
        $this->getGitController()->process();
    }

    /**
     * We expect that the check fo access right to this method has already been done by the caller
     */
    public function processAdmin(Codendi_Request $request) {
        $project_manager             = ProjectManager::instance();
        $gerrit_ressource_restrictor = new GerritServerResourceRestrictor(new RestrictedGerritServerDao());

        $admin = new Git_AdminRouter(
            $this->getGerritServerFactory(),
            new CSRFSynchronizerToken('/plugins/git/admin/'),
            $this->getMirrorDataMapper(),
            new Git_MirrorResourceRestrictor(
                new Git_RestrictedMirrorDao(),
                $this->getMirrorDataMapper(),
                $this->getGitSystemEventManager(),
                new ProjectHistoryDao()
            ),
            $project_manager,
            $this->getManifestManager(),
            $this->getGitSystemEventManager(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getRegexpFineGrainedEnabler(),
            $this->getAdminPageRenderer(),
            $this->getRegexpFineGrainedDisabler(),
            $gerrit_ressource_restrictor,
            new Restrictor(
                $this->getGerritServerFactory(),
                $gerrit_ressource_restrictor,
                $project_manager
            ),
            $this->getManagementDetector()
        );

        $admin->process($request);
        $admin->display($request);
    }

    private function getRegexpFineGrainedEnabler()
    {
        return new RegexpFineGrainedEnabler(
            $this->getRegexpFineGrainedDao(),
            $this->getRegexpRepositoryDao(),
            $this->getRegexpTemplateDao()
        );
    }

    public function getRegexpFineGrainedRetriever()
    {
        return new RegexpFineGrainedRetriever(
            $this->getRegexpFineGrainedDao(),
            $this->getRegexpRepositoryDao(),
            $this->getRegexpTemplateDao()
        );
    }

    private function getRegexpTemplateDao()
    {
        return new RegexpTemplateDao();
    }

    private function getRegexpFineGrainedDao()
    {
        return new RegexpFineGrainedDao();
    }

    private function getRegexpRepositoryDao()
    {
        return new RegexpRepositoryDao();
    }

    private function getMirrorDataMapper() {
        return new Git_Mirror_MirrorDataMapper(
            new Git_Mirror_MirrorDao(),
            UserManager::instance(),
            new GitRepositoryFactory(
                new GitDao(),
                ProjectManager::instance()
            ),
            $this->getProjectManager(),
            $this->getGitSystemEventManager(),
            new Git_Gitolite_GitoliteRCReader(new VersionDetector()),
            new DefaultProjectMirrorDao()
        );
    }

    private function getAdminPageRenderer()
    {
        return new AdminPageRenderer();
    }

    /**
     * Hook to collect Git disk size usage per project
     *
     * @param array $params
     */
    public function plugin_statistics_disk_usage_collect_project($params)
    {
        $row            = $params['project_row'];
        $disk_usage_dao = $params['DiskUsageManager']->_getDao();
        $retriever      = new Retriever($disk_usage_dao);
        $collector      = new Collector($params['DiskUsageManager'], new Git_LogDao(), $retriever);
        $project        = new Project($row);

        $disk_usage_dao->addGroup(
            $row['group_id'],
            self::SERVICE_SHORTNAME,
            $collector->collectForGitoliteRepositories($project),
            $_SERVER['REQUEST_TIME']
        );
    }

    /**
     * Hook to list docman in the list of serices managed by disk stats
     *
     * @param array $params
     */
    function plugin_statistics_disk_usage_service_label($params) {
        $params['services'][self::SERVICE_SHORTNAME] = 'Git';
    }

    /**
     * Hook to choose the color of the plugin in the graph
     *
     * @param array $params
     */
    function plugin_statistics_color($params) {
        if ($params['service'] == self::SERVICE_SHORTNAME) {
            $params['color'] = 'palegreen';
        }
    }

    /**
     * Function called when a user is removed from a project
     * If a user is removed from a project wich having a private git repository, the
     * user should be removed from notification.
     *
     * @param array $params
     *
     * @return void
     */
    private function projectRemoveUserFromNotification($params) {
        $project_id = $params['group_id'];
        $user_id = $params['user_id'];

        $user_manager = UserManager::instance();

        $user    = $user_manager->getUserById($user_id);
        $project = $this->getProjectManager()->getProject($project_id);

        $cleaner = new NotificationsForProjectMemberCleaner(
            $this->getRepositoryFactory(),
            new Git_PostReceiveMailManager(),
            $this->getUsersToNotifyDao()
        );
        $cleaner->cleanNotificationsAfterUserRemoval($project, $user);

    }

    /**
     *
     * @see Event::EDIT_SSH_KEYS
     * @param array $params
     */
    public function edit_ssh_keys(array $params) {
        $this->getGitSystemEventManager()->queueEditSSHKey($params['user_id'], $params['original_keys']);
    }

    /**
     * Hook. Call by backend when SSH keys are modified
     *
     * @param array $params Should contain two entries:
     *     'user' => PFUser,
     *     'original_keys' => string of concatenated ssh keys
     */
    public function dump_ssh_keys(array $params) {
        $this->getGitSystemEventManager()->queueDumpAllSSHKeys();
    }

    /**
     *
     * @param PFUser $user
     * @return Git_UserAccountManager
     */
    private function getUserAccountManager() {
        if (! $this->user_account_manager) {
            $this->user_account_manager = new Git_UserAccountManager(
                $this->getGerritDriverFactory(),
                $this->getGerritServerFactory()
            );
        }

        return $this->user_account_manager;
    }

    /**
     *
     * @param Git_UserAccountManager $manager
     */
    public function setUserAccountManager(Git_UserAccountManager $manager) {
        $this->user_account_manager = $manager;
    }

    /**
     * Method called as a hook.
     *
     * @param array $params Should contain two entries:
     *     'user' => PFUser,
     *     'html' => string An emty string of html output- passed by reference
     */
    public function getRemoteServersForUser(array $params) {
        if (! $user = $this->getUserFromParameters($params)) {
            return;
        }

        if (! isset($params['html']) || ! is_string($params['html'])) {
            return;
        }
        $html = $params['html'];

        $remote_servers = $this->getGerritServerFactory()->getRemoteServersForUser($user);

        if (count($remote_servers) > 0) {
            $purifier = Codendi_HTMLPurifier::instance();
            $html     = '<br />'.
                $purifier->purify($GLOBALS['Language']->getText('plugin_git', 'push_ssh_keys_info')) .
                '<ul>';

            foreach ($remote_servers as $server) {
                $html .= '<li>
                        <a href="'. $purifier->purify($server->getBaseUrl()) .'/#/settings/ssh-keys">'.
                            $purifier->purify($server->getBaseUrl()) .'
                        </a>
                    </li>';
            }

            $html .= '</ul>
                <form action="" method="post">
                    <input type="submit"
                        class="btn btn-small"
                        title="'. $purifier->purify($GLOBALS['Language']->getText('plugin_git', 'push_ssh_keys_button_title')) .'"
                        value="'. $purifier->purify($GLOBALS['Language']->getText('plugin_git', 'push_ssh_keys_button_value')) .'"
                        name="ssh_key_push"/>
                </form>';
        }

        if (isset($_POST['ssh_key_push'])) {
            $this->pushUserSSHKeysToRemoteServers($user);
        }

        $params['html'] = $html;
    }

    /**
     * Method called as a hook.

     * Copies all SSH Keys to Remote Git Servers
     * @param PFUser $user
     */
    private function pushUserSSHKeysToRemoteServers(PFUser $user) {
        $this->getLogger()->info('Trying to push ssh keys for user: '.$user->getUnixName());
        $git_user_account_manager = $this->getUserAccountManager();

        try {
            $git_user_account_manager->pushSSHKeys(
                $user
            );
        } catch (Git_UserSynchronisationException $e) {
            $message = $GLOBALS['Language']->getText('plugin_git','push_ssh_keys_error');
            $GLOBALS['Response']->addFeedback('error', $message);

            $this->getLogger()->error('Unable to push ssh keys: ' . $e->getMessage());
            return;
        }

        $this->getLogger()->info('Successfully pushed ssh keys for user: '.$user->getUnixName());
    }

    private function getUserFromParameters($params) {
        if (! isset($params['user']) || ! $params['user'] instanceof PFUser) {
            $this->getLogger()->error('Invalid user passed in params: ' . print_r($params, true));
            return false;
        }

        return $params['user'];
    }

    function permission_get_name($params) {
        if (!$params['name']) {
            switch($params['permission_type']) {
                case 'PLUGIN_GIT_READ':
                    $params['name'] = $GLOBALS['Language']->getText('plugin_git', 'perm_R');
                    break;
                case 'PLUGIN_GIT_WRITE':
                    $params['name'] = $GLOBALS['Language']->getText('plugin_git', 'perm_W');
                    break;
                case 'PLUGIN_GIT_WPLUS':
                    $params['name'] = $GLOBALS['Language']->getText('plugin_git', 'perm_W+');
                    break;
                default:
                    break;
            }
        }
    }
    function permission_get_object_type($params) {
        if (!$params['object_type']) {
            if (in_array($params['permission_type'], array('PLUGIN_GIT_READ', 'PLUGIN_GIT_WRITE', 'PLUGIN_GIT_WPLUS'))) {
                $params['object_type'] = 'git_repository';
            }
        }
    }
    function permission_get_object_name($params) {
        if (!$params['object_name']) {
            if (in_array($params['permission_type'], array('PLUGIN_GIT_READ', 'PLUGIN_GIT_WRITE', 'PLUGIN_GIT_WPLUS'))) {
                $repository = new GitRepository();
                $repository->setId($params['object_id']);
                try {
                    $repository->load();
                    $params['object_name'] = $repository->getName();
                } catch (Exception $e) {
                    // do nothing
                }
            }
        }
    }
    function permission_get_object_fullname($params) {
        if (!$params['object_fullname']) {
            if (in_array($params['permission_type'], array('PLUGIN_GIT_READ', 'PLUGIN_GIT_WRITE', 'PLUGIN_GIT_WPLUS'))) {
                $repository = new GitRepository();
                $repository->setId($params['object_id']);
                try {
                    $repository->load();
                    $params['object_name'] = 'git repository '. $repository->getName();
                } catch (Exception $e) {
                    // do nothing
                }
            }
        }
    }
    function permissions_for_ugroup($params) {
        if (!$params['results']) {
            if (in_array($params['permission_type'], array('PLUGIN_GIT_READ', 'PLUGIN_GIT_WRITE', 'PLUGIN_GIT_WPLUS'))) {
                $repository = new GitRepository();
                $repository->setId($params['object_id']);
                try {
                    $repository->load();
                    $params['results']  = $repository->getName();
                } catch (Exception $e) {
                    // do nothing
                }
            }
        }
    }

    var $_cached_permission_user_allowed_to_change;
    function permission_user_allowed_to_change($params) {
        if (!$params['allowed']) {
            $user = $this->getCurrentUser();
            $project = $this->getProjectManager()->getProject($params['group_id']);

            if ($this->getGitPermissionsManager()->userIsGitAdmin($user, $project)) {
                $this->_cached_permission_user_allowed_to_change = true;
            }

            if (! $this->_cached_permission_user_allowed_to_change) {
                if (in_array($params['permission_type'], array('PLUGIN_GIT_READ', 'PLUGIN_GIT_WRITE', 'PLUGIN_GIT_WPLUS'))) {
                    $repository = new GitRepository();
                    $repository->setId($params['object_id']);
                    try {
                        $repository->load();
                        //Only project admin can update perms of project repositories
                        //Only repo owner can update perms of personal repositories
                        $this->_cached_permission_user_allowed_to_change = $repository->belongsTo($user) || $this->getPermissionsManager()->userIsGitAdmin($user, $project);
                    } catch (Exception $e) {
                        // do nothing
                    }
                }
            }
            $params['allowed'] = $this->_cached_permission_user_allowed_to_change;
        }
    }

    public function proccess_system_check($params) {
        $gitgc = new Git_GitoliteHousekeeping_GitoliteHousekeepingGitGc(
            new Git_GitoliteHousekeeping_GitoliteHousekeepingDao(),
            $params['logger'],
            $this->getGitoliteAdminPath()
        );
        $gitolite_driver  = $this->getGitoliteDriver();

        $system_check = new Git_SystemCheck(
            $gitgc,
            $gitolite_driver,
            $this->getGitSystemEventManager(),
            new PluginConfigChecker($params['logger']),
            $this
        );

        $system_check->process();
    }

    public function getGitoliteDriver() {
        return new Git_GitoliteDriver(
            $this->getLogger(),
            $this->getGitSystemEventManager(),
            $this->getGitRepositoryUrlManager(),
            $this->getGitDao(),
            new Git_Mirror_MirrorDao,
            $this,
            null,
            null,
            null,
            null,
            $this->getProjectManager(),
            $this->getMirrorDataMapper()
        );
    }

    /**
     * When project is deleted all its git repositories are archived and marked as deleted
     *
     * @param Array $params Parameters contining project id
     *
     * @return void
     */
    public function project_is_deleted($params) {
        if (!empty($params['group_id'])) {
            $project = ProjectManager::instance()->getProject($params['group_id']);
            if ($project) {
                $repository_manager = $this->getRepositoryManager();
                $repository_manager->deleteProjectRepositories($project);
            }
        }
    }

    /**
     * Display git backend statistics in CSV format
     *
     * @param Array $params parameters of the event
     *
     * @return void
     */
    public function statistics_collector($params) {
        if (!empty($params['formatter'])) {
            include_once('GitBackend.class.php');
            $formatter  = $params['formatter'];
            $gitBackend = Backend::instance('Git','GitBackend', array($this->getGitRepositoryUrlManager()));
            echo $gitBackend->getBackendStatistics($formatter);
        }
    }

    /**
     * Add ci trigger information for Git service
     *
     * @param Array $params Hook parms
     *
     * @return Void
     */
    public function collect_ci_triggers($params) {
        $ci = new Git_Ci();
        $triggers = $ci->retrieveTriggers($params);
        $params['services'][] = $triggers;
    }

    /**
     * Save ci trigger for Git service
     *
     * @param Array $params Hook parms
     *
     * @return Void
     */
    public function save_ci_triggers($params) {
        if (isset($params['job_id']) && !empty($params['job_id']) && isset($params['request']) && !empty($params['request'])) {
            if ($params['request']->get('hudson_use_plugin_git_trigger_checkbox')) {
                $repositoryId = $params['request']->get('hudson_use_plugin_git_trigger');
                if ($repositoryId) {
                    $vRepoId = new Valid_Uint('hudson_use_plugin_git_trigger');
                    $vRepoId->required();
                    if($params['request']->valid($vRepoId)) {
                        $ci = new Git_Ci();
                        if (!$ci->saveTrigger($params['job_id'], $repositoryId)) {
                            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_trigger_not_saved'));
                        }
                    } else {
                        $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_bad_repo_id'));
                    }
                }
            }
        }
    }

    /**
     * Update ci trigger for Git service
     *
     * @param Array $params Hook parms
     *
     * @return Void
     */
    public function update_ci_triggers($params) {
        if (isset($params['request']) && !empty($params['request'])) {
            $jobId        = $params['request']->get('job_id');
            $repositoryId = $params['request']->get('hudson_use_plugin_git_trigger');
            if ($jobId) {
                $vJobId = new Valid_Uint('job_id');
                $vJobId->required();
                if($params['request']->valid($vJobId)) {
                    $ci = new Git_Ci();
                    $vRepoId = new Valid_Uint('hudson_use_plugin_git_trigger');
                    $vRepoId->required();
                    if ($params['request']->valid($vRepoId)) {
                        if (!$ci->saveTrigger($jobId, $repositoryId)) {
                            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_trigger_not_saved'));
                        }
                    } else {
                        if (!$ci->deleteTrigger($jobId)) {
                            $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_trigger_not_deleted'));
                        }
                    }
                } else {
                    $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_bad_repo_id'));
                }
            }
        }
    }

    /**
     * Delete ci trigger for Git service
     *
     * @param Array $params Hook parms
     *
     * @return Void
     */
    public function delete_ci_triggers($params) {
        if (isset($params['job_id']) && !empty($params['job_id'])) {
            $ci = new Git_Ci();
            if (!$ci->deleteTrigger($params['job_id'])) {
                $GLOBALS['Response']->addFeedback('error', $GLOBALS['Language']->getText('plugin_git','ci_trigger_not_deleted'));
            }
        }
    }

    /**
     * Add log access for git pushs
     *
     * @param Array $params parameters of the event
     *
     * @return Void
     */
    function logsDaily($params) {
        $pm      = ProjectManager::instance();
        $project = $pm->getProject($params['group_id']);
        if ($project->usesService(GitPlugin::SERVICE_SHORTNAME)) {
            $controler = $this->getGitController();
            $controler->logsDaily($params);
        }
    }

    /**
     * Instanciate the corresponding widget
     *
     * @param Array $params Name and instance of the widget
     *
     * @return Void
     */
    function myPageBox($params) {
        switch ($params['widget']) {
            case 'plugin_git_user_pushes':
                $params['instance'] = new Git_Widget_UserPushes($this->getPluginPath());
                break;
            case 'plugin_git_project_pushes':
                $params['instance'] = new Git_Widget_ProjectPushes($this->getPluginPath());
                break;
            default:
                break;
        }
    }

    public function project_admin_remove_user_from_project_ugroups($params) {
        foreach ($params['ugroups'] as $ugroup_id) {
            $this->project_admin_ugroup_remove_user(
                array(
                    'group_id'  => $params['group_id'],
                    'user_id'   => $params['user_id'],
                    'ugroup_id' => $ugroup_id,
                )
            );
        }
    }

    public function project_admin_change_user_permissions($params) {
        if ($params['user_permissions']['admin_flags'] == 'A') {
            $params['ugroup_id'] = ProjectUGroup::PROJECT_ADMIN;
            $this->project_admin_ugroup_add_user($params);
        } else {
            $params['ugroup_id'] = ProjectUGroup::PROJECT_ADMIN;
            $this->project_admin_ugroup_remove_user($params);
        }
    }

    public function project_admin_ugroup_deletion($params) {
        $ugroup     = $params['ugroup'];
        $users      = $ugroup->getMembers();
        $project_id = $params['group_id'];

        foreach ($users as $user) {
            $calling = array(
                'group_id' => $project_id,
                'user_id'  => $user->getId(),
                'ugroup'   => $ugroup
            );
            $this->project_admin_ugroup_remove_user($calling);
        }

        $this->getFineGrainedUpdater()->deleteUgroupPermissions($ugroup, $project_id);
        $this->getUgroupsToNotifyDao()->deleteByUgroupId($project_id, $ugroup->getId());

        $this->getGitSystemEventManager()->queueProjectsConfigurationUpdate(array($project_id));
    }

    public function project_admin_add_user($params) {
        $params['ugroup_id'] = ProjectUGroup::PROJECT_MEMBERS;
        $this->project_admin_ugroup_add_user($params);
    }

    public function project_admin_remove_user($params) {
        $params['ugroup_id'] = ProjectUGroup::PROJECT_MEMBERS;
        $this->project_admin_ugroup_remove_user($params);
        $this->projectRemoveUserFromNotification($params);
    }

    public function project_admin_ugroup_add_user($params) {
        $this->getGerritMembershipManager()->addUserToGroup(
            $this->getUserFromParams($params),
            $this->getUGroupFromParams($params)
        );
    }

    public function project_admin_ugroup_remove_user($params) {
        $this->getGerritMembershipManager()->removeUserFromGroup(
            $this->getUserFromParams($params),
            $this->getUGroupFromParams($params)
        );
    }

    public function project_admin_ugroup_creation($params) {
        $this->getGerritMembershipManager()->createGroupOnProjectsServers(
            $this->getUGroupFromParams($params)
        );
    }

    public function project_admin_parent_project_modification($params) {
        try {
            $project        = ProjectManager::instance()->getProject($params['group_id']);
            $gerrit_servers = $this->getGerritServerFactory()->getServersForProject($project);

            $this->getGerritUmbrellaProjectManager()->recursivelyCreateUmbrellaProjects($gerrit_servers, $project);
        } catch (Git_Driver_Gerrit_Exception $exception) {
            $GLOBALS['Response']->addFeedback(Feedback::ERROR, $GLOBALS['Language']->getText('plugin_git', 'gerrit_remote_exception', $exception->getMessage()));
        } catch (Exception $exception) {
            $GLOBALS['Response']->addFeedback(Feedback::ERROR, $exception->getMessage());
        }
    }

    public function ugroup_manager_update_ugroup_binding_add($params) {
        $this->getGerritMembershipManager()->addUGroupBinding(
            $params['ugroup'],
            $params['source']
        );
    }

    public function ugroup_manager_update_ugroup_binding_remove($params) {
        $this->getGerritMembershipManager()->removeUGroupBinding(
            $params['ugroup']
        );
    }

    private function getUserFromParams(array $params) {
        return UserManager::instance()->getUserById($params['user_id']);
    }


    private function getUGroupFromParams(array $params) {
        if (isset($params['ugroup'])) {
            return $params['ugroup'];
        } else {
            $project = ProjectManager::instance()->getProject($params['group_id']);
            return $this->getUGroupManager()->getUGroup($project, $params['ugroup_id']);
        }
    }

    /**
     * List plugin's widgets in customize menu
     *
     * @param Array $params List of widgets
     *
     * @return Void
     */
    function widgets($params) {
        require_once('common/widget/WidgetLayoutManager.class.php');
        if ($params['owner_type'] == WidgetLayoutManager::OWNER_TYPE_USER) {
            $params['codendi_widgets'][] = 'plugin_git_user_pushes';
        }
        $request = HTTPRequest::instance();
        $groupId = $request->get('group_id');
        $pm      = ProjectManager::instance();
        $project = $pm->getProject($groupId);
        if ($project->usesService(GitPlugin::SERVICE_SHORTNAME)) {
            if ($params['owner_type'] == WidgetLayoutManager::OWNER_TYPE_GROUP) {
                $params['codendi_widgets'][] = 'plugin_git_project_pushes';
            }
        }
    }

    private function getProjectCreator() {
        $tmp_dir = ForgeConfig::get('tmp_dir') .'/gerrit_'. uniqid();
        return new Git_Driver_Gerrit_ProjectCreator(
            $tmp_dir,
            $this->getGerritDriverFactory(),
            $this->getGerritUserFinder(),
            $this->getUGroupManager(),
            $this->getGerritMembershipManager(),
            $this->getGerritUmbrellaProjectManager(),
            $this->getTemplateFactory(),
            $this->getTemplateProcessor()
        );
    }

    private function getTemplateProcessor() {
        return new Git_Driver_Gerrit_Template_TemplateProcessor();
    }

    private function getGerritUmbrellaProjectManager() {
        return new Git_Driver_Gerrit_UmbrellaProjectManager(
            $this->getUGroupManager(),
            $this->getProjectManager(),
            $this->getGerritMembershipManager(),
            $this->getGerritDriverFactory()
        );
    }

    private function getProjectManager() {
        return ProjectManager::instance();
    }

    private function getGerritUserFinder() {
        return new Git_Driver_Gerrit_UserFinder(PermissionsManager::instance(), $this->getUGroupManager());
    }

    private function getProjectCreatorStatus() {
        $dao = new Git_Driver_Gerrit_ProjectCreatorStatusDao();

        return new Git_Driver_Gerrit_ProjectCreatorStatus($dao);
    }

    private function getDescriptionUpdater()
    {
        return new DescriptionUpdater(new ProjectHistoryDao(), $this->getGitSystemEventManager());
    }

    private function getGitController() {
        $gerrit_server_factory = $this->getGerritServerFactory();
        return new Git(
            $this,
            $this->getGerritServerFactory(),
            $this->getGerritDriverFactory(),
            $this->getRepositoryManager(),
            $this->getGitSystemEventManager(),
            new Git_Driver_Gerrit_UserAccountManager($this->getGerritDriverFactory(), $gerrit_server_factory),
            $this->getRepositoryFactory(),
            UserManager::instance(),
            ProjectManager::instance(),
            PluginManager::instance(),
            HTTPRequest::instance(),
            $this->getProjectCreator(),
            new Git_Driver_Gerrit_Template_TemplateFactory(new Git_Driver_Gerrit_Template_TemplateDao()),
            $this->getGitPermissionsManager(),
            $this->getGitRepositoryUrlManager(),
            $this->getLogger(),
            $this->getBackendGitolite(),
            $this->getMirrorDataMapper(),
            $this->getProjectCreatorStatus(),
            new GerritCanMigrateChecker(EventManager::instance(), $gerrit_server_factory),
            new WebhookDao(),
            $this->getFineGrainedUpdater(),
            $this->getFineGrainedFactory(),
            $this->getFineGrainedRetriever(),
            $this->getFineGrainedPermissionSaver(),
            $this->getDefaultFineGrainedPermissionFactory(),
            new CITokenManager(new CITokenDao()),
            $this->getFineGrainedPermissionDestructor(),
            $this->getFineGrainedRepresentationBuilder(),
            $this->getFineGrainedPermissionReplicator(),
            $this->getHistoryValueFormatter(),
            $this->getPermissionChangesDetector(),
            $this->getTemplatePermissionsUpdater(),
            new ProjectHistoryDao(),
            $this->getDescriptionUpdater(),
            $this->getGitPhpAccessLogger(),
            new VersionDetector(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getRegexpFineGrainedEnabler(),
            $this->getRegexpFineGrainedDisabler(),
            $this->getRegexpPermissionFilter(),
            new UsersToNotifyDao(),
            $this->getUgroupsToNotifyDao(),
            new UGroupManager()
        );
    }

    /**
     * @return UgroupsToNotifyDao
     */
    private function getUgroupsToNotifyDao()
    {
        return new UgroupsToNotifyDao();
    }

    /**
     * @return UsersToNotifyDao
     */
    private function getUsersToNotifyDao()
    {
        return new UsersToNotifyDao();
    }

    private function getRegexpPermissionFilter()
    {
        return new RegexpPermissionFilter(
            $this->getFineGrainedFactory(),
            $this->getPatternValidator(),
            $this->getFineGrainedPermissionDestructor(),
            $this->getDefaultFineGrainedPermissionFactory()
        );
    }

    private function getRegexpFineGrainedDisabler()
    {
        return new RegexpFineGrainedDisabler(
            new RegexpRepositoryDao(),
            new RegexpFineGrainedDao(),
            new RegexpTemplateDao()
        );
    }

    private function getTemplatePermissionsUpdater()
    {
        return new TemplatePermissionsUpdater(
            $this->getPermissionsManager(),
            new ProjectHistoryDao(),
            $this->getHistoryValueFormatter(),
            $this->getFineGrainedRetriever(),
            $this->getDefaultFineGrainedPermissionFactory(),
            $this->getFineGrainedUpdater(),
            $this->getTemplateFineGrainedPermissionSaver(),
            $this->getPermissionChangesDetector(),
            $this->getRegexpFineGrainedEnabler(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getRegexpPermissionFilter(),
            $this->getRegexpFineGrainedDisabler()
        );
    }

    private function getPermissionChangesDetector()
    {
        return new PermissionChangesDetector(
            $this->getGitPermissionsManager(),
            $this->getFineGrainedRetriever()
        );
    }

    private function getFineGrainedPermissionReplicator()
    {
        $dao             = $this->getFineGrainedDao();
        $default_factory = $this->getDefaultFineGrainedPermissionFactory();
        $saver           = $this->getFineGrainedPermissionSaver();
        $factory         = $this->getFineGrainedFactory();

        return new FineGrainedPermissionReplicator(
            $dao,
            $default_factory,
            $saver,
            $factory,
            $this->getRegexpFineGrainedEnabler(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getPatternValidator()
        );
    }

    private function getFineGrainedRepresentationBuilder()
    {
        $user_group_factory  = new User_ForgeUserGroupFactory(new UserGroupDao());
        $permissions_manager = $this->getPermissionsManager();
        $option_builder      = new AccessRightsPresenterOptionsBuilder($user_group_factory, $permissions_manager);

        return new FineGrainedRepresentationBuilder($option_builder);
    }

    private function getFineGrainedDao()
    {
        return new FineGrainedDao();
    }

    private function getFineGrainedPermissionDestructor()
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedPermissionDestructor($dao);
    }

    private function getTemplateFineGrainedPermissionSaver()
    {
        $dao = $this->getFineGrainedDao();
        return new TemplateFineGrainedPermissionSaver($dao);
    }

    private function getDefaultFineGrainedPermissionFactory()
    {
        $dao = $this->getFineGrainedDao();
        return new DefaultFineGrainedPermissionFactory(
            $dao,
            $this->getUGroupManager(),
            new PermissionsNormalizer(),
            $this->getPermissionsManager(),
            $this->getPatternValidator(),
            $this->getFineGrainedPermissionSorter(),
            $this->getRegexpFineGrainedRetriever()
        );
    }

    private function getFineGrainedPermissionSorter()
    {
        return new FineGrainedPermissionSorter();
    }

    /**
     * @return FineGrainedPermissionSaver
     */
    private function getFineGrainedPermissionSaver()
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedPermissionSaver($dao);
    }

    /**
     * @return FineGrainedUpdater
     */
    private function getFineGrainedUpdater()
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedUpdater($dao);
    }

    /**
     * @return FineGrainedRetriever
     */
    public function getFineGrainedRetriever()
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedRetriever($dao);
    }

    /**
     * @return FineGrainedPermissionFactory
     */
    public function getFineGrainedFactory()
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedPermissionFactory(
            $dao,
            $this->getUGroupManager(),
            new PermissionsNormalizer(),
            $this->getPermissionsManager(),
            $this->getPatternValidator(),
            $this->getFineGrainedPermissionSorter(),
            new XmlUgroupRetriever(
                $this->getLogger(),
                $this->getUGroupManager()
            )
        );
    }

    /**
     * @return FineGrainedPermissionFactory
     */
    private function getFineGrainedFactoryWithLogger(Logger $logger)
    {
        $dao = $this->getFineGrainedDao();
        return new FineGrainedPermissionFactory(
            $dao,
            $this->getUGroupManager(),
            new PermissionsNormalizer(),
            $this->getPermissionsManager(),
            $this->getPatternValidator(),
            $this->getFineGrainedPermissionSorter(),
            new XmlUgroupRetriever(
                $logger,
                $this->getUGroupManager()
            )
        );
    }

    private function getPatternValidator()
    {
        return new PatternValidator(
            $this->getFineGrainedPatternValidator(),
            $this->getRegexpPatternValidator(),
            $this->getRegexpFineGrainedRetriever()
        );
    }

    private function getFineGrainedPatternValidator()
    {
        return new FineGrainedPatternValidator();
    }

    private function getRegexpPatternValidator()
    {
        return new FineGrainedRegexpValidator();
    }

    private function getHistoryValueFormatter()
    {
        return new HistoryValueFormatter(
            $this->getPermissionsManager(),
            $this->getUGroupManager(),
            $this->getFineGrainedRetriever(),
            $this->getDefaultFineGrainedPermissionFactory(),
            $this->getFineGrainedFactory()
        );
    }

    public function getGitSystemEventManager() {
        return new Git_SystemEventManager(SystemEventManager::instance(), $this->getRepositoryFactory());
    }

    /**
     * @return GitRepositoryManager
     */
    private function getRepositoryManager() {
        return new GitRepositoryManager(
            $this->getRepositoryFactory(),
            $this->getGitSystemEventManager(),
            $this->getGitDao(),
            $this->getConfigurationParameter('git_backup_dir'),
            new GitRepositoryMirrorUpdater($this->getMirrorDataMapper(), new ProjectHistoryDao()),
            $this->getMirrorDataMapper(),
            $this->getFineGrainedPermissionReplicator(),
            new ProjectHistoryDao(),
            $this->getHistoryValueFormatter()
        );
    }

    public function getRepositoryFactory() {
        return new GitRepositoryFactory($this->getGitDao(), ProjectManager::instance());
    }

    private function getGitDao() {
        return new GitDao();
    }

    /**
     * @return Git_Driver_Gerrit_GerritDriverFactory
     */
    private function getGerritDriverFactory() {
        return new Git_Driver_Gerrit_GerritDriverFactory($this->getLogger());
    }

    private function getPermissionsManager() {
        return PermissionsManager::instance();
    }

    private function getGitPermissionsManager() {
        return new GitPermissionsManager(
            new Git_PermissionsDao(),
            $this->getGitSystemEventManager(),
            $this->getFineGrainedDao(),
            $this->getFineGrainedRetriever()
        );
    }

    /**
     *
     * @return Logger
     */
    public function getLogger() {
        if (!$this->logger) {
            $this->logger = new GitBackendLogger();
        }

        return $this->logger;
    }

    /**
     *
     * @param Logger $logger
     */
    public function setLogger(Logger $logger) {
        $this->logger = $logger;
    }

    private function getGerritMembershipManager() {
        return new Git_Driver_Gerrit_MembershipManager(
            new Git_Driver_Gerrit_MembershipDao(),
            $this->getGerritDriverFactory(),
            new Git_Driver_Gerrit_UserAccountManager($this->getGerritDriverFactory(), $this->getGerritServerFactory()),
            $this->getGerritServerFactory(),
            $this->getLogger(),
            $this->getUGroupManager(),
            $this->getProjectManager()
        );
    }

    protected function getGerritServerFactory() {
        return new Git_RemoteServer_GerritServerFactory(
            new Git_RemoteServer_Dao(),
            $this->getGitDao(),
            $this->getGitSystemEventManager(),
            $this->getProjectManager()
        );
    }

    private function getGitoliteAdminPath() {
        return $GLOBALS['sys_data_dir'] . '/gitolite/admin';
    }

    private function getUGroupManager() {
        return new UGroupManager();
    }

    /**
     * @param array $params
     * Parameters:
     *     'user' => PFUser
     *     'html' => string
     */
    public function manage_third_party_apps($params) {
        $this->resynch_gerrit_groups_with_user($params);
    }

    /**
     * @param array $params
     * Parameters:
     *     'user' => PFUser
     *     'html' => string
     */
    private function resynch_gerrit_groups_with_user($params) {
        if (! $this->getGerritServerFactory()->hasRemotesSetUp()) {
            return;
        }

        $renderer = TemplateRendererFactory::build()->getRenderer(dirname(GIT_BASE_DIR).'/templates');
        $presenter = new GitPresenters_GerritAsThirdPartyPresenter();
        $params['html'] .= $renderer->renderToString('gerrit_as_third_party', $presenter);

        $request = HTTPRequest::instance();
        $action = $request->get('action');
        if ($action && $action = $presenter->form_action) {
            $this->addMissingGerritAccess($params['user']);
        }
    }

    private function addMissingGerritAccess($user) {
        $this->getGerritMembershipManager()->addUserToAllTheirGroups($user);
    }

    /**
     * @see Event::USER_RENAME
     */
    public function systemevent_user_rename($params) {
        $this->getGitSystemEventManager()->queueUserRenameUpdate($params['old_user_name'], $params['user']);
    }

    public function register_project_creation($params)
    {
        $this->getPermissionsManager()->duplicateWithStaticMapping(
                $params['template_id'],
                $params['group_id'],
                array(Git::PERM_ADMIN, Git::DEFAULT_PERM_READ, Git::DEFAULT_PERM_WRITE, Git::DEFAULT_PERM_WPLUS),
                $params['ugroupsMapping']
        );

        $this->getDefaultFineGrainedPermissionReplicator()->replicate(
            $this->getProjectManager()->getProject($params['template_id']),
            $params['group_id'],
            $params['ugroupsMapping']
        );

        $this->getMirrorDataMapper()->duplicate($params['template_id'], $params['group_id']);
    }

    private function getDefaultFineGrainedPermissionReplicator()
    {
        return new DefaultFineGrainedPermissionReplicator(
            new FineGrainedDao(),
            $this->getDefaultFineGrainedPermissionFactory(),
            $this->getTemplateFineGrainedPermissionSaver(),
            $this->getRegexpFineGrainedEnabler(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getPatternValidator()
        );
    }

    /** @see Event::GET_PROJECTID_FROM_URL */
    public function get_projectid_from_url($params) {
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0) {
            $url = new Git_URL(
                ProjectManager::instance(),
                $this->getRepositoryFactory(),
                $_SERVER['REQUEST_URI']
            );
            if ($url->isSmartHTTP()) {
                return;
            }

            $project = $url->getProject();
            if ($project && ! $project->isError()) {
                $params['project_id'] = $url->getProject()->getId();
            }
        }
    }

    public function anonymous_access_to_script_allowed($params) {
        if (strpos($_SERVER['REQUEST_URI'], $this->getPluginPath()) === 0) {
            $url = new Git_URL(
                ProjectManager::instance(),
                $this->getRepositoryFactory(),
                $_SERVER['REQUEST_URI']
            );
            if ($url->isSmartHTTP()) {
                $params['anonymous_allowed'] = true;
            }
        }
    }

    /**
     * @return boolean true if friendly URLs have been activated
     */
    public function areFriendlyUrlsActivated() {
        return (bool) $this->getConfigurationParameter('git_use_friendly_urls');
    }

    /**
     * @return Git_GitRepositoryUrlManager
     */
    private function getGitRepositoryUrlManager() {
        return new Git_GitRepositoryUrlManager($this);
    }

    /**
     * @return Git_Mirror_ManifestManager
     */
    public function getManifestManager() {
        return new Git_Mirror_ManifestManager(
            $this->getMirrorDataMapper(),
            new Git_Mirror_ManifestFileGenerator(
                $this->getLogger(),
                ForgeConfig::get('sys_data_dir').'/gitolite/grokmirror'
            )
        );
    }

    /**
     * @return \Tuleap\Git\Gitolite\SSHKey\Dumper
     */
    private function getSSHKeyDumper()
    {
        $factory = $this->getSSHKeyDumperFactory();
        return $factory->buildDumper();
    }

    /**
     * @return \Tuleap\Git\Gitolite\SSHKey\MassDumper
     */
    private function getSSHKeyMassDumper()
    {
        $factory = $this->getSSHKeyDumperFactory();
        return $factory->buildMassDumper();
    }

    /**
     * @return DumperFactory
     */
    private function getSSHKeyDumperFactory()
    {
        $user_manager = UserManager::instance();

        $whole_instance_keys = new WholeInstanceKeysAggregator(
            new GitoliteAdmin(),
            new GerritServer(new Git_RemoteServer_Dao()),
            new User($user_manager)
        );

        $gitolite_admin_path = $this->getGitoliteAdminPath();
        $git_exec            = new Git_Exec($gitolite_admin_path);

        $system_command = new System_Command();

        return new DumperFactory(
            $this->getManagementDetector(),
            new AuthorizedKeysFileCreator($whole_instance_keys, $system_command, $this->getLogger()),
            $system_command,
            $git_exec,
            $gitolite_admin_path,
            $user_manager
        );
    }

    /**
     * @return ManagementDetector
     */
    private function getManagementDetector()
    {
        return new ManagementDetector(
            new VersionDetector(),
            new GlobalParameterDao(),
            SystemEventManager::instance()
        );
    }

    public function fill_project_history_sub_events($params) {
        array_push(
            $params['subEvents']['event_others'],
            'git_repo_create',
            'git_repo_delete',
            'git_repo_update',
            'git_repo_mirroring_update',
            'git_repo_to_gerrit',
            'git_create_template',
            'git_delete_template',
            'git_disconnect_gerrit_delete',
            'git_disconnect_gerrit_read_only',
            'git_admin_groups',
            'git_fork_repositories'
        );
    }

    /**
     * @see Event::POST_EVENTS_ACTIONS
     */
    public function post_system_events_actions($params) {
        if (! $this->pluginIsConcerned($params)) {
            return;
        }

        $this->getLogger()->info('Processing git post system events actions');

        $executed_events_ids = $params['executed_events_ids'];

        $this->getGitoliteDriver()->commit('Modifications from events ' . implode(',', $executed_events_ids));
        $this->getGitoliteDriver()->push();
    }

    private function pluginIsConcerned($params) {
        return $params['queue_name'] == "git"
            && is_array($params['executed_events_ids'])
            && count($params['executed_events_ids']) > 0;
    }

    public function getRESTRepositoryRepresentationBuilder($version) {
        $class  = "Tuleap\\Git\\REST\\".$version."\\RepositoryRepresentationBuilder";
        return new $class(
            $this->getGitPermissionsManager(),
            $this->getGerritServerFactory()
        );
    }

    public function rest_project_get_git($params) {
        $class            = "Tuleap\\Git\\REST\\".$params['version']."\\ProjectResource";
        $project_resource = new $class($this->getRepositoryFactory(), $this->getRESTRepositoryRepresentationBuilder($params['version']));
        $project          = $params['project'];

        $params['result'] = $project_resource->getGit(
            $project,
            $this->getCurrentUser(),
            $params['limit'],
            $params['offset'],
            $params['fields']
        );

        $params['total_git_repo'] = count($this->getRepositoryFactory()->getAllRepositories($project));
    }

    public function rest_project_options_git($params) {
        $params['activated'] = true;
    }

    /**
     * @see Event::REST_PROJECT_RESOURCES
     */
    public function rest_project_resources(array $params) {
        $injector = new Git_REST_ResourcesInjector();
        $injector->declareProjectPlanningResource($params['resources'], $params['project']);
    }

    /**
     * @see REST_RESOURCES
     */
    public function rest_resources($params) {
        $injector = new Git_REST_ResourcesInjector();
        $injector->populate($params['restler']);
    }

    /**
     * @return PFUser
     */
    private function getCurrentUser() {
        return UserManager::instance()->getCurrentUser();
    }

    /**
     * Hook to list archived repositories for restore in site admin page
     *
     * @param array $params
     */
    public function showArchivedRepositories($params) {
        $group_id              = $params['group_id'];
        $archived_repositories = $this->getRepositoryManager()->getRepositoriesForRestoreByProjectId($group_id);
        $tab_content           = '';

        $tab_content .= '<section class="tlp-pane">
        <div class="tlp-pane-container">
            <div class="tlp-pane-header">
                <h1 class="tlp-pane-title">'. $GLOBALS['Language']->getText('plugin_git', 'archived_repositories') .'</h1>
            </div>
            <section class="tlp-pane-section">
                <table class="tlp-table">
                    <thead>
                        <tr>
                            <th>'.$GLOBALS['Language']->getText('plugin_git', 'archived_repositories_name').'</th>
                            <th>'.$GLOBALS['Language']->getText('plugin_git', 'archived_repositories_date').'</th>
                            <th>'.$GLOBALS['Language']->getText('plugin_git', 'archived_repositories_creator').'</th>
                            <th>'.$GLOBALS['Language']->getText('plugin_git', 'archived_repositories_date_delete').'</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>';
        if (count($archived_repositories)) {
            foreach($archived_repositories as $archived_repository) {
                $tab_content .= '<tr>';
                $tab_content .= '<td>'.$archived_repository->getName().'</td>';
                $tab_content .= '<td>'.$archived_repository->getCreationDate().'</td>';
                $tab_content .= '<td>'.$archived_repository->getCreator()->getName().'</td>';
                $tab_content .= '<td>'.$archived_repository->getDeletionDate().'</td>';
                $tab_content .= '<td class="tlp-table-cell-actions">
                    <a href="/plugins/git/?action=restore&group_id='.$group_id.'&repo_id='.$archived_repository->getId().'"
                        class="tlp-table-cell-actions-button tlp-button-small tlp-button-outline tlp-button-primary"
                        onClick="return confirm(\''.$GLOBALS['Language']->getText('plugin_git', 'restore_confirmation').'\')"
                    >
                        <i class="fa fa-repeat tlp-button-icon"></i> '.$GLOBALS['Language']->getText('plugin_git', 'archived_repositories_restore').'
                    </a>
                </td>';
                $tab_content .= '</tr>';
            }
        } else {
            $tab_content .= '<tr>
                <td class="tlp-table-cell-empty" colspan="5">
                    '. $GLOBALS['Language']->getText('plugin_git', 'restore_no_repo_found') .'
                </td>
            </tr>';
        }
        $tab_content .= '</tbody>
                    </table>
                </section>
            </div>
        </section>';
        $params['html'][]= $tab_content;
    }

    public function is_script_handled_for_restricted($params) {
        $uri = $params['uri'];
        if (strpos($uri, $this->getPluginPath()) === 0) {
            $params['allow_restricted'] = true;
        }
    }

    public function get_services_allowed_for_restricted($params) {
        $params['allowed_services'][] = $this->getServiceShortname();
    }

    /**
     * @see Event::PROJECT_ACCESS_CHANGE
     * @param type $params
     */
    public function project_access_change($params)
    {
        $project = ProjectManager::instance()->getProject($params['project_id']);

        $this->getGitPermissionsManager()->updateProjectAccess($project, $params['old_access'], $params['access']);

        $updater = $this->getUgroupToNotifyUpdater();
        $updater->updateProjectAccess($project, $params['old_access'], $params['access']);
    }

    /**
     * @see Event::SITE_ACCESS_CHANGE
     * @param array $params
     */
    public function site_access_change(array $params)
    {
        $this->getGitPermissionsManager()->updateSiteAccess($params['old_value'], $params['new_value']);

        $updater = $this->getUgroupToNotifyUpdater();
        $updater->updateSiteAccess($params['old_value']);
    }

    /**
     * @return UgroupToNotifyUpdater
     */
    private function getUgroupToNotifyUpdater()
    {
        return new UgroupToNotifyUpdater($this->getUgroupsToNotifyDao());
    }

    /**
     * @param PFUser user
     */
    public function ldap_daily_synchro_update_user(PFUser $user) {
        if ($user->getStatus() == PFUser::STATUS_SUSPENDED) {
            $factory = $this->getGerritServerFactory();
            $gerrit_servers = $factory->getServers();
            $gerritDriverFactory = new Git_Driver_Gerrit_GerritDriverFactory ($this->getLogger());
            foreach($gerrit_servers as $server) {
                $gerritDriver = $gerritDriverFactory->getDriver($server);
                $gerritDriver->setUserAccountInactive($server, $user);
            }
        }
    }

    /** @see Event::SERVICES_TRUNCATED_EMAILS */
    public function services_truncated_emails(array $params) {
        $project = $params['project'];
        if ($project->usesService($this->getServiceShortname())) {
            $params['services'][] = $GLOBALS['Language']->getText('plugin_git', 'service_lbl_key');
        }
    }


    /**
     *
     * @param array $params
     * @see Event::IMPORT_XML_PROJECT
     */
    public function importXmlProject($params) {
        $logger = new WrapperLogger($params['logger'], "GitXmlImporter");

        $importer = new GitXmlImporter(
            $logger,
            $this->getRepositoryManager(),
            $this->getRepositoryFactory(),
            $this->getBackendGitolite(),
            new XML_RNGValidator(),
            new System_Command(),
            $this->getGitSystemEventManager(),
            PermissionsManager::instance(),
            EventManager::instance(),
            $this->getFineGrainedUpdater(),
            $this->getRegexpFineGrainedRetriever(),
            $this->getRegexpFineGrainedEnabler(),
            $this->getFineGrainedFactoryWithLogger($logger),
            $this->getFineGrainedPermissionSaver(),
            new XmlUgroupRetriever(
                $logger,
                $this->getUGroupManager()
            )
        );

        $importer->import(
            $params['configuration'],
            $params['project'],
            UserManager::instance()->getCurrentUser(),
            $params['xml_content'],
            $params['extraction_path']
        );
    }

    /**
     * @return GitPhpAccessLogger
     */
    private function getGitPhpAccessLogger()
    {
        $dao = new HistoryDao();

        return new GitPhpAccessLogger($dao);
    }

    public function codendi_daily_start()
    {
        $detector = new VersionDetector();
        if ($detector->isGitolite3()) {
            SystemEventManager::instance()->createEvent(
                ParseGitolite3Logs::NAME,
                null,
                SystemEvent::PRIORITY_LOW,
                SystemEvent::OWNER_ROOT,
                '\\Tuleap\\Git\\Events\\ParseGitolite3Logs'
            );
        }

        $this->getRepositoryManager()->purgeArchivedRepositories($this->getLogger());
    }

    private function getGitolite3Parser()
    {
        return new Gitolite3LogParser(
            new GitBackendLogger(),
            new System_Command(),
            new HttpUserValidator(),
            new HistoryDao(),
            $this->getRepositoryFactory(),
            UserManager::instance(),
            new GitoliteFileLogsDao()
        );
    }
}
