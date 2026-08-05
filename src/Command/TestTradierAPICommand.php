<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:test_tradier_api', description: 'Test Tradier API Connection')]
class TestTradierAPICommand extends Command
{
    public function __construct(
        #[Autowire(env: 'TRADIER_API_KEY')] private string $tradierApiKey,
        #[Autowire(env: 'TRADIER_BASE_URL')] private string $tradierBaseUrl,
        private readonly HttpClientInterface $httpClient,
    )
    {
        parent::__construct();
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test Tradier API Connection');

        $response = $this->httpClient->request('GET', $this->tradierBaseUrl, [
            'headers' => [
                'Accept' => 'application/json',
                'authorization' => 'Bearer '.$this->tradierApiKey,
                ],
        ]);

        $io->success(sprintf('Connected! HTTP %d', $response->getStatusCode()));

        $io->section('Tradier Response');
        $io->writeln(json_encode(
            $response->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }
}
