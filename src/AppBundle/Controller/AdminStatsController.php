<?php

/**
 * This file contains the code that powers the AdminStats page of XTools.
 *
 * @version 1.5.1
 */

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Xtools\AdminStats;
use Xtools\AdminStatsRepository;
use Xtools\ProjectRepository;

/**
 * Class AdminStatsController
 *
 * @category AdminStats
 * @package  AppBundle\Controller
 * @author   XTools Team <xtools@lists.wikimedia.org>
 * @license  GPL 3.0
 */
class AdminStatsController extends XtoolsController
{
    /** @var Project The project being queried. */
    protected $project;

    /** @var AdminStats The admin stats instance that does all the work. */
    protected $adminStats;

    /**
     * Get the tool's shortname.
     * @return string
     * @codeCoverageIgnore
     */
    public function getToolShortname()
    {
        return 'adminstats';
    }

    /**
     * Every action in this controller (other than 'index') calls this first.
     * If a response is returned, the calling action is expected to return it.
     * @param string $project
     * @param string $start
     * @param string $end
     * @return AdminStats|RedirectResponse
     */
    public function setUpAdminStats($project, $start = null, $end = null)
    {
        // Load the database information for the tool.
        // $projectData will be a redirect if the project is invalid.
        $projectData = $this->validateProject($project);
        if ($projectData instanceof RedirectResponse) {
            return $projectData;
        }
        $this->project = $projectData;

        list($start, $end) = $this->getUTCFromDateParams($start, $end);

        $this->adminStats = new AdminStats($this->project, $start, $end);
        $adminStatsRepo = new AdminStatsRepository();
        $adminStatsRepo->setContainer($this->container);
        $this->adminStats->setRepository($adminStatsRepo);

        // For testing purposes.
        return $this->adminStats;
    }

    /**
     * Method for rendering the AdminStats Main Form.
     * This method redirects if valid parameters are found, making it a
     * valid form endpoint as well.
     * @Route("/adminstats",           name="adminstats")
     * @Route("/adminstats/",          name="AdminStatsSlash")
     * @Route("/adminstats/index.php", name="AdminStatsIndexPhp")
     * @return Route|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        // Redirect if we have a project. $results may also include start and/or end date.
        if (isset($this->params['project'])) {
            return $this->redirectToRoute('AdminStatsResult', $this->params);
        }

        // Otherwise render form.
        return $this->render('adminStats/index.html.twig', [
            'xtPage' => 'adminstats',
            'xtPageTitle' => 'tool-adminstats',
            'xtSubtitle' => 'tool-adminstats-desc',
        ]);
    }

    /**
     * Method for rendering the AdminStats Results
     * @Route(
     *   "/adminstats/{project}/{start}/{end}", name="AdminStatsResult",
     *   requirements={"start" = "|\d{4}-\d{2}-\d{2}", "end" = "|\d{4}-\d{2}-\d{2}"}
     * )
     * @param string $project Project to run the results against
     * @param string $start   Date to start on.  Must parse by strtotime.
     * @param string $end     Date to end on.  Must parse by strtotime.
     * @return Response|RedirectResponse
     * @codeCoverageIgnore
     */
    public function resultAction($project, $start = null, $end = null)
    {
        $ret = $this->setUpAdminStats($project, $start, $end);
        if ($ret instanceof RedirectResponse) {
            return $ret;
        }

        $this->adminStats->prepareStats();

        // Render the result!
        return $this->render('adminStats/result.html.twig', [
            'xtPage' => 'adminstats',
            'xtTitle' => $this->project->getDomain(),
            'project' => $this->project,
            'as' => $this->adminStats,
        ]);
    }

    /************************ API endpoints ************************/

    /**
     * Get users of the project that are capable of making 'admin actions',
     * keyed by user name with a list of the relevant user groups as the values.
     * @Route("/api/project/admins_groups/{project}", name="ProjectApiAdminsGroups")
     * @param string $project Project domain or database name.
     * @return Response
     * @codeCoverageIgnore
     */
    public function adminsGroupsApiAction($project)
    {
        $this->recordApiUsage('project/admins_groups');

        $ret = $this->setUpAdminStats($project);
        if ($ret instanceof RedirectResponse) {
            // FIXME: needs to render as JSON, fetching the message from the FlashBag.
            return $ret;
        }

        return new JsonResponse(
            $this->adminStats->getAdminsAndGroups(false),
            Response::HTTP_OK
        );
    }

    /**
     * Get users of the project that are capable of making 'admin actions',
     * along with various stats about which actions they took. Time period is limited
     * to one month.
     * @Route("/api/project/adminstats/{project}/{days}", name="ProjectApiAdminStats")
     * @param string $project Project domain or database name.
     * @param int $days Number of days from present to grab data for. Maximum 30.
     * @return Response
     * @codeCoverageIgnore
     */
    public function adminStatsApiAction($project, $days = 30)
    {
        $this->recordApiUsage('project/adminstats');

        // Maximum 30 days.
        $days = min((int) $days, 30);
        $start = date('Y-m-d', strtotime("-$days days"));
        $end = date('Y-m-d');

        $ret = $this->setUpAdminStats($project, $start, $end);
        if ($ret instanceof RedirectResponse) {
            // FIXME: needs to render as JSON, fetching the message from the FlashBag.
            return $ret;
        }

        $this->adminStats->prepareStats(false);

        $response = [
            'project' => $this->project->getDomain(),
            'start' => $this->adminStats->getStart(),
            'end' => $this->adminStats->getEnd(),
            'users' => $this->adminStats->getStats(false),
        ];

        return new JsonResponse(
            $response,
            Response::HTTP_OK
        );
    }
}
