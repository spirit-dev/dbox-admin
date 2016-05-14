<?php

namespace SpiritDev\Bundle\DBoxAdminBundle\Processor;

use Doctrine\ORM\Query;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\ContinuousIntegration;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Project;
use SpiritDev\Bundle\DBoxPortalBundle\Mailer\Mailer;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use SpiritDev\Bundle\DBoxUserBundle\Entity\User;
use SpiritDev\Bundle\DBoxUserBundle\Security\Random\Randomize as Randomizer;

/**
 * Class ProcessorCore
 * @package SpiritDev\Bundle\DBoxAdminBundle\Processor
 */
abstract class ProcessorCore {

    /**
     * @var \Doctrine\Common\Persistence\ObjectManager|\Doctrine\ORM\EntityManager|object
     */
    protected $em;
    /**
     * @var ContainerInterface
     */
    protected $container;
    /**
     * @var RouterInterface
     */
    protected $router;
    /**
     * @var Session
     */
    protected $session;
    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var \SpiritDev\Bundle\DBoxPortalBundle\API\GitLabAPI
     */
    protected $gitlabApi;
    /**
     * @var \SpiritDev\Bundle\DBoxPortalBundle\API\RedmineAPI
     */
    protected $redmineApi;
    /**
     * @var \SpiritDev\Bundle\DBoxPortalBundle\API\SonarAPI
     */
    protected $sonarApi;
    /**
     * @var \SpiritDev\Bundle\DBoxPortalBundle\API\JenkinsAPI
     */
    protected $jenkinsApi;
    /**
     * @var Mailer
     */
    protected $mailer;
    /**
     * @var \SpiritDev\Bundle\DBoxUserBundle\Ldap\LdapDriver
     */
    protected $ldapDriver;

    protected $ciBaseUrl;

    /**
     * ProcessorCore constructor.
     * @param ContainerInterface $container
     * @param RouterInterface $router
     * @param Session $session
     * @param EngineInterface $templating
     */
    public function __construct(ContainerInterface $container,
                                RouterInterface $router,
                                Session $session,
                                EngineInterface $templating
    ) {
        $this->container = $container;
        $this->router = $router;
        $this->session = $session;
        $this->templating = $templating;
        $this->em = $this->container->get('doctrine')->getEntityManager();

        $this->gitlabApi = $this->container->get('spirit_dev_dbox_portal_bundle.api.gitlab');
        $this->mailer = $this->container->get('spirit_dev_dbox_portal_bundle.mailer');
        $this->ldapDriver = $this->container->get('spirit_dev_dbox_user_bundle.ldap.ldap_driver');
        $this->redmineApi = $this->container->get('spirit_dev_dbox_portal_bundle.api.redmine');
        $this->sonarApi = $this->container->get('spirit_dev_dbox_portal_bundle.api.sonar');
        $this->jenkinsApi = $this->container->get('spirit_dev_dbox_portal_bundle.api.jenkins');

        $this->ciBaseUrl = $this->container->getParameter('jenkins_api')['protocol'] . $this->container->getParameter('jenkins_api')['url'];
    }

