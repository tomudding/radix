<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Decision\MemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Service\Scim\ScimSyncDispatcher;
use App\Service\Scim\ScimSyncStatusProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The state of the SCIM sync and the pushes an administrator can ask for: the full sweep, one
 * account, or one body. The page only dispatches; the handlers do the work on the SCIM
 * transport, so a button returns before the source has answered.
 */
#[IsGranted(
    attribute: UserRoles::Admin->value,
    message: 'You are not allowed to manage the SCIM sync.',
)]
class ScimSyncController extends AbstractController
{
    public function __construct(
        private readonly ScimSyncDispatcher $dispatcher,
        private readonly ScimSyncStatusProvider $statusProvider,
        private readonly MemberRepository $memberRepository,
        private readonly OrganRepository $organRepository,
        private readonly TranslatorInterface $translator,
        private readonly bool $scimEnabled,
    ) {
    }

    #[Route(
        path: '/admin/scim-sync',
        name: 'admin/scim-sync',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render(
            'user/admin/scim-sync.html.twig',
            [
                'status' => $this->statusProvider->status(),
                'organs' => $this->organRepository->findAllForSyncSelect(),
            ],
        );
    }

    #[IsCsrfTokenValid(
        id: 'scim_sync_all',
        tokenKey: '_csrf_token',
    )]
    #[Route(
        path: '/admin/scim-sync/reconcile',
        name: 'admin/scim-sync/reconcile',
        methods: ['POST'],
    )]
    public function syncAll(): Response
    {
        if (!$this->scimEnabled) {
            return $this->refused();
        }

        $this->dispatcher->syncAll();

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The full sync has been dispatched to the worker queue.'),
        );

        return $this->redirectToRoute('admin/scim-sync');
    }

    #[IsCsrfTokenValid(
        id: 'scim_sync_user',
        tokenKey: '_csrf_token',
    )]
    #[Route(
        path: '/admin/scim-sync/user',
        name: 'admin/scim-sync/user',
        methods: ['POST'],
    )]
    public function syncUser(Request $request): Response
    {
        $lidnr = $request->request->getInt('lidnr');
        if (null === $this->memberRepository->find($lidnr)) {
            $this->addFlash(
                AlertTypes::Danger->value,
                $this->translator->trans(
                    'No member with membership number %lidnr%.',
                    ['%lidnr%' => $lidnr],
                ),
            );

            return $this->redirectToRoute('admin/scim-sync');
        }

        if (!$this->scimEnabled) {
            return $this->refused();
        }

        $this->dispatcher->memberChanged($lidnr);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans(
                'The account of member %lidnr% has been dispatched to the worker queue.',
                ['%lidnr%' => $lidnr],
            ),
        );

        return $this->redirectToRoute('admin/scim-sync');
    }

    #[IsCsrfTokenValid(
        id: 'scim_sync_body',
        tokenKey: '_csrf_token',
    )]
    #[Route(
        path: '/admin/scim-sync/body',
        name: 'admin/scim-sync/body',
        methods: ['POST'],
    )]
    public function syncBody(Request $request): Response
    {
        $foundationHash = (string) $request->request->get('body');
        if (
            '' === $foundationHash
            || null === $this->organRepository->findByFoundationHash($foundationHash)
        ) {
            $this->addFlash(
                AlertTypes::Danger->value,
                $this->translator->trans('That body does not exist.'),
            );

            return $this->redirectToRoute('admin/scim-sync');
        }

        if (!$this->scimEnabled) {
            return $this->refused();
        }

        $this->dispatcher->syncBody($foundationHash);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The body has been dispatched to the worker queue.'),
        );

        return $this->redirectToRoute('admin/scim-sync');
    }

    private function refused(): Response
    {
        $this->addFlash(
            AlertTypes::Danger->value,
            $this->translator->trans('SCIM sync is disabled; nothing was dispatched.'),
        );

        return $this->redirectToRoute('admin/scim-sync');
    }
}
