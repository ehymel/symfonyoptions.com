<?php

namespace App\Service\Broker;

interface BrokerApiClientInterface
{
    /**
     * Retrieve current account balances (cash, buying power, net liquidation value).
     *
     * @return array{
     *     net_liquidation_value: string,
     *     cash_balance: string,
     *     option_buying_power: string,
     *     pending_orders_reserve: string
     * }
     */
    public function getAccountBalances(): array;

    /**
     * Fetch options chain for an underlying ticker symbol.
     *
     * @param string $symbol e.g., 'SPY'
     * @param \DateTimeInterface|null $expiration Filter by explicit expiration date
     * @return array<int, array<string, mixed>> Raw option chain data
     */
    public function getOptionChain(string $symbol, ?\DateTimeInterface $expiration = null): array;

    /**
     * Place an options order with the broker.
     *
     * @param array{
     *     class: string,
     *     symbol: string,
     *     option_symbol: string,
     *     side: string,
     *     quantity: int,
     *     type: string,
     *     duration: string,
     *     price?: string
     * } $orderPayload
     * @return array{id: string, status: string, raw: array<string, mixed>}
     */
    public function placeOptionOrder(array $orderPayload): array;

    /**
     * Cancel an active order by broker order ID.
     */
    public function cancelOrder(string $brokerOrderId): bool;

    /**
     * Fetch the current status and execution details of a broker order.
     *
     * @return array{id: string, status: string, filled_quantity: int, avg_fill_price: ?string}
     */
    public function getOrderStatus(string $brokerOrderId): array;

    /**
     * Get real-time quotes and Greeks for a specific OSI option symbol.
     *
     * @param string $osiSymbol e.g., 'SPY260918P00500000'
     * @return array{
     *     bid: string,
     *     ask: string,
     *     last: string,
     *     delta: string,
     *     gamma: string,
     *     theta: string,
     *     vega: string,
     *     iv: string
     * }
     */
    public function getOptionQuote(string $osiSymbol): array;
}