    /**
     * @param Demand $demandToProcess
     * @return bool|null
     */
    protected function processLdapUserRegister(Demand $demandToProcess) {
        $demandToProcessContent = $demandToProcess->getContent();

        // Defining username
        // Concatenate firstname (char1) + lastname
        $username = substr($demandToProcessContent["firstname"], 0, 1) . $demandToProcessContent["lastname"];
        // Low case all
        $username = strtolower($username);

        // Defining userDN
        $userDn = 'uid=' . $username . ', ou=people, ' . $this->container->getParameter('ldap_driver')['user']['basedn'];
        $userDn = str_replace(" ", "", $userDn);

        // Generating encoded SSHA Password
        $salt = base_convert(sha1(uniqid(mt_rand(), true)), 16, 36);
        $plainpasswd = Randomizer::generateRandomString(8);
        $encodedPassword = '{SSHA}' . base64_encode(sha1($plainpasswd . $salt, TRUE) . $salt);

        // LDAP Encoded infos
        $newUserInfo['uid'] = $username;
        $newUserInfo['preferredLanguage'] = "fr_FR";
        $newUserInfo['gosaMailServer'] = "localhost";
        $newUserInfo['gosaVacationStart'] = (int)"1446559098";
        $newUserInfo['gosaVacationStop'] = (int)"1446559098";
        $newUserInfo['gosaMailDeliveryMode'] = ['none'];
        $newUserInfo['objectClass'][0] = "inetOrgPerson";
        $newUserInfo['objectClass'][1] = "organizationalPerson";
        $newUserInfo['objectClass'][2] = "person";
        $newUserInfo['objectClass'][3] = "gosaMailAccount";
        $newUserInfo['mail'] = $demandToProcessContent["user_mail"];
        $newUserInfo['givenName'] = ucfirst($demandToProcessContent["firstname"]);
        $newUserInfo['sn'] = ucfirst($demandToProcessContent["lastname"]);
        $newUserInfo['cn'] = ucfirst($demandToProcessContent["firstname"]) . " " . ucfirst($demandToProcessContent["lastname"]);
        $newUserInfo['userPassword'] = $encodedPassword;

        // Applying LDAP registration
        $ldapCreateUserIssue = $this->ldapDriver->ldapCreateUser($userDn, $newUserInfo);

        // If OK
        if ($ldapCreateUserIssue) {
            // Add some information to the array
            $newUserInfo['created'] = true;
            $newUserInfo['userDn'] = $userDn;
            $newUserInfo['clear_password'] = $plainpasswd;
        } else {
            $newUserInfo['created'] = false;
        }

        // Return with added infos to array
        return $newUserInfo;
    }

    /**
     * Adding new LDAP User to database
     * @param $userArray
     * @return User
     */
    protected function processDBUserRegister($userArray) {
        // Creating DB User object
        $dbUser = new User();
        $dbUser->setUsername($userArray['uid']);
        // Setting empty password
        $dbUser->setPassword('');
        $dbUser->setEmail($userArray['mail']);
        $dbUser->setDn($userArray['userDn']);
        $dbUser->setLastName($userArray['sn']);
        $dbUser->setFirstName($userArray['givenName']);
        $dbUser->setLanguage($userArray['preferredLanguage']);
        $dbUser->setEnabled(true);
        $dbUser->setRoles(['ROLE_USER']);

        // Adding it to database
        $this->em->persist($dbUser);
        $this->em->flush();
        return $dbUser;
    }

    /**
     * Processing demand DBupdate
     * @param Demand $demandToProcess
     * @param User $dbUser
     * @param array $gitLabUser
     * @param \SimpleXMLElement $redmineUser
     * @return Demand
     */
    protected function processDemandUpdate(Demand $demandToProcess, User $dbUser, array $gitLabUser, \SimpleXMLElement $redmineUser) {
        // Getting EM and object
        $succeedStatus = $this->em->getRepository('SpiritDevDBoxPortalBundle:Status')->findOneBy(array('canonicalName' => 'resolved'));

        // Updating demand
        $demandToProcess->setStatus($succeedStatus);
        // Applying new apllicant
        $demandToProcess->setApplicant($dbUser);
        // Updating demand date depending on status
        if ($succeedStatus->getCanonicalName() == "resolved") {
            $demandToProcess->setResolutionDate(new \DateTime());
        } else {
            $demandToProcess->setResolutionDate(null);
        }

        // Updating user
        $dbUser->setGitLabId($gitLabUser['id']);
        $dbUser->setRedmineId($redmineUser->{'id'});
        // TODO Update QA if available
        // TODO Update CI if available

        // Registering update
        $this->em->flush();

        return $demandToProcess;
    }

