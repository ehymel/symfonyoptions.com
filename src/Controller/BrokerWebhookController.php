<?php

namespace App\Controller;

use App\DTO\OrderFillWebhookPayload;
use App\Service\WebhookOrderProcessorService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/webhooks', name: 'api_webhooks_')]
final class BrokerWebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookOrderProcessorService $processorService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(BROKER_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret
    ) {}

    #[Route('/order-fill', name: 'order_fill', methods: ['POST'])]
    public function handleOrderFill(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $signature = $request->headers->get('X-Broker-Signature') ?? $request->headers->get('X-Tradier-Signature');

        // 1. Verify Webhook Signature (Security Gatekeeper)
        if (!$this->isValidSignature($content, $signature)) {
            $this->logger->warning('Rejected unauthorized webhook submission.', [
                'ip' => $request->getClientIp(),
            ]);

            return $this->json(['error' => 'Invalid signature verification.'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // 2. Normalize raw payload into DTO (Example payload mapping)
            $payload = new OrderFillWebhookPayload(
                brokerOrderId: (string) ($data['order_id'] ?? $data['id'] ?? ''),
                eventStatus: strtoupper($data['status'] ?? 'UNKNOWN'),
                filledQuantity: (int) ($data['filled_quantity'] ?? $data['quantity'] ?? 0),
                avgFillPrice: number_format((float) ($data['avg_fill_price'] ?? $data['price'] ?? 0), 2, '.', ''),
                filledAt: new \DateTimeImmutable($data['timestamp'] ?? 'now'),
                rawPayload: $data
            );

            // 3. Process fill notification
            $processed = $this->processorService->processFillNotification($payload);

            return $this->json([
                'status' => 'acknowledged',
                'matched' => $processed,
            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Webhook processing error: %s', $e->getMessage()), [
                'exception' => $e,
            ]);

            // Always return HTTP 200/202 to broker once signature is verified to avoid infinite retries
            return $this->json(['status' => 'error_logged'], Response::HTTP_ACCEPTED);
        }
    }

    /**
     * Validates incoming HMAC-SHA256 signature against raw payload body.
     */
    private function isValidSignature(string $content, ?string $providedSignature): bool
    {
        if (empty($this->webhookSecret) || empty($providedSignature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $content, $this->webhookSecret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
