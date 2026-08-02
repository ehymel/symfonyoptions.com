<?php

namespace App\Service\Broker;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TradierApiClient implements BrokerApiClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%env(TRADIER_API_TOKEN)%')]
        private string $apiToken,
        #[Autowire('%env(TRADIER_ACCOUNT_ID)%')]
        private string $accountId,
        #[Autowire('%env(TRADIER_BASE_URL)%')]
        private string $baseUrl
    ) {}

    /**
     * Helper to make authenticated REST calls to Tradier with JSON headers.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = rtrim($this->baseUrl, '/') . 'TradierApiClient.php/' . ltrim($endpoint, '/');

        $defaultHeaders = [
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Accept' => 'application/json',
        ];

        $options['headers'] = array_merge($defaultHeaders, $options['headers'] ?? []);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $this->logger->error(sprintf('Tradier API returned error status %d: %s', $statusCode, $response->getContent(false)));
                throw new \RuntimeException(sprintf('Tradier API HTTP Error %d', $statusCode));
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Tradier API request failed (%s %s): %s', $method, $endpoint, $e->getMessage()));
            throw $e;
        }
    }

    public function getAccountBalances(): array
    {
        $endpoint = sprintf('accounts/%s/balances', $this->accountId);
        $data = $this->request('GET', $endpoint);

        $balances = $data['balances'] ?? [];

        return [
            'net_liquidation_value' => number_format((float) ($balances['total_equity'] ?? 0), 2, '.', ''),
            'cash_balance' => number_format((float) ($balances['cash']['cash_available'] ?? 0), 2, '.', ''),
            'option_buying_power' => number_format((float) ($balances['pdt']['option_buying_power'] ?? $balances['margin']['option_buying_power'] ?? 0), 2, '.', ''),
            'pending_orders_reserve' => number_format((float) ($balances['unallocated_funds'] ?? 0), 2, '.', ''),
        ];
    }

    public function getOptionChain(string $symbol, ?\DateTimeInterface $expiration = null): array
    {
        // 1. If no expiration provided, fetch options expiration dates first
        if (!$expiration) {
            $expirations = $this->getExpirations($symbol);
            if (empty($expirations)) {
                return [];
            }
            // Default to first available expiration
            $expirationDateStr = $expirations[0];
        } else {
            $expirationDateStr = $expiration->format('Y-m-d');
        }

        // 2. Query options chain with greeks included
        $endpoint = 'markets/options/chains';
        $data = $this->request('GET', $endpoint, [
            'query' => [
                'symbol' => strtoupper($symbol),
                'expiration' => $expirationDateStr,
                'greeks' => 'true',
            ],
        ]);

        $options = $data['options']['option'] ?? [];

        // Tradier returns a single associate array if only 1 option exists, normalize to list
        if (isset($options['symbol'])) {
            $options = [$options];
        }

        $normalizedChain = [];

        foreach ($options as $opt) {
            $normalizedChain[] = [
                'symbol' => strtoupper($symbol),
                'osi_symbol' => $opt['symbol'],
                'option_type' => strtoupper($opt['option_type']),
                'strike_price' => number_format((float) $opt['strike'], 2, '.', ''),
                'expiration_date' => $opt['expiration_date'],
                'bid' => number_format((float) ($opt['bid'] ?? 0), 2, '.', ''),
                'ask' => number_format((float) ($opt['ask'] ?? 0), 2, '.', ''),
                'last' => number_format((float) ($opt['last'] ?? 0), 2, '.', ''),
                'greeks' => [
                    'delta' => number_format((float) ($opt['greeks']['delta'] ?? 0), 4, '.', ''),
                    'gamma' => number_format((float) ($opt['greeks']['gamma'] ?? 0), 4, '.', ''),
                    'theta' => number_format((float) ($opt['greeks']['theta'] ?? 0), 4, '.', ''),
                    'vega' => number_format((float) ($opt['greeks']['vega'] ?? 0), 4, '.', ''),
                    'midiv' => number_format((float) ($opt['greeks']['midiv'] ?? 0), 4, '.', ''),
                ],
            ];
        }

        return $normalizedChain;
    }

    /**
     * Helper to fetch available expiration dates for an underlying equity.
     *
     * @return array<int, string>
     */
    public function getExpirations(string $symbol): array
    {
        $data = $this->request('GET', 'markets/options/expirations', [
            'query' => ['symbol' => strtoupper($symbol)],
        ]);

        $dates = $data['expirations']['date'] ?? [];

        return is_array($dates) ? $dates : [$dates];
    }

    public function placeOptionOrder(array $orderPayload): array
    {
        $endpoint = sprintf('accounts/%s/orders', $this->accountId);

        // Map payload parameters to Tradier's expected form-urlencoded body
        $formData = [
            'class' => 'option',
            'symbol' => strtoupper($orderPayload['symbol']),
            'option_symbol' => strtoupper($orderPayload['option_symbol']),
            'side' => $orderPayload['side'], // 'sell_to_open', 'buy_to_close', etc.
            'quantity' => (string) $orderPayload['quantity'],
            'type' => $orderPayload['type'] ?? 'market', // 'market', 'limit'
            'duration' => $orderPayload['duration'] ?? 'day',
        ];

        if (isset($orderPayload['price']) && $formData['type'] === 'limit') {
            $formData['price'] = $orderPayload['price'];
        }

        $data = $this->request('POST', $endpoint, [
            'body' => $formData,
        ]);

        $order = $data['order'] ?? [];

        if (!isset($order['id'])) {
            throw new \RuntimeException('Tradier API response did not contain an order ID.');
        }

        return [
            'id' => (string) $order['id'],
            'status' => strtolower($order['status'] ?? 'ok'),
            'raw' => $data,
        ];
    }

    public function cancelOrder(string $brokerOrderId): bool
    {
        $endpoint = sprintf('accounts/%s/orders/%s', $this->accountId, $brokerOrderId);
        $data = $this->request('DELETE', $endpoint);

        $status = strtolower($data['order']['status'] ?? '');

        return in_array($status, ['ok', 'canceled', 'pending']);
    }

    public function getOrderStatus(string $brokerOrderId): array
    {
        $endpoint = sprintf('accounts/%s/orders/%s', $this->accountId, $brokerOrderId);
        $data = $this->request('GET', $endpoint);

        $order = $data['order'] ?? [];

        return [
            'id' => (string) ($order['id'] ?? $brokerOrderId),
            'status' => strtoupper($order['status'] ?? 'UNKNOWN'), // 'filled', 'open', 'canceled', etc.
            'filled_quantity' => (int) ($order['exec_quantity'] ?? 0),
            'avg_fill_price' => isset($order['avg_fill_price']) ? number_format((float) $order['avg_fill_price'], 2, '.', '') : null,
        ];
    }

    public function getOptionQuote(string $osiSymbol): array
    {
        $data = $this->request('GET', 'markets/quotes', [
            'query' => [
                'symbols' => strtoupper($osiSymbol),
                'greeks' => 'true',
            ],
        ]);

        $quote = $data['quotes']['quote'] ?? [];

        return [
            'bid' => number_format((float) ($quote['bid'] ?? 0), 2, '.', ''),
            'ask' => number_format((float) ($quote['ask'] ?? 0), 2, '.', ''),
            'last' => number_format((float) ($quote['last'] ?? $quote['close'] ?? 0), 2, '.', ''),
            'delta' => number_format((float) ($quote['greeks']['delta'] ?? 0), 4, '.', ''),
            'gamma' => number_format((float) ($quote['greeks']['gamma'] ?? 0), 4, '.', ''),
            'theta' => number_format((float) ($quote['greeks']['theta'] ?? 0), 4, '.', ''),
            'vega' => number_format((float) ($quote['greeks']['vega'] ?? 0), 4, '.', ''),
            'iv' => number_format((float) ($quote['greeks']['midiv'] ?? 0), 4, '.', ''),
        ];
    }
}