    /**
     * Function processing to VCS project creation
     * @param Project $project
     * @return mixed
     */
    protected function processVCSProjectCreation(Project $project) {

        $returnValues['slot_name'] = 'VCS';

        try {
            // Preparing GitLab team-mate
            $dBUserList = $this->em->getRepository('SpiritDevDBoxUserBundle:User')->getUsableUsers()->getQuery()->getResult(Query::HYDRATE_OBJECT);
            $gitLabUsersDiff = $this->gitlabApi->diffUsers($dBUserList, true);
            // Return values
            $retGitLabUsersDiff = array();
            for ($i = 0; $i < count($gitLabUsersDiff); $i++) {
                $retGitLabUsersDiff[] = $gitLabUsersDiff[$i]['username'];
            }
            $returnValues['data'][] = $this->setRetVal('VCS user diff', 'array', $retGitLabUsersDiff);

            // Creating GITLAB Project via API
            $gitlabProject = $this->gitlabApi->createProject($project);
            if ($gitlabProject != null) {
                $gitlabProjectData = $gitlabProject->getData();
                // Return values
                $retGitlabProjectData[] = array('key' => 'ID', 'data' => $gitlabProjectData['id']);
                $retGitlabProjectData[] = array('key' => 'SSH', 'data' => $gitlabProjectData['ssh_url_to_repo']);
                $retGitlabProjectData[] = array('key' => 'HTTP', 'data' => $gitlabProjectData['http_url_to_repo']);
                $retGitlabProjectData[] = array('key' => 'WEB', 'data' => $gitlabProjectData['web_url']);
                $retGitlabProjectData[] = array('key' => 'Namespace', 'data' => $gitlabProjectData['name_with_namespace']);
                $retGitlabProjectData[] = array('key' => 'Path with namespace', 'data' => $gitlabProjectData['path_with_namespace']);
                $returnValues['data'][] = $this->setRetVal('VCS project data', 'array_with_sub_key', $retGitlabProjectData);

                // Defining team members for this project
                $gitlabProjectMembers = $this->gitlabApi->setTeamMembers($gitlabProjectData["id"], $project->getTeamMembers());
                // Return values
                $retGitlabProjectMembers = array();
                for ($i = 0; $i < count($gitlabProjectMembers); $i++) {
                    $retGitlabProjectMembers[] = $gitlabProjectMembers[$i]['username'];
                }
                $returnValues['data'][] = $this->setRetVal('VCS project members', 'array', $retGitlabProjectMembers);

                if (array_key_exists("id", $gitlabProjectData)) {
                    // Set Local DB Gitlab related datas
                    $project->setGitLabProjectId($gitlabProjectData["id"]);
                    $project->setGitLabHttpUrlToRepo($gitlabProjectData["http_url_to_repo"]);
                    $project->setGitLabSshUrlToRepo($gitlabProjectData["ssh_url_to_repo"]);
                    $project->setGitLabWebUrl($gitlabProjectData["web_url"]);
                    $project->setGitLabNamespace($gitlabProjectData['path_with_namespace']);
                    // Updating datas
                    $this->em->flush();

                    // Commit push first documents
                    $fileArray = $this->VCSPushMandatoryFiles($project);
                    $returnValues['data'][] = $this->setRetVal('VCS files', 'array', $fileArray);

                    // Creating hook(s)
                    // Nb Commit updater hook
                    $nbCommitHookUrl = $this->router->generate('spirit_dev_dbox_portal_bundle_webhook_pjt_update_nbcommits', array('pjt_id' => $project->getId()), true);
                    $gitLabNbCommitHook = $this->gitlabApi->setProjectWebHook($project, $nbCommitHookUrl);
                    // Push hook to ci server
                    $jenkinsUrl = $this->defineJenkinsProjectUrl($project);
                    $gitLabPushHook = $this->gitlabApi->setProjectWebHook($project, $jenkinsUrl);
                    // Return values
                    $retGitLabPushHook[] = array('key' => 'ID', 'data' => $gitLabNbCommitHook['id']);
                    $retGitLabPushHook[] = array('key' => 'URL', 'data' => $gitLabNbCommitHook['url']);
                    $retGitLabPushHook[] = array('key' => 'ID', 'data' => $gitLabPushHook['id']);
                    $retGitLabPushHook[] = array('key' => 'URL', 'data' => $gitLabPushHook['url']);
                    $returnValues['data'][] = $this->setRetVal('VCS hooks', 'array_with_sub_key', $retGitLabPushHook);
                }
            }
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('VCS ERROR', 'string', $e->getMessage());
            $returnValues['data'][] = $this->setRetVal('VCS user diff', 'array', null);
            $returnValues['data'][] = $this->setRetVal('VCS project data', 'array', null);
            $returnValues['data'][] = $this->setRetVal('VCS project members', 'array', null);
            $returnValues['data'][] = $this->setRetVal('VCS hooks', 'array', null);
            $returnValues['data'][] = $this->setRetVal('VCS files', 'array', null);
        }
        return $returnValues;
    }

    /**
     * Function managing returning values
     * @param $valName
     * @param $type
     * @param $data
     * @return mixed
     */
    protected function setRetVal($valName, $type, $data) {

        $retVal['val_name'] = $valName;
        $retVal['type'] = $type;
        $retVal['data'] = $data;

        return $retVal;

    }

