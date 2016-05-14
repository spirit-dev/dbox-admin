<?php

namespace SpiritDev\Bundle\DBoxAdminBundle\Controller;

use Doctrine\ORM\Query;
use SpiritDev\Bundle\DBoxPortalBundle\Entity\Demand;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

class AdminDemandController extends Controller {

    /**
     * @Route("/demand_dashboard", name="easyadmin_demand_dashboard")
     * @Template()
     */
    public function demandsAction() {

        $demands = $this->getDoctrine()->getRepository('PortalBundle:Demand')->findAll();

        return array(
            'tab_slot' => 'demands',
            'demands' => $demands
        );
    }

    /**
     * @Route("/demand/change_status/{demandId}", name="easyadmin_demand_change_status")
     * @param $demandId
     * @param Request $request
     * @return RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function demandChangeStatusAction($demandId, Request $request) {
        // Creating form
        $form = $this->createForm('demand_change_status');
        // Handling request with form
        $form->handleRequest($request);
        // If form full and valid
        if ($form->isValid()) {
            // Importing Handler and processing / Updating demand status
            $issue = $this->get('spirit_dev_dbox_portal_bundle.form.handler.demand_change_status')->process($demandId);
            // If well done -> redirect
            if ($issue) {
                $mailer = $this->get('spirit_dev_dbox_portal_bundle.mailer');
                $mailer->changeStatusSendMail($issue, $this->getCurrentUser());
                $this->get('session')->getFlashBag()->set('success', 'flashbag.demand.change_status.success');
            } else {
                $this->get('session')->getFlashBag()->set('error', 'flashbag.demand.change_status.error');
            }
            return $this->redirectToRoute('easyadmin', array(
                'view' => 'list',
                'entity' => 'Demand'
            ));
        }
        // Return form
        return $this->render('PortalBundle:Demand/Form:demandChangeStatus.html.twig', array(
            'form' => $form->createView(),
            'id' => $demandId
        ));
    }

    /**
     * @return mixed
     * @throws AccessDeniedException
     */
    protected function getCurrentUser() {

        $user = $this->container->get('security.token_storage')->getToken()->getUser();

        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        return $user;
    }

    /**
     * @Route("/demand/process/{demandId}", name="easyadmin_demand_process")
     * @param $demandId
     * @return mixed
     */
    public function demandProcessAction($demandId) {
        // Getting demand to treat
        $demandToProcess = $this->getDoctrine()->getRepository('PortalBundle:Demand')->findOneBy(array('id' => $demandId));

        // Calling processor service
        $resultValues = $this->get('spirit_dev_dbox_admin_bundle.admin.processor')->autoprocess($demandToProcess);

        // Return issue
        return $this->render('SpiritDevDBoxAdminBundle:AdminDemand:resolution.html.twig', array('resolution' => $resultValues));
    }
}
