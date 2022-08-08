<?php

namespace App\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelZero\Framework\Commands\Command;
use Throwable;

enum VenmoStatus: string
{
    case LIVE = 'LIVE';
    case DIE = 'DIE';
    case UNKNOWN = 'UNKNOWN';
    case ERROR = 'ERROR';
}

class DefaultChecker extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'default';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $done = 1;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $client = new Client([
                'headers' => [
                    'cookie' => file_get_contents(
                        getcwd().'/cookies.txt'
                    ),
                ],
            ]);

            collect(
                file(
                    getcwd().'/lists.txt'
                )
            )->map(
                fn ($item) => str($item)->replace(["\r", "\n"], '')
            )->chunk(10)->each(
                function ($lists) use (&$client) {
                    collect($lists)
                        ->map(
                            function ($list) use (&$client) {
                                return $client->postAsync('https://account.venmo.com/api/eligibility', [
                                    'json' => [
                                        'targetType' => 'phone',
                                        'targetId' => $list,
                                        'amountInCents' => 100,
                                        'action' => 'pay',
                                        'note' => 'jancok',
                                    ],
                                    'headers' => [
                                        'Accept' => '*/*',
                                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36',
                                        'content-type' => 'application/json',
                                        'csrf-token' => 'hT3qTevD--DIY_8CRDOxgo9t9KUVmAQslczQ',
                                        'xsrf-token' => 'hT3qTevD--DIY_8CRDOxgo9t9KUVmAQslczQ',
                                    ],
                                ])
                                    ->then(
                                        function (Response $response) use ($list) {
                                            $responseJson = optional(
                                                json_decode(
                                                    $response->getBody()->getContents()
                                                )
                                            );

                                            if ($responseJson->ineligibleReason === '111') {
                                                $this->writeResult($list, VenmoStatus::DIE);
                                            } elseif ($responseJson->ineligibleReason === '105') {
                                                $this->writeResult($list, VenmoStatus::LIVE);
                                            } else {
                                                $this->writeResult($list, VenmoStatus::UNKNOWN);
                                            }
                                        },
                                        function (Throwable $th) use ($list) {
                                            $this->writeResult(
                                                $list,
                                                VenmoStatus::ERROR,
                                                $th->getMessage()
                                            );
                                        }
                                    );
                            }
                        )
                        ->each(
                            fn ($promise) => $promise->wait()
                        );
                }
            );
        } catch (\Throwable $th) {
            $this->newLine();
            $this->error($th->getMessage());
            $this->newLine();
        }
    }

    protected function writeResult(string $list, VenmoStatus $status, ?string $message = '')
    {
        $resultPath = getcwd().'/result';

        if (! is_dir($resultPath)) {
            @mkdir($resultPath);
        }

        file_put_contents(
            $resultPath.'/'.$status->value.'.txt',
            $list.PHP_EOL,
            FILE_APPEND
        );

        if ($status != VenmoStatus::UNKNOWN || $status != VenmoStatus::ERROR) {
            $this->info("   {$this->done}     {$list}     {$status->value}");
        } else {
            $this->info("   {$this->done}     {$list}     {$message}     {$status->value}");
        }

        $this->done++;
    }
}