    /**
     * This function pushes first documents into project
     * FILES PUSHED
     *      Common
     *          LICENSE                                 To create
     *          README.md                               OK
     *          ToolsLeaflet-_-printable-_-.docx        OK
     *          ToolsLeaflet-_-printable-_-.pdf         OK
     *          ToolsLeaflet-_-readable-_-.docx         To create
     *          ToolsLeaflet-_-readable-_-.pdf          To create
     *
     *      Php Project
     *          build.xml                               OK
     *          Doxyfile                                OK
     *          phpdox.xml                              OK
     *
     * @param Project $project
     * @return array|null
     */
    protected function VCSPushMandatoryFiles(Project $project) {

        // TODO Verify documents to push

        $fileArray = null;

        // Push Common Files
        $finderCommon = new Finder();
        $finderCommon->files()->in($this->container->get('kernel')->getRootDir() . '/../src/SpiritDevDBoxPortalBundle/Resources/public/docs/common');
        foreach ($finderCommon as $file) {
            // Get file path
            $fileName = $file->getRelativePathname();
            // Get file content
            $fileContent = $file->getContents();
            // Push file to repo (encoding content to base64)
            $file = $this->gitlabApi->addFile($project, $fileName, base64_encode($fileContent), sprintf('Adds %s', $fileName), 'master', 'base64');
            $fileArray[] = $file['file_path'];
        }

        // Push PHP files
        if ($project->getLanguageType() == 'Php') {
            $finderPhp = new Finder();
            $finderPhp->files()->in($this->container->get('kernel')->getRootDir() . '/../src/SpiritDevDBoxPortalBundle/Resources/public/docs/php');
            foreach ($finderPhp as $file) {
                // Get file path
                $fileName = $file->getRelativePathname();
                // Get file content
                $fileContent = $file->getContents();
                // Push file to repo (encoding content to base64)
                $file = $this->gitlabApi->addFile($project, $fileName, base64_encode($fileContent), sprintf('Adds %s', $fileName), 'master', 'base64');
                $fileArray[] = $file['file_path'];
            }
        }

        return $fileArray;
    }

    /**
     * Processing to jenkins url definition
     * @param Project $project
     * @return string
     */
    protected function defineJenkinsProjectUrl(Project $project) {

        $proto = $this->container->getParameter('jenkins_api')['protocol'];
        $url = $this->container->getParameter('jenkins_api')['url'];
        $projectPrepend = "/project/";
        $projectPostpend = $this->getJobName($project);

        return $proto . $url . $projectPrepend . $projectPostpend;

    }

    /**
     * Define project job name
     * @param Project $project
     * @return string
     */
    protected function getJobName(Project $project) {
        return $project->getCanonicalName() . "_dev_pipeline";
    }

    /**
     * Function processing to PM project creation
     * @param Project $project
     * @return mixed
     */
    protected function processPMProjectCreation(Project $project) {

        $returnValues['slot_name'] = 'PM';

        try {
            // Create PM project
            $redmineProject = $this->redmineApi->createProject($project);
            // Do stuff if no error
            if ($redmineProject != null && !(isset($redmineProject->{'error'}->{0}) && $redmineProject->{'error'}->{0} != null)) {
                // Updating project entity
                $project->setRedmineProjectId((string)$redmineProject->{'id'});
                $project->setRedmineProjectIdentifier((string)$redmineProject->{'identifier'});
                $project->setRedmineWebUrl($this->getRedmineWebUrl($project));
                // Apply project modification
                $this->em->flush();

                // Setting retvals
                $returnValues['data'][] = $this->setRetVal('PM project ID', 'string', (string)$redmineProject->{'id'});
                $returnValues['data'][] = $this->setRetVal('PM project Identifier', 'string', (string)$redmineProject->{'identifier'});
                $returnValues['data'][] = $this->setRetVal('PM project web URL', 'string', $this->getRedmineWebUrl($project));

                // Defining team members
                $this->redmineApi->setProjectMemberships($project, $project->getTeamMembers(), $project->getOwner());
            } else {
                // in case of error
//                if (isset($redmineProject->{'error'}->{0}) && $redmineProject->{'error'}->{0} != null) {
                $returnValues['data'][] = $this->setRetVal('PM ERROR', 'string', (string)$redmineProject->{'error'}->{0});
//                }
            }
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('PM project ID', 'string', null);
            $returnValues['data'][] = $this->setRetVal('PM project Identifier', 'string', null);
            $returnValues['data'][] = $this->setRetVal('PM project web URL', 'string', null);
        }
        return $returnValues;
    }

