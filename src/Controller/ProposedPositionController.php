<?php

namespace App\Controller;

use App\Entity\Position;
use App\Message\SubmitOrderCommand;
use App\Repository\PositionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/trading/proposed', name: 'app_trading_proposed_')]
#[IsGranted('ROLE_TRADER')]
final class ProposedPositionController extends AbstractController
{
    public function __construct(
        private readonly PositionRepository $positionRepository,
        private readonly MessageBusInterface $bus,
        private readonly WorkflowInterface $optionPositionStateStateMachine
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $proposedPositions = $this->positionRepository->findBy(
            ['status' => Position::STATE_PROPOSED],
            ['createdAt' => 'DESC']
        );

        return $this->render('trading/proposed/index.html.twig', [
            'positions' => $proposedPositions,
        ]);
    }

    #[Route('/{id}/dispatch', name: 'dispatch', methods: ['POST'])]
    public function dispatchOrder(Position $position): JsonResponse
    {
        if (!$this->optionPositionStateStateMachine->can($position, 'submit_order')) {
            return $this->json([
                'success' => false,
                'message' => 'Position is not in a valid state to be submitted.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Dispatch async order execution command via Symfony Messenger
        $this->bus->dispatch(new SubmitOrderCommand($position->id));

        return $this->json([
            'success' => true,
            'message' => sprintf('Order dispatch queued for position #%d', $position->id),
        ]);
    }
}