    /**
     * Define redmine url
     * @param Project $project
     * @return string
     */
    protected function getRedmineWebUrl(Project $project) {
        return $this->container->getParameter('redmine_api')['protocol'] . $this->container->getParameter('redmine_api')['url'] . '/projects/' . $project->getRedmineProjectIdentifier();
    }

    /**
     * Function processing to CI project creation
     * @param Project $project
     * @return mixed
     */
    protected function processCIProjectCreation(Project $project) {

        $returnValues['slot_name'] = 'CI';

        try {
            // CI job name
            $ciJobName = $this->getJobName($project);
            // Create view
            $jenkins_view_creation = $this->jenkinsApi->createView($project->getName());
            $returnValues['data'][] = $this->setRetVal('CI View creation', 'bool', $jenkins_view_creation);
            // Copy default job
            $jenkins_job_copy = $this->jenkinsApi->copyJob($ciJobName);
            $returnValues['data'][] = $this->setRetVal('CI Job copy', 'bool', $jenkins_job_copy);
            // Add Project to view
            $jenkins_job_to_view = $this->jenkinsApi->addJobToView($project->getName(), $ciJobName);
            $returnValues['data'][] = $this->setRetVal('CI Job add to view', 'bool', $jenkins_job_to_view);
            // Add ci remote access
            $ci = new ContinuousIntegration();
            $ci->setAccessUrl(sprintf('%s/job/%s', $this->ciBaseUrl, $ciJobName));
            $ci->setProject($project);
            $ci->setCiName($ciJobName);
            $ci->setParametrized(true);
            $ci->setParameters(array(
                json_encode(array(
                    'name' => 'PIPELINE_VERSION_UPPER',
                    'type' => 'string',
                    'default' => '${BUILD_NUMBER}',
                    'description' => 'Define the pipeline version',
                    'hide' => true
                )),
                json_encode(array(
                    'name' => 'CUSTOM_WORKSPACE',
                    'type' => 'string',
                    'default' => '${WORKSPACE}',
                    'description' => 'Define the workspace place',
                    'hide' => true
                ))
            ));
            $ci->setActive(false);
            $this->em->persist($ci);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('CI ERROR', 'string', 'Unexpected error');
            $returnValues['data'][] = $this->setRetVal('CI View creation', 'bool', false);
            $returnValues['data'][] = $this->setRetVal('CI Job copy', 'bool', false);
            $returnValues['data'][] = $this->setRetVal('CI Job add to view', 'bool', false);
        }
        return $returnValues;
    }

    /**
     * Function processing to QA project creation
     * @param Project $project
     * @return mixed
     */
    protected function processQAProjectCreation(Project $project) {

        $returnValues['slot_name'] = 'QA';

        try {
            // Create project
            $sonarPjt = $this->sonarApi->createProject($project);

            if ($sonarPjt != null) {
                // Update local entity
                $project->setSonarProjectId($sonarPjt['id']);
                $project->setSonarProjectKey($sonarPjt['k']);
                $project->setSonarProjectUrl($this->getSonarWebUrl($project));
                // Apply project modification
                $this->em->flush();
                // Setting retvals
                $returnValues['data'][] = $this->setRetVal('QA Project id', 'string', $sonarPjt['id']);
                $returnValues['data'][] = $this->setRetVal('QA Project key', 'string', $sonarPjt['k']);

                // Add permissions
                $perms = array();
                foreach ($project->getTeamMembers() as $user) {
                    $perm = $this->sonarApi->addPermission($user, $project);
                    dump($perm);
                    $perms[] = $perm['user'];
                }
                $returnValues['data'][] = $this->setRetVal('QA project members', 'array', $perms);
            } else {
                $returnValues['data'][] = $this->setRetVal('QA ERROR', 'string', 'Unexpected error occured!');
            }

        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('QA ERROR', 'string', 'Unexpected error occured!');
            $returnValues['data'][] = $this->setRetVal('QA Project id', 'string', null);
            $returnValues['data'][] = $this->setRetVal('QA Project key', 'string', null);
            $returnValues['data'][] = $this->setRetVal('QA project members', 'array', null);
        }
        return $returnValues;
    }

    protected function getSonarWebUrl(Project $project) {
        $qa_api_url = $this->container->getParameter("sonar_api")["url"];
        $qa_base_url = substr($qa_api_url, 0, count($qa_api_url) - 5);
        $qa_view_url = $qa_base_url . "dashboard/index/" . $project->getSonarProjectId();
        return $qa_view_url;
    }

    /**
     * Function processing to Todos
     * @param Project $project
     * @return mixed
     */
    protected function processProjectCreationTodos(Project $project) {

        $returnValues['slot_name'] = 'Todos';

        try {
            // Define PM manual Todos
            if ($project->isPmManaged()) {
                $todo[] = $this->addTodo("<b>Redmine - Manage scm account - repo path: </b> /var/www/html/redmine/git_repositories/" . $project->getCanonicalName() . ".git");
                $todo[] = $this->addTodo("<b>Redmine - Manage scm account - repo url: </b> " . $project->getGitLabSshUrlToRepo());
            }

            // Define CI manual Todos
            if ($project->isCiDevManaged()) {
                $todo[] = $this->addTodo("<b>Jenkins - Redmine website: </b>" . $project->getName());
                $teamString = "";
                foreach ($project->getTeamMembers() as $user) {
                    $teamString .= " - " . $user->getUsername();
                }
                $todo[] = $this->addTodo("<b>Jenkins - Define security (follow \$user credentials): </b>" . $teamString);
                $todo[] = $this->addTodo("<b>Jenkins - Define gitlab repo name: </b>" . $project->getGitLabNamespace());
                $todo[] = $this->addTodo("<b>Jenkins - Define Display Name (Advanced options)</b>");
                $todo[] = $this->addTodo("<b>Jenkins - Configure Git SCM: </b>" . $project->getGitLabSshUrlToRepo());
                $todo[] = $this->addTodo("<b>Jenkins - Configure Git SCM: </b>Specify branch to use (dev)");
                $todo[] = $this->addTodo("<b>Jenkins - Manage SCM trigger: </b>Specify branch to use (dev)");
                $todo[] = $this->addTodo("<b>Jenkins - MultiJob Phase - QA: </b> Change the following values: SONAR_PROJECT_KEY, SONAR_PROJECT_NAME, SONAR_LANGUAGE, SONAR_SOURCES, SONAR_DOXYGEN_PATH");
            }

            // Define QA Manual todos
            if ($project->isQaDevManaged()) {
                $todo[] = $this->addTodo("<b>Sonar - Configure project Redmine: </b>Project Key: " . $project->getRedmineProjectId());
                $todo[] = $this->addTodo("<b>Sonar - Configure project Redmine: </b>Tracker: Qa Tracker");
            }

            // Push a separator between multiple todos
            $todo[] = $this->addTodo("<hr style='border-color: #9d9d9d;'>");

            $returnValues['data'][] = $this->setRetVal('Manual todos', 'todo', $todo);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('Manual todos', 'todo', null);
        }
        return $returnValues;
    }

    protected function addTodo($content) {
        $this->container->get('spirit_dev_dbox_portal_bundle.todos.manager')->addTodo($content);
        return $content;
    }

    /**
     * Function finalizing processes
     * @param Project $project
     * @param Demand $demand
     * @param bool $flashbag
     * @return mixed
     */
    protected function processProjectCreationFinalize(Project $project, Demand $demand, $flashbag = true) {

        $returnValues['slot_name'] = 'proc';

        try {
            // Finalizing process
//            if ($project->getGitLabProjectId() != null && $project->getRedmineProjectId() != null) {
            // Update demand
            $resolvedStatus = $this->em->getRepository('SpiritDevDBoxPortalBundle:Status')->findOneBy(array('canonicalName' => 'resolved'));
            $demand->setStatus($resolvedStatus);
            $project->setActive(true);

            // Apply project and demand modification
            $this->em->flush();

            // Send user mail + team mail
            $this->mailer->processProjectCreationSendMail($project);
            $returnValues['data'][] = $this->setRetVal('Mail sent', 'bool', true);

            if ($flashbag) {
                $this->session->getFlashBag()->set('success', 'flashbag.demand.processing_project.success');
            }
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('Mail sent', 'bool', false);
        }

        $returnValues['data'][] = $this->setRetVal('Demand status', 'string', $demand->getStatus()->getCanonicalName());

        return $returnValues;
    }

    /**
     * Process VCS deletion
     * @param Project $project
     * @return mixed
     */
    protected function processVCSProjectDeletion(Project $project) {

        $returnValues['slot_name'] = 'VCS';

        try {
            $pjt = $this->gitlabApi->deleteProject($project->getGitLabProjectId());
            $returnValues['data'][] = $this->setRetVal('VCS Deletion (incomming) id', 'string', $pjt);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('VCS Deletion (incomming) id', 'string', null);
        }

        return $returnValues;
    }

    /**
     * Process PM deletion
     * @param Project $project
     * @return mixed
     */
    protected function processPMProjectDeletion(Project $project) {

        $returnValues['slot_name'] = 'PM';

        try {
            $pjt = $this->redmineApi->deleteProject($project);
            $returnValues['data'][] = $this->setRetVal('PM project deletion', 'bool', !$pjt);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('PM project deletion', 'bool', false);
        }

        return $returnValues;
    }

    /**
     * Process CI deletion
     * @param Project $project
     * @return mixed
     */
    protected function processCIProjectDeletion(Project $project) {

        $returnValues['slot_name'] = 'CI';

        try {
            // CI job name
            $ciJobName = $this->getJobName($project);
            // Delete job
            $jenkins_job_deletion = $this->jenkinsApi->deleteJob($ciJobName);
            $returnValues['data'][] = $this->setRetVal('CI Job deletion', 'bool', $jenkins_job_deletion);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('CI Job deletion', 'bool', false);
        }

        try {
            // Delete view
            $jenkins_view_deletion = $this->jenkinsApi->deleteView($project->getName());
            $returnValues['data'][] = $this->setRetVal('CI View deletion', 'bool', $jenkins_view_deletion);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('CI View deletion', 'bool', false);
        }

        return $returnValues;
    }

    /**
     * Process QA deletion
     * @param Project $project
     * @return mixed
     */
    protected function processQAProjectDeletion(Project $project) {

        $returnValues['slot_name'] = 'QA';

        try {
            $sonarPjt = $this->sonarApi->deleteProject($project);
            $returnValues['data'][] = $this->setRetVal('QA Project deletion', 'string', $sonarPjt['err_msg']);
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('QA Project deletion', 'string', null);
        }

        return $returnValues;
    }

    /**
     * Process Entity arranges
     * @param Project $project
     * @param bool $remove
     * @return mixed
     */
    protected function processProjectDeletionFinalize(Project $project, $remove = true) {

        $returnValues['slot_name'] = 'proc';

        try {
            // Finalizing process
            if (!$remove) {
                // Unset Local DB VCS related datas
                $project->setGitLabProjectId(null);
                $project->setGitLabHttpUrlToRepo(null);
                $project->setGitLabSshUrlToRepo(null);
                $project->setGitLabWebUrl(null);
                $project->setGitLabNamespace(null);
                $project->setGitNbCommits(null);
                $project->setGitCommitLastUpdate(null);

                // Unset Local DB PM related datas
                $project->setRedmineProjectId(null);
                $project->setRedmineProjectIdentifier(null);
                $project->setRedmineWebUrl(null);

                // Unser local DB QA related datas
                $project->setSonarProjectId(null);
                $project->setSonarProjectKey(null);

                // Other
                $project->setActive(false);

                // Remove project CIs
                $cis = $this->em->getRepository('SpiritDevDBoxPortalBundle:ContinuousIntegration')->findBy(array('project' => $project));
                foreach ($cis as $ci) {
                    $this->em->remove($ci);
                }

            } else {
                // Removing project
                $this->em->remove($project);
            }

            // Updating datas
            $this->em->flush();
            $returnValues['data'][] = $this->setRetVal('Entity updated', 'bool', true);

            // TODO Send user mail + team mail ?
//            $this->mailer->processProjectCreationSendMail($project);
//            $returnValues['data'][] = $this->setRetVal('Mail sent', 'bool', true);

            $this->session->getFlashBag()->set('success', 'flashbag.demand.processing_deletion_project.success');
        } catch (\Exception $e) {
            $returnValues['data'][] = $this->setRetVal('Entity updated', 'bool', false);
//            $returnValues['data'][] = $this->setRetVal('Mail sent', 'bool', false);
            $this->session->getFlashBag()->set('error', 'flashbag.demand.processing_deletion_project.error');
        }

        return $returnValues;
    }
}